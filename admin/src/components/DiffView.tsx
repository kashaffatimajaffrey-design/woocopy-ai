import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { GenerationResult } from '../api';

interface Props {
	generation: GenerationResult;
	onReview: (
		evalId: number,
		decision: 'accepted' | 'edited' | 'rejected',
		shortDesc: string,
		longDesc: string
	) => void;
}

/**
 * Word-level diff — highlights additions/removals between the existing
 * description and the AI draft so a reviewer can scan it in seconds
 * rather than re-reading the whole paragraph.
 */
function wordDiff( oldText: string, newText: string ) {
	const oldWords = oldText.split( /(\s+)/ );
	const newWords = newText.split( /(\s+)/ );

	// Simple LCS-based diff, adequate for short product-copy paragraphs.
	const m = oldWords.length;
	const n = newWords.length;
	const dp: number[][] = Array.from( { length: m + 1 }, () => new Array( n + 1 ).fill( 0 ) );

	for ( let i = m - 1; i >= 0; i-- ) {
		for ( let j = n - 1; j >= 0; j-- ) {
			dp[ i ][ j ] =
				oldWords[ i ] === newWords[ j ] ? dp[ i + 1 ][ j + 1 ] + 1 : Math.max( dp[ i + 1 ][ j ], dp[ i ][ j + 1 ] );
		}
	}

	const segments: Array< { type: 'same' | 'add' | 'remove'; text: string } > = [];
	let i = 0;
	let j = 0;
	while ( i < m && j < n ) {
		if ( oldWords[ i ] === newWords[ j ] ) {
			segments.push( { type: 'same', text: newWords[ j ] } );
			i++;
			j++;
		} else if ( dp[ i + 1 ][ j ] >= dp[ i ][ j + 1 ] ) {
			segments.push( { type: 'remove', text: oldWords[ i ] } );
			i++;
		} else {
			segments.push( { type: 'add', text: newWords[ j ] } );
			j++;
		}
	}
	while ( i < m ) {
		segments.push( { type: 'remove', text: oldWords[ i ] } );
		i++;
	}
	while ( j < n ) {
		segments.push( { type: 'add', text: newWords[ j ] } );
		j++;
	}

	return segments;
}

export default function DiffView( { generation, onReview }: Props ) {
	const [ shortDesc, setShortDesc ] = useState( generation.short_description );
	const [ longDesc, setLongDesc ] = useState( generation.long_description );
	const [ showDiff, setShowDiff ] = useState( true );
	const [ editing, setEditing ] = useState( false );

	const longDiff = wordDiff( generation.existing_long || '', generation.long_description );
	const shortDiff = wordDiff( generation.existing_short || '', generation.short_description );

	function handleDecision( decision: 'accepted' | 'edited' | 'rejected' ) {
		const finalDecision =
			decision === 'accepted' &&
			( shortDesc !== generation.short_description || longDesc !== generation.long_description )
				? 'edited'
				: decision;
		onReview( generation.eval_id, finalDecision, shortDesc, longDesc );
	}

	return (
		<div className="woocopy-diff-view">
			<div className="woocopy-diff-view__toolbar">
				<label>
					<input type="checkbox" checked={ showDiff } onChange={ () => setShowDiff( ! showDiff ) } />
					{ __( 'Show diff vs. existing description', 'woocopy-ai' ) }
				</label>
				<label>
					<input type="checkbox" checked={ editing } onChange={ () => setEditing( ! editing ) } />
					{ __( 'Edit before deciding', 'woocopy-ai' ) }
				</label>
			</div>

			<h3>{ __( 'Short description', 'woocopy-ai' ) }</h3>
			{ showDiff && ! editing ? (
				<p className="woocopy-diff-text">
					{ shortDiff.map( ( seg, idx ) => (
						<span key={ idx } className={ `woocopy-diff-${ seg.type }` }>
							{ seg.text }
						</span>
					) ) }
				</p>
			) : editing ? (
				<textarea
					className="woocopy-textarea"
					rows={ 2 }
					value={ shortDesc }
					onChange={ ( e ) => setShortDesc( e.target.value ) }
				/>
			) : (
				<p>{ shortDesc }</p>
			) }

			<h3>{ __( 'Long description', 'woocopy-ai' ) }</h3>
			{ showDiff && ! editing ? (
				<p className="woocopy-diff-text">
					{ longDiff.map( ( seg, idx ) => (
						<span key={ idx } className={ `woocopy-diff-${ seg.type }` }>
							{ seg.text }
						</span>
					) ) }
				</p>
			) : editing ? (
				<textarea
					className="woocopy-textarea"
					rows={ 8 }
					value={ longDesc }
					onChange={ ( e ) => setLongDesc( e.target.value ) }
				/>
			) : (
				<p>{ longDesc }</p>
			) }

			<div className="woocopy-diff-view__actions">
				<button className="button button-primary" onClick={ () => handleDecision( 'accepted' ) }>
					{ __( 'Accept & publish', 'woocopy-ai' ) }
				</button>
				<button className="button" onClick={ () => handleDecision( 'edited' ) }>
					{ __( 'Save edits & publish', 'woocopy-ai' ) }
				</button>
				<button className="button button-link-delete" onClick={ () => handleDecision( 'rejected' ) }>
					{ __( 'Reject', 'woocopy-ai' ) }
				</button>
			</div>
			<p className="woocopy-muted woocopy-diff-legend">
				<span className="woocopy-diff-add">{ __( 'added', 'woocopy-ai' ) }</span> ·{ ' ' }
				<span className="woocopy-diff-remove">{ __( 'removed', 'woocopy-ai' ) }</span>
			</p>
		</div>
	);
}
