<?php
/**
 * REST API routes consumed by the React admin app.
 *
 * @package WooCopy_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WooCopy_REST_Controller
 */
class WooCopy_REST_Controller {

	const NAMESPACE_ = 'woocopy-ai/v1';

	/**
	 * Register all REST routes.
	 */
	public function register_routes() {

		register_rest_route(
			self::NAMESPACE_,
			'/products/search',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'search_products' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'search' => array( 'type' => 'string' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_,
			'/generate',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'generate' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'product_id' => array(
						'required' => true,
						'type'     => 'integer',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_,
			'/generate/bulk-estimate',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'bulk_estimate' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_,
			'/generate/bulk',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'bulk_generate' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_,
			'/evals',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_evals' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_,
			'/evals/(?P<id>\d+)/review',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'review_eval' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'decision' => array(
						'required' => true,
						'type'     => 'string',
						'enum'     => array( 'accepted', 'edited', 'rejected' ),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_,
			'/evals/dashboard',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'dashboard' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_,
			'/voice-profile',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_voice_profile' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'update_voice_profile' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_,
			'/settings',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => array( $this, 'settings_permissions_check' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'update_settings' ),
					'permission_callback' => array( $this, 'settings_permissions_check' ),
				),
			)
		);
	}

	/**
	 * Standard capability check for most routes.
	 *
	 * @return bool
	 */
	public function permissions_check() {
		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Stricter check for the settings route (API key handling).
	 *
	 * @return bool
	 */
	public function settings_permissions_check() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Search products for the "pick a product" UI (used outside bulk flows).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function search_products( $request ) {
		$search = $request->get_param( 'search' );

		$query = new WP_Query(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				's'              => $search,
				'posts_per_page' => 20,
			)
		);

		$results = array();
		foreach ( $query->posts as $post ) {
			$product = wc_get_product( $post->ID );
			if ( ! $product ) {
				continue;
			}
			$results[] = array(
				'id'    => $product->get_id(),
				'name'  => $product->get_name(),
				'sku'   => $product->get_sku(),
				'image' => wp_get_attachment_image_url( $product->get_image_id(), 'thumbnail' ),
			);
		}

		return new WP_REST_Response( $results, 200 );
	}

	/**
	 * Generate copy for a single product and log the eval row.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function generate( $request ) {
		$product_id = (int) $request->get_param( 'product_id' );

		$context = WooCopy_Product_Data::get_context( $product_id );
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$settings       = get_option( 'woocopy_ai_settings', array() );
		$prompt_version = ! empty( $settings['prompt_version'] ) ? $settings['prompt_version'] : 'v1';
		$model          = ! empty( $settings['model'] ) ? $settings['model'] : 'claude-sonnet-4-6';
		$voice_profile  = WooCopy_Voice_Profile::get_profile_text();

		$api        = new WooCopy_API();
		$generation = $api->generate_product_copy( $context, $voice_profile, $prompt_version );

		if ( is_wp_error( $generation ) ) {
			return $generation;
		}

		$eval_id = WooCopy_Eval::log_generation( $product_id, $generation, $context, $prompt_version, $model );

		return new WP_REST_Response(
			array(
				'eval_id'            => $eval_id,
				'product_id'         => $product_id,
				'short_description'  => $generation['short_description'],
				'long_description'   => $generation['long_description'],
				'existing_short'     => $context['existing_short_description'],
				'existing_long'      => $context['existing_long_description'],
			),
			200
		);
	}

	/**
	 * Estimate cost/time for a bulk generation run before committing to it.
	 * Uses a flat per-product token estimate; good enough for a UI warning,
	 * not meant to be a billing-grade calculation.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function bulk_estimate( $request ) {
		$product_ids = (array) $request->get_param( 'product_ids' );
		$count       = count( $product_ids );

		// Rough estimate: ~800 input tokens + ~500 output tokens per product.
		$est_input_tokens  = $count * 800;
		$est_output_tokens = $count * 500;

		return new WP_REST_Response(
			array(
				'product_count'      => $count,
				'est_input_tokens'   => $est_input_tokens,
				'est_output_tokens'  => $est_output_tokens,
				'est_time_seconds'   => $count * 3,
			),
			200
		);
	}

	/**
	 * Run bulk generation, rate-limited to avoid hammering the API.
	 * Products are processed synchronously in small batches; for very
	 * large catalogs this should be swapped for Action Scheduler, but
	 * this is sufficient for a demo/portfolio-scale store.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function bulk_generate( $request ) {
		$product_ids = array_map( 'absint', (array) $request->get_param( 'product_ids' ) );
		$product_ids = array_slice( $product_ids, 0, 25 ); // Hard safety cap per request.

		$settings       = get_option( 'woocopy_ai_settings', array() );
		$prompt_version = ! empty( $settings['prompt_version'] ) ? $settings['prompt_version'] : 'v1';
		$model          = ! empty( $settings['model'] ) ? $settings['model'] : 'claude-sonnet-4-6';
		$voice_profile  = WooCopy_Voice_Profile::get_profile_text();
		$api            = new WooCopy_API();

		$results = array();

		foreach ( $product_ids as $product_id ) {
			$context = WooCopy_Product_Data::get_context( $product_id );
			if ( is_wp_error( $context ) ) {
				$results[] = array(
					'product_id' => $product_id,
					'error'      => $context->get_error_message(),
				);
				continue;
			}

			$generation = $api->generate_product_copy( $context, $voice_profile, $prompt_version );

			if ( is_wp_error( $generation ) ) {
				$results[] = array(
					'product_id' => $product_id,
					'error'      => $generation->get_error_message(),
				);
				// Simple rate-limit backoff on error before continuing the loop.
				usleep( 500000 );
				continue;
			}

			$eval_id   = WooCopy_Eval::log_generation( $product_id, $generation, $context, $prompt_version, $model );
			$results[] = array(
				'product_id'        => $product_id,
				'eval_id'            => $eval_id,
				'short_description' => $generation['short_description'],
				'long_description'  => $generation['long_description'],
			);

			// Gentle pacing between requests.
			usleep( 300000 );
		}

		return new WP_REST_Response( array( 'results' => $results ), 200 );
	}

	/**
	 * List eval rows for the review queue, optionally filtered by status.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function list_evals( $request ) {
		$status = $request->get_param( 'status' );
		$page   = (int) $request->get_param( 'page' ) ?: 1;

		$rows = WooCopy_Eval::query(
			array(
				'status' => $status ? sanitize_key( $status ) : '',
				'page'   => $page,
			)
		);

		$enriched = array_map(
			function ( $row ) {
				$product      = wc_get_product( $row->product_id );
				$row->product_name = $product ? $product->get_name() : __( '(deleted product)', 'woocopy-ai' );
				$row->rubric_scores = json_decode( $row->rubric_scores, true );
				return $row;
			},
			$rows
		);

		return new WP_REST_Response( $enriched, 200 );
	}

	/**
	 * Submit a human review decision for an eval row.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function review_eval( $request ) {
		$eval_id      = (int) $request->get_param( 'id' );
		$decision     = sanitize_key( $request->get_param( 'decision' ) );
		$final_short  = sanitize_textarea_field( (string) $request->get_param( 'short_description' ) );
		$final_long   = wp_kses_post( (string) $request->get_param( 'long_description' ) );
		$apply_to_product = (bool) $request->get_param( 'apply_to_product' );

		$result = WooCopy_Eval::log_review( $eval_id, $decision, $final_short, $final_long );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( $apply_to_product && in_array( $decision, array( 'accepted', 'edited' ), true ) ) {
			$row = WooCopy_Eval::get_eval( $eval_id );
			if ( $row ) {
				$product = wc_get_product( $row->product_id );
				if ( $product ) {
					$product->set_short_description( $final_short );
					$product->set_description( $final_long );
					$product->save();
				}
			}
		}

		return new WP_REST_Response( array( 'success' => true ), 200 );
	}

	/**
	 * Dashboard aggregate stats.
	 *
	 * @return WP_REST_Response
	 */
	public function dashboard() {
		return new WP_REST_Response( WooCopy_Eval::get_dashboard_stats(), 200 );
	}

	/**
	 * Get current voice profile.
	 *
	 * @return WP_REST_Response
	 */
	public function get_voice_profile() {
		return new WP_REST_Response( WooCopy_Voice_Profile::get_full_record(), 200 );
	}

	/**
	 * Rebuild voice profile from submitted examples.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_voice_profile( $request ) {
		$examples = (array) $request->get_param( 'examples' );
		$examples = array_map( 'sanitize_textarea_field', $examples );

		$profile = WooCopy_Voice_Profile::build_from_examples( $examples );

		if ( is_wp_error( $profile ) ) {
			return $profile;
		}

		return new WP_REST_Response( WooCopy_Voice_Profile::get_full_record(), 200 );
	}

	/**
	 * Get plugin settings (API key is masked, never returned in full).
	 *
	 * @return WP_REST_Response
	 */
	public function get_settings() {
		$settings = get_option( 'woocopy_ai_settings', array() );

		$provider = ! empty( $settings['provider'] ) ? $settings['provider'] : 'anthropic';

		$masked_key = '';
		if ( ! empty( $settings['api_key'] ) ) {
			$masked_key = 'sk-ant-...' . substr( $settings['api_key'], -4 );
		}

		$has_api_key = ! empty( $settings['api_key'] ) || 'ollama' === $provider;

		return new WP_REST_Response(
			array(
				'api_key_masked'  => $masked_key,
				'has_api_key'     => $has_api_key,
				'provider'        => $provider,
				'ollama_model'    => isset( $settings['ollama_model'] ) ? $settings['ollama_model'] : 'llama3.1:8b',
				'model'           => isset( $settings['model'] ) ? $settings['model'] : 'claude-sonnet-4-6',
				'prompt_version'  => isset( $settings['prompt_version'] ) ? $settings['prompt_version'] : 'v1',
				'auto_publish'    => ! empty( $settings['auto_publish'] ),
			),
			200
		);
	}

	/**
	 * Update plugin settings.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function update_settings( $request ) {
		$settings = get_option( 'woocopy_ai_settings', array() );

		$new_api_key = $request->get_param( 'api_key' );
		if ( ! empty( $new_api_key ) ) {
			$settings['api_key'] = sanitize_text_field( $new_api_key );
		}

		if ( null !== $request->get_param( 'provider' ) ) {
			$settings['provider'] = sanitize_text_field( $request->get_param( 'provider' ) );
		}

		if ( null !== $request->get_param( 'ollama_model' ) ) {
			$settings['ollama_model'] = sanitize_text_field( $request->get_param( 'ollama_model' ) );
		}

		if ( null !== $request->get_param( 'model' ) ) {
			$settings['model'] = sanitize_text_field( $request->get_param( 'model' ) );
		}

		if ( null !== $request->get_param( 'prompt_version' ) ) {
			$settings['prompt_version'] = sanitize_text_field( $request->get_param( 'prompt_version' ) );
		}

		if ( null !== $request->get_param( 'auto_publish' ) ) {
			$settings['auto_publish'] = (bool) $request->get_param( 'auto_publish' );
		}

		update_option( 'woocopy_ai_settings', $settings );

		return new WP_REST_Response( array( 'success' => true ), 200 );
	}
}