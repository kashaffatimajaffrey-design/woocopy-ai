import { useState, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { api } from '../api';

/**
 * Voice Profile: the merchant pastes 2-3 example descriptions they like,
 * Claude extracts a reusable style profile, and that profile gets injected
 * into every future generation prompt for store-wide consistency.
 */
export default function VoiceProfile() {
	const [ examples, setExamples ] = useState< string[] >( [ '', '', '' ] );
	const [ profile, setProfile ] = useState( '' );
	const [ updatedAt, setUpdatedAt ] = useState( '' );
	const [ isSaving, setIsSaving ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );

	useEffect( () => {
		api.getVoiceProfile().then( ( data ) => {
			setProfile( data.profile || '' );
			setUpdatedAt( data.updated_at || '' );
			if ( data.examples && data.examples.length ) {
				const padded = [ ...data.examples ];
				while ( padded.length < 3 ) {
					padded.push( '' );
				}
				setExamples( padded );
			}
		} );
	}, [] );

	async function handleSave() {
		setError( null );
		setIsSaving( true );
		try {
			const nonEmpty = examples.filter( ( e ) => e.trim().length > 0 );
			const result = await api.updateVoiceProfile( nonEmpty );
			setProfile( result.profile );
			setUpdatedAt( result.updated_at );
		} catch ( e: any ) {
			setError( e?.message || __( 'Failed to build voice profile.', 'woocopy-ai' ) );
		} finally {
			setIsSaving( false );
		}
	}

	function updateExample( idx: number, value: string ) {
		setExamples( ( prev ) => prev.map( ( e, i ) => ( i === idx ? value : e ) ) );
	}

	return (
		<div className="woocopy-voice-profile">
			<div className="woocopy-card">
				<h2>{ __( 'Example descriptions', 'woocopy-ai' ) }</h2>
				<p className="woocopy-muted">
					{ __(
						'Paste 2-3 product descriptions that already sound like your brand. Claude will extract tone, sentence length, and recurring patterns, then apply that voice to every future generation.',
						'woocopy-ai'
					) }
				</p>

				{ examples.map( ( example, idx ) => (
					<div key={ idx } className="woocopy-voice-example">
						<label>
							{ __( 'Example', 'woocopy-ai' ) } { idx + 1 }
						</label>
						<textarea
							className="woocopy-textarea"
							rows={ 3 }
							value={ example }
							onChange={ ( e ) => updateExample( idx, e.target.value ) }
						/>
					</div>
				) ) }

				{ error && <div className="woocopy-error">{ error }</div> }

				<button className="button button-primary" disabled={ isSaving } onClick={ handleSave }>
					{ isSaving ? __( 'Extracting voice…', 'woocopy-ai' ) : __( 'Build voice profile', 'woocopy-ai' ) }
				</button>
			</div>

			{ profile && (
				<div className="woocopy-card">
					<h2>{ __( 'Current voice profile', 'woocopy-ai' ) }</h2>
					{ updatedAt && (
						<p className="woocopy-muted">
							{ __( 'Last updated:', 'woocopy-ai' ) } { updatedAt }
						</p>
					) }
					<pre className="woocopy-voice-profile__text">{ profile }</pre>
				</div>
			) }
		</div>
	);
}
