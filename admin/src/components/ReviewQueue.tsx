import { useState, useEffect, useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { api, ProductSummary, GenerationResult, EvalRow } from '../api';
import DiffView from './DiffView';

/**
 * Review Queue: pick products (single or bulk), generate AI copy, and
 * review each draft with a diff against the existing description before
 * accepting, editing, or rejecting it. This is the human-in-the-loop
 * centerpiece of the plugin.
 */
export default function ReviewQueue() {
	const [ search, setSearch ] = useState( '' );
	const [ searchResults, setSearchResults ] = useState< ProductSummary[] >( [] );
	const [ selectedIds, setSelectedIds ] = useState< number[] >( [] );
	const [ isGenerating, setIsGenerating ] = useState( false );
	const [ queue, setQueue ] = useState< EvalRow[] >( [] );
	const [ activeGeneration, setActiveGeneration ] = useState< GenerationResult | null >( null );
	const [ error, setError ] = useState< string | null >( null );
	const [ bulkEstimate, setBulkEstimate ] = useState< {
		product_count: number;
		est_input_tokens: number;
		est_output_tokens: number;
		est_time_seconds: number;
	} | null >( null );

	// Pre-fill selection from ?product_ids= query param set by the Products
	// list bulk action / row action link.
	useEffect( () => {
		const params = new URLSearchParams( window.location.search );
		const ids = params.get( 'product_ids' );
		if ( ids ) {
			setSelectedIds( ids.split( ',' ).map( Number ).filter( Boolean ) );
		}
		loadPendingQueue();
	}, [] );

	const loadPendingQueue = useCallback( () => {
		api.listEvals( 'pending_review' ).then( setQueue ).catch( () => setError( 'Failed to load review queue.' ) );
	}, [] );

	useEffect( () => {
		if ( ! search ) {
			setSearchResults( [] );
			return;
		}
		const timeout = setTimeout( () => {
			api.searchProducts( search ).then( setSearchResults );
		}, 300 );
		return () => clearTimeout( timeout );
	}, [ search ] );

	useEffect( () => {
		if ( selectedIds.length > 1 ) {
			api.bulkEstimate( selectedIds ).then( setBulkEstimate );
		} else {
			setBulkEstimate( null );
		}
	}, [ selectedIds ] );

	function toggleSelected( id: number ) {
		setSelectedIds( ( prev ) => ( prev.includes( id ) ? prev.filter( ( x ) => x !== id ) : [ ...prev, id ] ) );
	}

	async function handleGenerate() {
		setError( null );
		setIsGenerating( true );
		try {
			if ( selectedIds.length === 1 ) {
				const result = await api.generate( selectedIds[ 0 ] );
				setActiveGeneration( result );
			} else if ( selectedIds.length > 1 ) {
				await api.bulkGenerate( selectedIds );
				loadPendingQueue();
				setSelectedIds( [] );
			}
		} catch ( e: any ) {
			setError( e?.message || __( 'Generation failed.', 'woocopy-ai' ) );
		} finally {
			setIsGenerating( false );
		}
	}

	async function handleReview(
		evalId: number,
		decision: 'accepted' | 'edited' | 'rejected',
		shortDesc: string,
		longDesc: string
	) {
		try {
			await api.reviewEval( evalId, decision, shortDesc, longDesc, decision !== 'rejected' );
			setActiveGeneration( null );
			loadPendingQueue();
		} catch ( e: any ) {
			setError( e?.message || __( 'Failed to save review.', 'woocopy-ai' ) );
		}
	}

	return (
		<div className="woocopy-review-queue">
			{ error && <div className="woocopy-error">{ error }</div> }

			<div className="woocopy-card">
				<h2>{ __( 'Generate copy', 'woocopy-ai' ) }</h2>
				<input
					type="text"
					className="woocopy-input"
					placeholder={ __( 'Search products by name or SKU…', 'woocopy-ai' ) }
					value={ search }
					onChange={ ( e ) => setSearch( e.target.value ) }
				/>

				{ searchResults.length > 0 && (
					<ul className="woocopy-product-list">
						{ searchResults.map( ( product ) => (
							<li key={ product.id }>
								<label>
									<input
										type="checkbox"
										checked={ selectedIds.includes( product.id ) }
										onChange={ () => toggleSelected( product.id ) }
									/>
									{ product.name }
									{ product.sku && <span className="woocopy-muted"> ({ product.sku })</span> }
								</label>
							</li>
						) ) }
					</ul>
				) }

				{ selectedIds.length > 0 && (
					<p>
						{ selectedIds.length === 1
							? __( '1 product selected.', 'woocopy-ai' )
							: `${ selectedIds.length } ${ __( 'products selected.', 'woocopy-ai' ) }` }
					</p>
				) }

				{ bulkEstimate && (
					<div className="woocopy-estimate">
						{ __( 'Estimated cost:', 'woocopy-ai' ) } ~{ bulkEstimate.est_input_tokens + bulkEstimate.est_output_tokens }{ ' ' }
						{ __( 'tokens across', 'woocopy-ai' ) } { bulkEstimate.product_count }{ ' ' }
						{ __( 'products (~', 'woocopy-ai' ) }
						{ bulkEstimate.est_time_seconds }s)
					</div>
				) }

				<button
					className="button button-primary"
					disabled={ selectedIds.length === 0 || isGenerating || ! window.woocopyAI.hasApiKey }
					onClick={ handleGenerate }
				>
					{ isGenerating ? __( 'Generating…', 'woocopy-ai' ) : __( 'Generate AI copy', 'woocopy-ai' ) }
				</button>
			</div>

			{ activeGeneration && (
				<div className="woocopy-card">
					<h2>{ __( 'Review draft', 'woocopy-ai' ) }</h2>
					<DiffView generation={ activeGeneration } onReview={ handleReview } />
				</div>
			) }

			<div className="woocopy-card">
				<h2>
					{ __( 'Pending review', 'woocopy-ai' ) } ({ queue.length })
				</h2>
				{ queue.length === 0 && <p className="woocopy-muted">{ __( 'Nothing waiting on review.', 'woocopy-ai' ) }</p> }
				<ul className="woocopy-queue-list">
					{ queue.map( ( row ) => (
						<li key={ row.id }>
							<button
								className="button-link"
								onClick={ () =>
									setActiveGeneration( {
										eval_id: row.id,
										product_id: row.product_id,
										short_description: row.generated_short_description,
										long_description: row.generated_long_description,
										existing_short: '',
										existing_long: '',
									} )
								}
							>
								{ row.product_name }
							</button>
							<span className="woocopy-muted"> — { row.prompt_version } — { row.created_at }</span>
						</li>
					) ) }
				</ul>
			</div>
		</div>
	);
}
