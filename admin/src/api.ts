import apiFetch from '@wordpress/api-fetch';

declare global {
	interface Window {
		woocopyAI: {
			restUrl: string;
			nonce: string;
			adminUrl: string;
			hasApiKey: boolean;
		};
	}
}

// Wire up REST root + nonce once, at module load, so every apiFetch call
// throughout the app is authenticated without repeating boilerplate.
apiFetch.use( apiFetch.createRootURLMiddleware( window.woocopyAI.restUrl ) );
apiFetch.use( apiFetch.createNonceMiddleware( window.woocopyAI.nonce ) );

export interface ProductSummary {
	id: number;
	name: string;
	sku: string;
	image: string | null;
}

export interface GenerationResult {
	eval_id: number;
	product_id: number;
	short_description: string;
	long_description: string;
	existing_short: string;
	existing_long: string;
}

export interface RubricScores {
	keyword_coverage: number | null;
	keywords_checked: string[];
	short_word_count: number;
	long_word_count: number;
	length_ok: boolean;
	unsupported_claims: string[];
	scored_at: string;
}

export interface EvalRow {
	id: number;
	product_id: number;
	product_name: string;
	prompt_version: string;
	model: string;
	generated_short_description: string;
	generated_long_description: string;
	status: 'draft' | 'pending_review' | 'accepted' | 'edited' | 'rejected';
	rubric_scores: RubricScores | null;
	edit_distance_short: number | null;
	edit_distance_long: number | null;
	created_at: string;
	reviewed_at: string | null;
}

export interface DashboardStats {
	total: number;
	by_status: Record< string, { status: string; count: number } >;
	by_prompt_version: Array< {
		prompt_version: string;
		total: number;
		accepted: number;
		edited: number;
		rejected: number;
		avg_edit_distance_long: number | null;
	} >;
	recent: Array< {
		id: number;
		product_id: number;
		prompt_version: string;
		status: string;
		rubric_scores: string;
		created_at: string;
	} >;
}

export const api = {
	searchProducts: ( search: string ): Promise< ProductSummary[] > =>
		apiFetch( { path: `/woocopy-ai/v1/products/search?search=${ encodeURIComponent( search ) }` } ),

	generate: ( productId: number ): Promise< GenerationResult > =>
		apiFetch( {
			path: '/woocopy-ai/v1/generate',
			method: 'POST',
			data: { product_id: productId },
		} ),

	bulkEstimate: ( productIds: number[] ) =>
		apiFetch< { product_count: number; est_input_tokens: number; est_output_tokens: number; est_time_seconds: number } >( {
			path: '/woocopy-ai/v1/generate/bulk-estimate',
			method: 'POST',
			data: { product_ids: productIds },
		} ),

	bulkGenerate: ( productIds: number[] ) =>
		apiFetch< { results: Array< GenerationResult & { error?: string } > } >( {
			path: '/woocopy-ai/v1/generate/bulk',
			method: 'POST',
			data: { product_ids: productIds },
		} ),

	listEvals: ( status?: string ): Promise< EvalRow[] > =>
		apiFetch( {
			path: `/woocopy-ai/v1/evals${ status ? `?status=${ status }` : '' }`,
		} ),

	reviewEval: (
		evalId: number,
		decision: 'accepted' | 'edited' | 'rejected',
		shortDescription: string,
		longDescription: string,
		applyToProduct: boolean
	) =>
		apiFetch( {
			path: `/woocopy-ai/v1/evals/${ evalId }/review`,
			method: 'POST',
			data: {
				decision,
				short_description: shortDescription,
				long_description: longDescription,
				apply_to_product: applyToProduct,
			},
		} ),

	dashboard: (): Promise< DashboardStats > => apiFetch( { path: '/woocopy-ai/v1/evals/dashboard' } ),

	getVoiceProfile: () =>
		apiFetch< { examples: string[]; profile: string; updated_at: string } >( {
			path: '/woocopy-ai/v1/voice-profile',
		} ),

	updateVoiceProfile: ( examples: string[] ) =>
		apiFetch< { examples: string[]; profile: string; updated_at: string } >( {
			path: '/woocopy-ai/v1/voice-profile',
			method: 'POST',
			data: { examples },
		} ),

	getSettings: () =>
		apiFetch< {
			api_key_masked: string;
			has_api_key: boolean;
			model: string;
			prompt_version: string;
			auto_publish: boolean;
		} >( { path: '/woocopy-ai/v1/settings' } ),

	updateSettings: ( settings: {
		api_key?: string;
		model?: string;
		prompt_version?: string;
		auto_publish?: boolean;
	} ) =>
		apiFetch( {
			path: '/woocopy-ai/v1/settings',
			method: 'POST',
			data: settings,
		} ),
};
