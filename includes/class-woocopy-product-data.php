<?php
/**
 * Pulls structured, native WooCommerce product data to use as prompt context.
 *
 * @package WooCopy_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WooCopy_Product_Data
 */
class WooCopy_Product_Data {

	/**
	 * Build a structured context array for a given product ID.
	 *
	 * @param int $product_id Product post ID.
	 * @return array|WP_Error
	 */
	public static function get_context( $product_id ) {
		$product = wc_get_product( $product_id );

		if ( ! $product ) {
			return new WP_Error( 'woocopy_invalid_product', __( 'Invalid product ID.', 'woocopy-ai' ) );
		}

		$context = array(
			'name'                    => $product->get_name(),
			'sku'                     => $product->get_sku(),
			'type'                    => $product->get_type(),
			'existing_short_description' => wp_strip_all_tags( $product->get_short_description() ),
			'existing_long_description'  => wp_strip_all_tags( $product->get_description() ),
			'regular_price'           => $product->get_regular_price(),
			'sale_price'              => $product->get_sale_price(),
			'stock_status'            => $product->get_stock_status(),
			'stock_quantity'          => $product->get_stock_quantity(),
			'categories'              => self::get_term_names( $product, 'product_cat' ),
			'tags'                    => self::get_term_names( $product, 'product_tag' ),
			'attributes'              => self::get_attributes( $product ),
			'weight'                  => $product->get_weight(),
			'dimensions'              => array(
				'length' => $product->get_length(),
				'width'  => $product->get_width(),
				'height' => $product->get_height(),
			),
		);

		if ( $product->is_type( 'variable' ) ) {
			$context['variations'] = self::get_variations( $product );
		}

		/**
		 * Filter the product context sent to the AI, so store owners can add
		 * custom meta fields (e.g. materials, care instructions) without
		 * editing plugin code.
		 *
		 * @param array      $context Structured product context.
		 * @param WC_Product $product The product object.
		 */
		return apply_filters( 'woocopy_ai_product_context', $context, $product );
	}

	/**
	 * Get taxonomy term names attached to a product.
	 *
	 * @param WC_Product $product  Product object.
	 * @param string     $taxonomy Taxonomy slug.
	 * @return array
	 */
	private static function get_term_names( $product, $taxonomy ) {
		$terms = get_the_terms( $product->get_id(), $taxonomy );
		if ( ! $terms || is_wp_error( $terms ) ) {
			return array();
		}
		return wp_list_pluck( $terms, 'name' );
	}

	/**
	 * Get product attributes as a flat name => value(s) map.
	 *
	 * @param WC_Product $product Product object.
	 * @return array
	 */
	private static function get_attributes( $product ) {
		$out = array();
		foreach ( $product->get_attributes() as $attribute ) {
			if ( is_a( $attribute, 'WC_Product_Attribute' ) ) {
				$name = wc_attribute_label( $attribute->get_name() );
				if ( $attribute->is_taxonomy() ) {
					$terms         = wc_get_product_terms( $product->get_id(), $attribute->get_name(), array( 'fields' => 'names' ) );
					$out[ $name ] = $terms;
				} else {
					$out[ $name ] = $attribute->get_options();
				}
			}
		}
		return $out;
	}

	/**
	 * Get a summary of variations for variable products.
	 *
	 * @param WC_Product_Variable $product Variable product object.
	 * @return array
	 */
	private static function get_variations( $product ) {
		$variations = array();
		foreach ( $product->get_available_variations() as $variation_data ) {
			$variations[] = array(
				'attributes' => $variation_data['attributes'],
				'price'      => $variation_data['display_price'],
				'in_stock'   => $variation_data['is_in_stock'],
			);
		}
		return $variations;
	}
}
