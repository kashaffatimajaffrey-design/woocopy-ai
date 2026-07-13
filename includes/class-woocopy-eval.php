<?php
/**
 * The eval-logging layer: every generation is scored and logged, and every
 * human review decision (accept/edit/reject) feeds back into the same table.
 * This is the core of WooCopy AI's "production-grade AI feature" story.
 *
 * @package WooCopy_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WooCopy_Eval
 */
class WooCopy_Eval {

	/**
	 * Get the fully-qualified table name.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'woocopy_evals';
	}

	/**
	 * Create the custom eval table on activation.
	 */
	public static function create_table() {
		global $wpdb;

		$table_name      = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			product_id BIGINT UNSIGNED NOT NULL,
			prompt_version VARCHAR(20) NOT NULL DEFAULT 'v1',
			model VARCHAR(60) NOT NULL DEFAULT '',
			generated_short_description LONGTEXT NULL,
			generated_long_description LONGTEXT NULL,
			voice_profile_snapshot LONGTEXT NULL,
			rubric_scores LONGTEXT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'draft',
			human_edited_short_description LONGTEXT NULL,
			human_edited_long_description LONGTEXT NULL,
			edit_distance_short INT NULL,
			edit_distance_long INT NULL,
			reviewed_by BIGINT UNSIGNED NULL,
			token_usage LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			reviewed_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY product_id (product_id),
			KEY status (status),
			KEY prompt_version (prompt_version)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * Log a fresh generation, including automated rubric scoring.
	 *
	 * @param int   $product_id      Product ID.
	 * @param array $generation      Result array from WooCopy_API::generate_product_copy().
	 * @param array $product_context The product context that was sent to the model.
	 * @param string $prompt_version Prompt version tag.
	 * @param string $model          Model identifier used.
	 * @return int Insert ID (eval row ID).
	 */
	public static function log_generation( $product_id, $generation, $product_context, $prompt_version, $model ) {
		global $wpdb;

		$rubric = self::score_rubric( $generation, $product_context );

		$wpdb->insert(
			self::table_name(),
			array(
				'product_id'                    => $product_id,
				'prompt_version'                => $prompt_version,
				'model'                          => $model,
				'generated_short_description'   => $generation['short_description'],
				'generated_long_description'    => $generation['long_description'],
				'voice_profile_snapshot'        => WooCopy_Voice_Profile::get_profile_text(),
				'rubric_scores'                  => wp_json_encode( $rubric ),
				'status'                         => 'pending_review',
				'token_usage'                    => wp_json_encode( $generation['usage'] ),
				'created_at'                     => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return $wpdb->insert_id;
	}

	/**
	 * Record a human review decision (accept / edit / reject) and compute
	 * edit distance so we can track how much the model's drafts actually
	 * need editing over time — the core "is this AI feature earning its
	 * keep" metric.
	 *
	 * @param int    $eval_id         Eval row ID.
	 * @param string $decision        One of 'accepted', 'edited', 'rejected'.
	 * @param string $final_short     Final short description (post-edit, if any).
	 * @param string $final_long      Final long description (post-edit, if any).
	 * @return bool|WP_Error
	 */
	public static function log_review( $eval_id, $decision, $final_short = '', $final_long = '' ) {
		global $wpdb;

		$row = self::get_eval( $eval_id );
		if ( ! $row ) {
			return new WP_Error( 'woocopy_eval_not_found', __( 'Eval record not found.', 'woocopy-ai' ) );
		}

		if ( ! in_array( $decision, array( 'accepted', 'edited', 'rejected' ), true ) ) {
			return new WP_Error( 'woocopy_invalid_decision', __( 'Invalid review decision.', 'woocopy-ai' ) );
		}

		$edit_distance_short = null;
		$edit_distance_long  = null;

		if ( 'edited' === $decision ) {
			$edit_distance_short = levenshtein(
				substr( $row->generated_short_description, 0, 255 ),
				substr( $final_short, 0, 255 )
			);
			$edit_distance_long = levenshtein(
				substr( $row->generated_long_description, 0, 255 ),
				substr( $final_long, 0, 255 )
			);
		}

		$updated = $wpdb->update(
			self::table_name(),
			array(
				'status'                          => $decision,
				'human_edited_short_description' => $final_short,
				'human_edited_long_description'  => $final_long,
				'edit_distance_short'              => $edit_distance_short,
				'edit_distance_long'               => $edit_distance_long,
				'reviewed_by'                      => get_current_user_id(),
				'reviewed_at'                      => current_time( 'mysql' ),
			),
			array( 'id' => $eval_id ),
			array( '%s', '%s', '%s', '%d', '%d', '%d', '%s' ),
			array( '%d' )
		);

		return false !== $updated;
	}

	/**
	 * Automated rubric scoring — heuristic checks that run at generation time,
	 * before any human looks at the draft. Not a substitute for human review,
	 * but a fast signal for the dashboard and for flagging bad drafts early.
	 *
	 * @param array $generation      Generated copy.
	 * @param array $product_context Product context sent to the model.
	 * @return array
	 */
	private static function score_rubric( $generation, $product_context ) {
		$long  = $generation['long_description'];
		$short = $generation['short_description'];

		// SEO keyword coverage: how many of the product's own name-words /
		// category / tag / attribute terms show up in the generated copy.
		$keywords = array();
		if ( ! empty( $product_context['name'] ) ) {
			$keywords = array_merge( $keywords, preg_split( '/\s+/', $product_context['name'] ) );
		}
		if ( ! empty( $product_context['categories'] ) ) {
			$keywords = array_merge( $keywords, $product_context['categories'] );
		}
		if ( ! empty( $product_context['tags'] ) ) {
			$keywords = array_merge( $keywords, $product_context['tags'] );
		}
		$keywords = array_unique( array_filter( array_map( 'strtolower', $keywords ) ) );

		$haystack       = strtolower( $short . ' ' . $long );
		$matched        = 0;
		foreach ( $keywords as $kw ) {
			if ( strlen( $kw ) > 2 && false !== strpos( $haystack, $kw ) ) {
				++$matched;
			}
		}
		$keyword_coverage = count( $keywords ) > 0 ? round( $matched / count( $keywords ), 2 ) : null;

		// Length sanity checks.
		$short_word_count = str_word_count( $short );
		$long_word_count  = str_word_count( $long );

		// Naive "unsupported superlative" flag — words that often signal
		// unverifiable claims, worth a human's attention.
		$risky_terms = array( 'best', 'world-class', 'guaranteed', 'perfect', '#1', 'number one' );
		$flagged     = array();
		foreach ( $risky_terms as $term ) {
			if ( false !== stripos( $haystack, $term ) ) {
				$flagged[] = $term;
			}
		}

		return array(
			'keyword_coverage'      => $keyword_coverage,
			'keywords_checked'      => array_values( $keywords ),
			'short_word_count'      => $short_word_count,
			'long_word_count'       => $long_word_count,
			'length_ok'             => ( $short_word_count >= 5 && $short_word_count <= 40 )
				&& ( $long_word_count >= 40 && $long_word_count <= 400 ),
			'unsupported_claims'    => $flagged,
			'scored_at'             => current_time( 'mysql' ),
		);
	}

	/**
	 * Get a single eval row.
	 *
	 * @param int $eval_id Eval row ID.
	 * @return object|null
	 */
	public static function get_eval( $eval_id ) {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is safe, constructed internally.
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $eval_id ) );
	}

	/**
	 * Get eval rows, optionally filtered by status, for the review queue / dashboard.
	 *
	 * @param array $args {
	 *     @type string $status   Filter by status. Empty for all.
	 *     @type int    $per_page Results per page.
	 *     @type int    $page     Page number (1-indexed).
	 * }
	 * @return array
	 */
	public static function query( $args = array() ) {
		global $wpdb;
		$table = self::table_name();

		$defaults = array(
			'status'   => '',
			'per_page' => 20,
			'page'     => 1,
		);
		$args = wp_parse_args( $args, $defaults );

		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = $args['status'];
		}

		$offset   = max( 0, ( absint( $args['page'] ) - 1 ) * absint( $args['per_page'] ) );
		$params[] = absint( $args['per_page'] );
		$params[] = $offset;

		$where_sql = implode( ' AND ', $where );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is safe, constructed internally; values are parameterized below.
		$sql = $wpdb->prepare(
			"SELECT * FROM {$table} WHERE {$where_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d",
			$params
		);

		return $wpdb->get_results( $sql );
	}

	/**
	 * Aggregate stats for the eval dashboard: acceptance rate, average edit
	 * distance, keyword coverage trend over time, by prompt version.
	 *
	 * @return array
	 */
	public static function get_dashboard_stats() {
		global $wpdb;
		$table = self::table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is safe, constructed internally.
		$totals = $wpdb->get_row( "SELECT COUNT(*) as total FROM {$table}" );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is safe, constructed internally.
		$by_status = $wpdb->get_results(
			"SELECT status, COUNT(*) as count FROM {$table} GROUP BY status",
			OBJECT_K
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is safe, constructed internally.
		$by_prompt_version = $wpdb->get_results(
			"SELECT prompt_version,
				COUNT(*) as total,
				SUM(CASE WHEN status = 'accepted' THEN 1 ELSE 0 END) as accepted,
				SUM(CASE WHEN status = 'edited' THEN 1 ELSE 0 END) as edited,
				SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
				AVG(edit_distance_long) as avg_edit_distance_long
			FROM {$table}
			GROUP BY prompt_version"
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is safe, constructed internally.
		$recent = $wpdb->get_results(
			"SELECT id, product_id, prompt_version, status, rubric_scores, created_at
			FROM {$table} ORDER BY created_at DESC LIMIT 50"
		);

		return array(
			'total'             => $totals ? (int) $totals->total : 0,
			'by_status'         => $by_status,
			'by_prompt_version' => $by_prompt_version,
			'recent'            => $recent,
		);
	}
}
