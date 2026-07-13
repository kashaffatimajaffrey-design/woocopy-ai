<?php
/**
 * Registers the woocopy_ai_settings option with the Settings API so it's
 * discoverable/sanitized the WordPress-standard way, even though the React
 * admin UI talks to it via REST rather than a rendered settings form.
 *
 * @package WooCopy_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WooCopy_Settings
 */
class WooCopy_Settings {

	/**
	 * Register the setting and its sanitize callback.
	 */
	public static function register() {
		register_setting(
			'woocopy_ai_settings_group',
			'woocopy_ai_settings',
			array(
				'type'              => 'object',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => array(
					'api_key'        => '',
					'model'          => 'claude-sonnet-4-6',
					'prompt_version' => 'v1',
					'auto_publish'   => false,
					'voice_profile'  => '',
				),
			)
		);
	}

	/**
	 * Sanitize settings before saving.
	 *
	 * @param array $settings Raw settings array.
	 * @return array
	 */
	public static function sanitize( $settings ) {
		if ( ! is_array( $settings ) ) {
			return array();
		}

		$clean = array();

		if ( isset( $settings['api_key'] ) ) {
			$clean['api_key'] = sanitize_text_field( $settings['api_key'] );
		}
		if ( isset( $settings['model'] ) ) {
			$clean['model'] = sanitize_text_field( $settings['model'] );
		}
		if ( isset( $settings['prompt_version'] ) ) {
			$clean['prompt_version'] = sanitize_text_field( $settings['prompt_version'] );
		}
		$clean['auto_publish'] = ! empty( $settings['auto_publish'] );

		return $clean;
	}
}

add_action( 'admin_init', array( 'WooCopy_Settings', 'register' ) );
