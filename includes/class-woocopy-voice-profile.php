<?php
/**
 * Manages the store's brand voice profile: examples in, extracted profile out.
 *
 * @package WooCopy_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WooCopy_Voice_Profile
 */
class WooCopy_Voice_Profile {

	const OPTION_KEY = 'woocopy_ai_voice_profile';

	/**
	 * Save example descriptions and re-run extraction via the API.
	 *
	 * @param array $examples Array of example description strings (2-3 recommended).
	 * @return string|WP_Error The extracted profile text, or WP_Error on failure.
	 */
	public static function build_from_examples( $examples ) {
		$examples = array_filter( array_map( 'trim', $examples ) );

		if ( count( $examples ) < 1 ) {
			return new WP_Error( 'woocopy_no_examples', __( 'At least one example description is required.', 'woocopy-ai' ) );
		}

		$api     = new WooCopy_API();
		$profile = $api->extract_voice_profile( $examples );

		if ( is_wp_error( $profile ) ) {
			return $profile;
		}

		update_option(
			self::OPTION_KEY,
			array(
				'examples'   => $examples,
				'profile'    => $profile,
				'updated_at' => current_time( 'mysql' ),
			)
		);

		return $profile;
	}

	/**
	 * Get the current stored voice profile text (empty string if none set).
	 *
	 * @return string
	 */
	public static function get_profile_text() {
		$data = get_option( self::OPTION_KEY, array() );
		return isset( $data['profile'] ) ? $data['profile'] : '';
	}

	/**
	 * Get the full stored voice profile record (examples + profile + timestamp).
	 *
	 * @return array
	 */
	public static function get_full_record() {
		return get_option(
			self::OPTION_KEY,
			array(
				'examples'   => array(),
				'profile'    => '',
				'updated_at' => '',
			)
		);
	}

	/**
	 * Clear the stored voice profile.
	 */
	public static function clear() {
		delete_option( self::OPTION_KEY );
	}
}
