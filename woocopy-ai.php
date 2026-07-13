<?php
/**
 * Plugin Name:       WooCopy AI
 * Plugin URI:         https://github.com/yourusername/woocopy-ai
 * Description:        AI-powered product copy generation for WooCommerce, with native product-data context, brand voice consistency, human-in-the-loop review, and built-in eval logging.
 * Version:             1.0.0
 * Requires at least:  6.3
 * Requires PHP:        8.0
 * Author:              Kashaf Fatima
 * Author URI:          https://github.com/yourusername
 * License:             GPL v2 or later
 * License URI:         https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:         woocopy-ai
 * Domain Path:         /languages
 * Requires Plugins:    woocommerce
 *
 * @package WooCopy_AI
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WOOCOPY_AI_VERSION', '1.0.2' );
define( 'WOOCOPY_AI_PLUGIN_FILE', __FILE__ );
define( 'WOOCOPY_AI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WOOCOPY_AI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WOOCOPY_AI_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Check that WooCommerce is active before doing anything.
 */
function woocopy_ai_check_woocommerce_active() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action(
			'admin_notices',
			function () {
				?>
				<div class="notice notice-error">
					<p>
						<?php
						esc_html_e(
							'WooCopy AI requires WooCommerce to be installed and active.',
							'woocopy-ai'
						);
						?>
					</p>
				</div>
				<?php
			}
		);
		return false;
	}
	return true;
}

/**
 * Composer-free autoloader for our includes/ classes.
 *
 * @param string $class_name Fully qualified class name.
 */
function woocopy_ai_autoloader( $class_name ) {
	if ( strpos( $class_name, 'WooCopy_' ) !== 0 ) {
		return;
	}

	$file_name = 'class-' . strtolower( str_replace( '_', '-', $class_name ) ) . '.php';
	$file_path = WOOCOPY_AI_PLUGIN_DIR . 'includes/' . $file_name;

	if ( file_exists( $file_path ) ) {
		require_once $file_path;
	}
}
spl_autoload_register( 'woocopy_ai_autoloader' );

/**
 * Activation hook: create custom eval-logging table.
 */
function woocopy_ai_activate() {
	require_once WOOCOPY_AI_PLUGIN_DIR . 'includes/class-woocopy-eval.php';
	WooCopy_Eval::create_table();

	// Default settings.
	if ( false === get_option( 'woocopy_ai_settings' ) ) {
		add_option(
			'woocopy_ai_settings',
			array(
				'api_key'           => '',
				'model'             => 'claude-sonnet-4-6',
				'prompt_version'    => 'v1',
				'auto_publish'      => false,
				'voice_profile'     => '',
			)
		);
	}
}
register_activation_hook( __FILE__, 'woocopy_ai_activate' );

/**
 * Deactivation hook. Data is intentionally preserved (evals, drafts, voice
 * profile) so reactivating doesn't lose review history. Uninstall.php
 * handles full cleanup if the user chooses to delete the plugin.
 */
function woocopy_ai_deactivate() {
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'woocopy_ai_deactivate' );

/**
 * Bootstraps the plugin once all plugins are loaded.
 */
function woocopy_ai_init() {
	if ( ! woocopy_ai_check_woocommerce_active() ) {
		return;
	}

	load_plugin_textdomain( 'woocopy-ai', false, dirname( WOOCOPY_AI_PLUGIN_BASENAME ) . '/languages' );

	WooCopy_Plugin::instance();
}
add_action( 'plugins_loaded', 'woocopy_ai_init' );

