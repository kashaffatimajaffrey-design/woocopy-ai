<?php
/**
 * Smoke tests for WooCopy_Eval's rubric scoring.
 *
 * Run with the WordPress PHPUnit test suite (wp-env / wp scaffold plugin-tests),
 * e.g.: `vendor/bin/phpunit --testsuite woocopy-ai`
 *
 * @package WooCopy_AI
 */

class WooCopy_Eval_Rubric_Test extends WP_UnitTestCase {

	/**
	 * The eval table should be created on activation and be queryable.
	 */
	public function test_table_exists_after_activation() {
		WooCopy_Eval::create_table();

		global $wpdb;
		$table = WooCopy_Eval::table_name();
		$result = $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" );

		$this->assertSame( $table, $result );
	}

	/**
	 * A generation whose copy includes the product name/category should
	 * score full keyword coverage.
	 */
	public function test_keyword_coverage_scoring() {
		$product_id = $this->factory()->post->create( array( 'post_type' => 'product' ) );

		$generation = array(
				'short_description' => 'A durable stainless steel water bottle for everyday use.',
				'long_description'  => 'This stainless steel water bottle keeps drinks cold for hours ' .
					'thanks to its double-wall vacuum insulation, making it the perfect companion for ' .
					'hiking, camping, and everyday outdoors adventures. Built to last with a durable, ' .
					'leak-proof design, this water bottle ensures your drinks stay refreshingly cold no ' .
					'matter where the day takes you.',
				'usage'              => array(),
		);

		$context = array(
			'name'       => 'Stainless Steel Water Bottle',
			'categories' => array( 'Outdoors' ),
			'tags'       => array(),
		);

		$eval_id = WooCopy_Eval::log_generation( $product_id, $generation, $context, 'v1', 'claude-sonnet-4-6' );
		$row     = WooCopy_Eval::get_eval( $eval_id );

		$this->assertNotNull( $row );
		$scores = json_decode( $row->rubric_scores, true );
		$this->assertGreaterThan( 0.5, $scores['keyword_coverage'] );
		$this->assertTrue( $scores['length_ok'] );
	}

	/**
	 * Risky superlatives should be flagged for human review.
	 */
	public function test_unsupported_claims_flagged() {
		$product_id = $this->factory()->post->create( array( 'post_type' => 'product' ) );

		$generation = array(
			'short_description' => 'The best product you will ever own, guaranteed.',
			'long_description'  => 'This is a world-class, number one item in its category.',
			'usage'              => array(),
		);

		$context = array(
			'name'       => 'Test Product',
			'categories' => array(),
			'tags'       => array(),
		);

		$eval_id = WooCopy_Eval::log_generation( $product_id, $generation, $context, 'v1', 'claude-sonnet-4-6' );
		$row     = WooCopy_Eval::get_eval( $eval_id );
		$scores  = json_decode( $row->rubric_scores, true );

		$this->assertNotEmpty( $scores['unsupported_claims'] );
		$this->assertContains( 'best', $scores['unsupported_claims'] );
	}

	/**
	 * Edit distance should be null on accept, and a positive integer on edit.
	 */
	public function test_review_edit_distance() {
		$product_id = $this->factory()->post->create( array( 'post_type' => 'product' ) );

		$generation = array(
			'short_description' => 'Original short description.',
			'long_description'  => 'Original long description text here.',
			'usage'              => array(),
		);
		$context = array( 'name' => 'Test', 'categories' => array(), 'tags' => array() );

		$eval_id = WooCopy_Eval::log_generation( $product_id, $generation, $context, 'v1', 'claude-sonnet-4-6' );

		WooCopy_Eval::log_review( $eval_id, 'edited', 'Edited short description.', 'Edited long description text here.' );
		$row = WooCopy_Eval::get_eval( $eval_id );

		$this->assertSame( 'edited', $row->status );
		$this->assertGreaterThan( 0, (int) $row->edit_distance_short );
	}
}
