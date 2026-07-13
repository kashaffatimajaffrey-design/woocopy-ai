<?php
/**
 * Core plugin class. Wires up the admin menu, REST API, and asset loading.
 *
 * @package WooCopy_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WooCopy_Plugin
 */
final class WooCopy_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var WooCopy_Plugin|null
	 */
	private static $instance = null;

	/**
	 * REST controller instance.
	 *
	 * @var WooCopy_REST_Controller
	 */
	private $rest_controller;

	/**
	 * Get singleton instance.
	 *
	 * @return WooCopy_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor. Private — use instance().
	 */
	private function __construct() {
		$this->rest_controller = new WooCopy_REST_Controller();

		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'rest_api_init', array( $this->rest_controller, 'register_routes' ) );
		add_action( 'admin_notices', array( $this, 'maybe_show_api_key_notice' ) );

		// Bulk action + row action on the Products list table.
		add_filter( 'bulk_actions-edit-product', array( $this, 'register_bulk_action' ) );
		add_filter( 'handle_bulk_actions-edit-product', array( $this, 'handle_bulk_action' ), 10, 3 );
		add_filter( 'post_row_actions', array( $this, 'add_product_row_action' ), 10, 2 );
	}

	/**
	 * Register the top-level admin menu page (React app mount point).
	 */
	public function register_admin_menu() {
		add_menu_page(
			__( 'WooCopy AI', 'woocopy-ai' ),
			__( 'WooCopy AI', 'woocopy-ai' ),
			'manage_woocommerce',
			'woocopy-ai',
			array( $this, 'render_admin_page' ),
			'dashicons-edit-page',
			56
		);

		add_submenu_page(
			'woocopy-ai',
			__( 'Review Queue', 'woocopy-ai' ),
			__( 'Review Queue', 'woocopy-ai' ),
			'manage_woocommerce',
			'woocopy-ai',
			array( $this, 'render_admin_page' )
		);

		add_submenu_page(
			'woocopy-ai',
			__( 'Eval Dashboard', 'woocopy-ai' ),
			__( 'Eval Dashboard', 'woocopy-ai' ),
			'manage_woocommerce',
			'woocopy-ai-evals',
			array( $this, 'render_admin_page' )
		);

		add_submenu_page(
			'woocopy-ai',
			__( 'Voice Profile', 'woocopy-ai' ),
			__( 'Voice Profile', 'woocopy-ai' ),
			'manage_woocommerce',
			'woocopy-ai-voice',
			array( $this, 'render_admin_page' )
		);

		add_submenu_page(
			'woocopy-ai',
			__( 'Settings', 'woocopy-ai' ),
			__( 'Settings', 'woocopy-ai' ),
			'manage_options',
			'woocopy-ai-settings',
			array( $this, 'render_admin_page' )
		);
	}

	/**
	 * Single mount point div — the React app (with its own router) takes over.
	 */
	public function render_admin_page() {
		echo '<div id="woocopy-ai-root" class="wrap"></div>';
	}

	/**
	 * Enqueue the compiled React admin bundle only on our own admin pages.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_admin_assets( $hook ) {
		if ( strpos( $hook, 'woocopy-ai' ) === false ) {
			return;
		}

		$asset_file_path = WOOCOPY_AI_PLUGIN_DIR . 'admin/build/index.asset.php';
		$asset_file      = file_exists( $asset_file_path )
			? require $asset_file_path
			: array(
				'dependencies' => array( 'wp-element', 'wp-api-fetch', 'wp-i18n', 'wp-components' ),
				'version'      => WOOCOPY_AI_VERSION,
			);

		wp_enqueue_script(
			'woocopy-ai-admin',
			WOOCOPY_AI_PLUGIN_URL . 'admin/build/index.js',
			$asset_file['dependencies'],
			$asset_file['version'],
			true
		);

		wp_enqueue_style(
			'woocopy-ai-admin',
			WOOCOPY_AI_PLUGIN_URL . 'admin/build/index.css',
			array( 'wp-components' ),
			WOOCOPY_AI_VERSION
		);

		wp_localize_script(
			'woocopy-ai-admin',
			'woocopyAI',
			array(
				'restUrl'   => esc_url_raw( rest_url( 'woocopy-ai/v1' ) ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'adminUrl'  => esc_url_raw( admin_url() ),
				'hasApiKey' => (bool) $this->get_api_key() || 'ollama' === $this->get_provider(),
			)
		);

		wp_set_script_translations( 'woocopy-ai-admin', 'woocopy-ai', WOOCOPY_AI_PLUGIN_DIR . 'languages' );
	}

	/**
	 * Nudge the admin to set an API key if missing (not applicable when
	 * running against a local Ollama provider, which needs no key).
	 */
	public function maybe_show_api_key_notice() {
		$screen = get_current_screen();
		if ( ! $screen || strpos( $screen->id, 'woocopy-ai' ) === false ) {
			return;
		}

		if ( $this->get_api_key() || 'ollama' === $this->get_provider() ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
			esc_html__( 'WooCopy AI needs an Anthropic API key to generate copy.', 'woocopy-ai' ),
			esc_url( admin_url( 'admin.php?page=woocopy-ai-settings' ) ),
			esc_html__( 'Add one now', 'woocopy-ai' )
		);
	}

	/**
	 * Get the stored (and ideally encrypted) API key.
	 *
	 * @return string
	 */
	private function get_api_key() {
		$settings = get_option( 'woocopy_ai_settings', array() );
		return isset( $settings['api_key'] ) ? $settings['api_key'] : '';
	}

	/**
	 * Which provider is configured: 'anthropic' (default) or 'ollama'.
	 *
	 * @return string
	 */
	private function get_provider() {
		$settings = get_option( 'woocopy_ai_settings', array() );
		return ! empty( $settings['provider'] ) ? $settings['provider'] : 'anthropic';
	}

	/**
	 * Add "Generate AI copy" to the Products list bulk actions dropdown.
	 *
	 * @param array $actions Existing bulk actions.
	 * @return array
	 */
	public function register_bulk_action( $actions ) {
		$actions['woocopy_ai_generate'] = __( 'Generate AI copy (WooCopy AI)', 'woocopy-ai' );
		return $actions;
	}

	/**
	 * Handle the bulk action: redirect into the review queue pre-filtered
	 * to the selected product IDs, rather than generating synchronously
	 * (bulk generation is rate-limited and cost-estimated client-side).
	 *
	 * @param string $redirect_to Redirect URL.
	 * @param string $action      Action name.
	 * @param array  $post_ids    Selected post IDs.
	 * @return string
	 */
	public function handle_bulk_action( $redirect_to, $action, $post_ids ) {
		if ( 'woocopy_ai_generate' !== $action ) {
			return $redirect_to;
		}

		$post_ids = array_map( 'absint', $post_ids );

		return add_query_arg(
			array(
				'page'        => 'woocopy-ai',
				'product_ids' => implode( ',', $post_ids ),
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Add a per-product "Generate AI copy" row action.
	 *
	 * @param array   $actions Existing row actions.
	 * @param WP_Post $post    Current post.
	 * @return array
	 */
	public function add_product_row_action( $actions, $post ) {
		if ( 'product' !== $post->post_type ) {
			return $actions;
		}

		$url = add_query_arg(
			array(
				'page'        => 'woocopy-ai',
				'product_ids' => $post->ID,
			),
			admin_url( 'admin.php' )
		);

		$actions['woocopy_ai'] = sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url( $url ),
			esc_html__( 'Generate AI copy', 'woocopy-ai' )
		);

		return $actions;
	}
}
