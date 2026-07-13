<?php
/**
 * Thin wrapper around the Anthropic Messages API, with a local Ollama
 * fallback provider for development/demo use without billing.
 *
 * @package WooCopy_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WooCopy_API
 */
class WooCopy_API {

	const API_ENDPOINT    = 'https://api.anthropic.com/v1/messages';
	const API_VERSION     = '2023-06-01';
	const OLLAMA_ENDPOINT = 'http://localhost:11434/api/chat';

	/**
	 * Generate product copy for a single product.
	 *
	 * @param array  $product_context Structured product data (see WooCopy_Product_Data).
	 * @param string $voice_profile   Extracted brand voice profile text, or empty.
	 * @param string $prompt_version  Identifier for eval logging (e.g. "v1").
	 * @return array|WP_Error
	 */
	public function generate_product_copy( $product_context, $voice_profile = '', $prompt_version = 'v1' ) {
		$system_prompt = $this->build_system_prompt( $voice_profile, $prompt_version );
		$user_prompt   = $this->build_user_prompt( $product_context );

		if ( 'ollama' === $this->get_provider() ) {
			return $this->call_ollama( $system_prompt, $user_prompt );
		}

		return $this->call_anthropic( $system_prompt, $user_prompt );
	}

	/**
	 * Call the Anthropic Messages API.
	 *
	 * @param string $system_prompt System prompt.
	 * @param string $user_prompt   User prompt.
	 * @return array|WP_Error
	 */
	private function call_anthropic( $system_prompt, $user_prompt ) {
		$api_key = $this->get_api_key();
		if ( empty( $api_key ) ) {
			return new WP_Error( 'woocopy_no_api_key', __( 'No Anthropic API key configured.', 'woocopy-ai' ) );
		}

		$body = array(
			'model'      => $this->get_model(),
			'max_tokens' => 1024,
			'system'     => $system_prompt,
			'messages'   => array(
				array(
					'role'    => 'user',
					'content' => $user_prompt,
				),
			),
		);

		$response = wp_remote_post(
			self::API_ENDPOINT,
			array(
				'timeout' => 45,
				'headers' => array(
					'x-api-key'         => $api_key,
					'anthropic-version' => self::API_VERSION,
					'content-type'      => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code          = wp_remote_retrieve_response_code( $response );
		$response_body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			$message = isset( $response_body['error']['message'] )
				? $response_body['error']['message']
				: __( 'Unknown error from Anthropic API.', 'woocopy-ai' );
			return new WP_Error( 'woocopy_api_error', $message, array( 'status' => $code ) );
		}

		$text = '';
		if ( ! empty( $response_body['content'] ) && is_array( $response_body['content'] ) ) {
			foreach ( $response_body['content'] as $block ) {
				if ( isset( $block['type'] ) && 'text' === $block['type'] ) {
					$text .= $block['text'];
				}
			}
		}

		$parsed = $this->parse_generated_text( $text );

		return array(
			'short_description' => $parsed['short_description'],
			'long_description'  => $parsed['long_description'],
			'raw_response'       => $text,
			'usage'              => isset( $response_body['usage'] ) ? $response_body['usage'] : array(),
		);
	}

	/**
	 * Call a local Ollama server (no API key / billing required).
	 *
	 * @param string $system_prompt System prompt.
	 * @param string $user_prompt   User prompt.
	 * @return array|WP_Error
	 */
	private function call_ollama( $system_prompt, $user_prompt ) {
		$body = array(
			'model'    => $this->get_ollama_model(),
			'stream'   => false,
			'messages' => array(
				array(
					'role'    => 'system',
					'content' => $system_prompt,
				),
				array(
					'role'    => 'user',
					'content' => $user_prompt,
				),
			),
		);

		$response = wp_remote_post(
			self::OLLAMA_ENDPOINT,
			array(
				'timeout' => 90, // Local generation can be slower than a hosted API.
				'headers' => array(
					'content-type' => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'woocopy_ollama_unreachable',
				__( 'Could not reach local Ollama server. Is Ollama running (ollama serve)?', 'woocopy-ai' )
			);
		}

		$code          = wp_remote_retrieve_response_code( $response );
		$response_body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			$message = isset( $response_body['error'] )
				? $response_body['error']
				: __( 'Unknown error from Ollama.', 'woocopy-ai' );
			return new WP_Error( 'woocopy_ollama_error', $message, array( 'status' => $code ) );
		}

		$text = isset( $response_body['message']['content'] ) ? $response_body['message']['content'] : '';
		$parsed = $this->parse_generated_text( $text );

		// Ollama reports counts, not the same shape as Anthropic's usage object —
		// normalize to the same keys the eval table / dashboard already expect.
		$usage = array(
			'input_tokens'  => isset( $response_body['prompt_eval_count'] ) ? $response_body['prompt_eval_count'] : 0,
			'output_tokens' => isset( $response_body['eval_count'] ) ? $response_body['eval_count'] : 0,
		);

		return array(
			'short_description' => $parsed['short_description'],
			'long_description'  => $parsed['long_description'],
			'raw_response'       => $text,
			'usage'              => $usage,
		);
	}

	/**
	 * Ask the model to extract a reusable "voice profile" from example descriptions.
	 *
	 * @param array $example_descriptions Array of 2-3 example product descriptions.
	 * @return string|WP_Error
	 */
	public function extract_voice_profile( $example_descriptions ) {
		$examples_text = '';
		foreach ( $example_descriptions as $i => $desc ) {
			$examples_text .= sprintf( "Example %d:\n%s\n\n", $i + 1, $desc );
		}

		$system = 'You are a brand voice analyst. Given example product descriptions, extract a concise, ' .
			'reusable style profile: tone, formality, typical sentence length, point of view, recurring ' .
			'phrases or patterns, and what to avoid. Output plain text, structured under short headers. ' .
			'This profile will be injected into future prompts to keep new product copy consistent with ' .
			'these examples. Do not include any preamble.';

		$user = "Analyze these example product descriptions and produce a voice profile:\n\n" . $examples_text;

		if ( 'ollama' === $this->get_provider() ) {
			$result = $this->call_ollama( $system, $user );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			return trim( $result['raw_response'] );
		}

		$api_key = $this->get_api_key();
		if ( empty( $api_key ) ) {
			return new WP_Error( 'woocopy_no_api_key', __( 'No Anthropic API key configured.', 'woocopy-ai' ) );
		}

		$body = array(
			'model'      => $this->get_model(),
			'max_tokens' => 600,
			'system'     => $system,
			'messages'   => array(
				array(
					'role'    => 'user',
					'content' => $user,
				),
			),
		);

		$response = wp_remote_post(
			self::API_ENDPOINT,
			array(
				'timeout' => 30,
				'headers' => array(
					'x-api-key'         => $api_key,
					'anthropic-version' => self::API_VERSION,
					'content-type'      => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code          = wp_remote_retrieve_response_code( $response );
		$response_body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			$message = isset( $response_body['error']['message'] )
				? $response_body['error']['message']
				: __( 'Unknown error from Anthropic API.', 'woocopy-ai' );
			return new WP_Error( 'woocopy_api_error', $message, array( 'status' => $code ) );
		}

		$text = '';
		if ( ! empty( $response_body['content'] ) ) {
			foreach ( $response_body['content'] as $block ) {
				if ( isset( $block['type'] ) && 'text' === $block['type'] ) {
					$text .= $block['text'];
				}
			}
		}

		return trim( $text );
	}

	/**
	 * Build the system prompt, injecting voice profile if present.
	 *
	 * @param string $voice_profile  Voice profile text.
	 * @param string $prompt_version Version tag, kept in sync with eval logging.
	 * @return string
	 */
	private function build_system_prompt( $voice_profile, $prompt_version ) {
		$prompt = "You are an expert e-commerce copywriter working inside a WooCommerce store.\n" .
			"Write a SHORT_DESCRIPTION (1-2 sentences, used in listings) and a LONG_DESCRIPTION " .
			"(3-5 short paragraphs, used on the product page) for the product described by the user.\n" .
			"Requirements:\n" .
			"- Base every claim strictly on the provided product attributes. Never invent features, " .
			"materials, or specs not given.\n" .
			"- Naturally include relevant keywords for SEO without keyword-stuffing.\n" .
			"- Do not use superlatives you can't back up with data (e.g. 'the best', 'world-class') " .
			"unless the product data supports it.\n" .
			"Output format, exactly:\n" .
			"SHORT_DESCRIPTION:\n<text>\n\nLONG_DESCRIPTION:\n<text>\n";

		if ( ! empty( $voice_profile ) ) {
			$prompt .= "\nApply this brand voice profile to your writing:\n" . $voice_profile . "\n";
		}

		return $prompt;
	}

	/**
	 * Build the user-turn prompt from structured product context.
	 *
	 * @param array $product_context Structured product data.
	 * @return string
	 */
	private function build_user_prompt( $product_context ) {
		return "Product data (JSON):\n" . wp_json_encode( $product_context, JSON_PRETTY_PRINT );
	}

	/**
	 * Parse the SHORT_DESCRIPTION / LONG_DESCRIPTION blocks out of raw text.
	 *
	 * @param string $text Raw model output.
	 * @return array
	 */
	private function parse_generated_text( $text ) {
		$short = '';
		$long  = '';

		// Use the LAST occurrence of each label as the split point — smaller/local
		// models sometimes echo the label format back before actually answering,
		// so anchoring on the last match skips any preamble that mimics the format.
		if ( preg_match_all( '/SHORT_DESCRIPTION:/i', $text, $sm, PREG_OFFSET_CAPTURE ) && $sm[0] ) {
			$short_start = end( $sm[0] )[1] + strlen( end( $sm[0] )[0] );
		} else {
			$short_start = null;
		}

		if ( preg_match_all( '/LONG_DESCRIPTION:/i', $text, $lm, PREG_OFFSET_CAPTURE ) && $lm[0] ) {
			$long_label  = end( $lm[0] );
			$long_start  = $long_label[1] + strlen( $long_label[0] );
		} else {
			$long_start = null;
		}

		if ( null !== $short_start && null !== $long_start && $long_start > $short_start ) {
			$short = substr( $text, $short_start, ( $long_label[1] ) - $short_start );
			$long  = substr( $text, $long_start );
		} elseif ( null !== $long_start ) {
			$long = substr( $text, $long_start );
		} elseif ( null !== $short_start ) {
			$short = substr( $text, $short_start );
		}

		$clean = function ( $str ) {
			$str = trim( $str );
			// Strip any stray label tokens that leaked into the body (repeated
			// headers, mid-sentence echoes) rather than only at the boundary.
			$str = preg_replace( '/\b(SHORT|LONG)_DESCRIPTION:\s*/i', '', $str );
			// Strip wrapping quotes some local models add around the whole string.
			$str = trim( $str, "\"'" );
			$str = trim( $str );
			return $str;
		};

		$short = $clean( $short );
		$long  = $clean( $long );

		if ( '' === $short && '' === $long ) {
			$long = $clean( $text );
		}

		return array(
			'short_description' => $short,
			'long_description'  => $long,
		);
	}

	/**
	 * Which provider to use: 'anthropic' (default) or 'ollama'.
	 *
	 * @return string
	 */
	private function get_provider() {
		$settings = get_option( 'woocopy_ai_settings', array() );
		return ! empty( $settings['provider'] ) ? $settings['provider'] : 'anthropic';
	}

	/**
	 * Get configured Anthropic API key.
	 *
	 * @return string
	 */
	private function get_api_key() {
		$settings = get_option( 'woocopy_ai_settings', array() );
		return isset( $settings['api_key'] ) ? $settings['api_key'] : '';
	}

	/**
	 * Get configured Anthropic model, with a safe default.
	 *
	 * @return string
	 */
	private function get_model() {
		$settings = get_option( 'woocopy_ai_settings', array() );
		return ! empty( $settings['model'] ) ? $settings['model'] : 'claude-sonnet-4-6';
	}

	/**
	 * Get configured Ollama model, with a safe default.
	 *
	 * @return string
	 */
	private function get_ollama_model() {
		$settings = get_option( 'woocopy_ai_settings', array() );
		return ! empty( $settings['ollama_model'] ) ? $settings['ollama_model'] : 'llama3.1:8b';
	}
}
