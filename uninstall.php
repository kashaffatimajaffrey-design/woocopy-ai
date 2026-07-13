<?php
/**
 * Fired when the plugin is deleted via the Plugins screen.
 *
 * Cleans up options and the custom eval table. This only runs on explicit
 * uninstall (not deactivation), so review history survives a simple
 * deactivate/reactivate cycle.
 *
 * @package WooCopy_AI
 */

// If uninstall not called from WordPress, exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

delete_option( 'woocopy_ai_settings' );
delete_option( 'woocopy_ai_voice_profile' );

$table_name = $wpdb->prefix . 'woocopy_evals';
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is safe, constructed internally, not user input.
$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" );
