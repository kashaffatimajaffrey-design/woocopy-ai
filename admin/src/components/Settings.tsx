import { useState, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { api } from '../api';

export default function Settings() {
	const [ apiKey, setApiKey ] = useState( '' );
	const [ maskedKey, setMaskedKey ] = useState( '' );
	const [ model, setModel ] = useState( 'claude-sonnet-4-6' );
	const [ promptVersion, setPromptVersion ] = useState( 'v1' );
	const [ autoPublish, setAutoPublish ] = useState( false );
	const [ isSaving, setIsSaving ] = useState( false );
	const [ saved, setSaved ] = useState( false );

	useEffect( () => {
		api.getSettings().then( ( data ) => {
			setMaskedKey( data.api_key_masked );
			setModel( data.model );
			setPromptVersion( data.prompt_version );
			setAutoPublish( data.auto_publish );
		} );
	}, [] );

	async function handleSave() {
		setIsSaving( true );
		setSaved( false );
		try {
			const payload: Record< string, unknown > = {
				model,
				prompt_version: promptVersion,
				auto_publish: autoPublish,
			};
			if ( apiKey.trim() ) {
				payload.api_key = apiKey.trim();
			}
			await api.updateSettings( payload );
			setApiKey( '' );
			const refreshed = await api.getSettings();
			setMaskedKey( refreshed.api_key_masked );
			setSaved( true );
		} finally {
			setIsSaving( false );
		}
	}

	return (
		<div className="woocopy-settings">
			<div className="woocopy-card">
				<h2>{ __( 'Anthropic API key', 'woocopy-ai' ) }</h2>
				{ maskedKey && (
					<p className="woocopy-muted">
						{ __( 'Current key:', 'woocopy-ai' ) } { maskedKey }
					</p>
				) }
				<input
					type="password"
					className="woocopy-input"
					placeholder={ __( 'sk-ant-…', 'woocopy-ai' ) }
					value={ apiKey }
					onChange={ ( e ) => setApiKey( e.target.value ) }
					autoComplete="off"
				/>
				<p className="woocopy-muted">
					{ __( 'Stored in the WordPress options table. Leave blank to keep the current key.', 'woocopy-ai' ) }
				</p>
			</div>

			<div className="woocopy-card">
				<h2>{ __( 'Model', 'woocopy-ai' ) }</h2>
				<select className="woocopy-input" value={ model } onChange={ ( e ) => setModel( e.target.value ) }>
					<option value="claude-sonnet-4-6">Claude Sonnet 4.6</option>
					<option value="claude-opus-4-8">Claude Opus 4.8</option>
					<option value="claude-haiku-4-5-20251001">Claude Haiku 4.5</option>
				</select>
			</div>

			<div className="woocopy-card">
				<h2>{ __( 'Prompt version', 'woocopy-ai' ) }</h2>
				<input
					type="text"
					className="woocopy-input"
					value={ promptVersion }
					onChange={ ( e ) => setPromptVersion( e.target.value ) }
				/>
				<p className="woocopy-muted">
					{ __(
						'Bump this whenever you materially change the system prompt so the eval dashboard can compare versions.',
						'woocopy-ai'
					) }
				</p>
			</div>

			<div className="woocopy-card">
				<h2>{ __( 'Publishing', 'woocopy-ai' ) }</h2>
				<label>
					<input type="checkbox" checked={ autoPublish } onChange={ () => setAutoPublish( ! autoPublish ) } />
					{ __( 'Skip review and publish generations automatically (not recommended)', 'woocopy-ai' ) }
				</label>
			</div>

			<button className="button button-primary" disabled={ isSaving } onClick={ handleSave }>
				{ isSaving ? __( 'Saving…', 'woocopy-ai' ) : __( 'Save settings', 'woocopy-ai' ) }
			</button>
			{ saved && <span className="woocopy-saved-badge">{ __( 'Saved.', 'woocopy-ai' ) }</span> }
		</div>
	);
}
