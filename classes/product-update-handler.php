<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dfrpswc_Product_Update_Handler {

	/** @var WC_Product $wc_product */
	public $wc_product;

	/** @var array $dfr_product An array of product data returned from Datafeedr */
	public $dfr_product;

	/** @var array $product_set An array of a Product Set generated by get_post() */
	public $product_set;

	/** @var string $action Either "insert" or "update" */
	public $action;

	/** @var array $post An array of a Product generated by get_post(). */
	public $post;

	public function __construct( WC_Product $wc_product, array $dfr_product, array $product_set, string $action ) {
		$this->wc_product  = $wc_product;
		$this->dfr_product = $dfr_product;
		$this->product_set = $product_set;
		$this->action      = $action;
		$this->post        = get_post( $wc_product->get_id(), ARRAY_A );
	}

	/**
	 * Updates a products post fields, post meta, taxonomies and attributes.
	 */
	public function update() {
		$this->update_post();
		$this->update_meta();
		$this->update_taxonomies();
		$this->update_attributes();

		do_action( 'dfrpswc_pre_save_product', $this );
		$this->save_product();
		do_action( 'dfrpswc_post_save_product', $this );

		do_action_deprecated(
			'dfrpswc_do_product',
			[ $this->post, $this->dfr_product, $this->product_set, $this->action ],
			'1.3.0',
			'dfrpswc_post_save_product'
		);
	}

	/**
	 * Updates a products "post" fields.
	 */
	public function update_post() {

		if ( ! $this->post_is_valid() ) {
			return;
		}

		$post = [
			'post_title'   => $this->dfr_product['name'] ?? '',
			'post_content' => $this->dfr_product['description'] ?? '',
			'post_excerpt' => $this->dfr_product['shortdescription'] ?? '',
			'post_status'  => 'publish',
			'post_author'  => absint( $this->product_set['post_author'] ?? 0 ),
		];

		$post = apply_filters(
			'dfrpswc_filter_post_array',
			$post,
			$this->dfr_product,
			$this->product_set,
			$this->action
		);

		$this->update_post_fields( $post );
		$this->update_unhandled_post_fields();
	}

	/**
	 * Update all post fields using the WC_Product::methods().
	 *
	 * @param array $post
	 */
	private function update_post_fields( array $post ) {
		$fields = $this->get_wp_post_wc_product_field_method_map();
		foreach ( $fields as $field => $handler ) {
			$method    = sanitize_key( $handler['method'] );
			$callbacks = $handler['callbacks'] ?? '';
			if ( isset( $post[ $field ] ) && method_exists( $this->wc_product, $method ) ) {
				$this->wc_product->{$method}( $this->handle_callbacks( $post[ $field ], $callbacks ) );
			}
		}
	}

	/**
	 * Updates a Post with additional post fields that were set but not handled by a WC_Product method.
	 *
	 * For example, post_author is not handled by a WC_Product setter method so we set the post_author
	 * using the wp_update_post() method.
	 */
	private function update_unhandled_post_fields() {
		$unhandled_post_fields = array_diff_key( $this->post, $this->get_wp_post_wc_product_field_method_map() );
		if ( ! empty( $unhandled_post_fields ) ) {
			$unhandled_post_fields['ID'] = $this->post['ID'];
			wp_update_post( $unhandled_post_fields );
		}
	}

	/**
	 * Updates a product's post meta fields.
	 */
	public function update_meta() {

		if ( ! $this->post_is_valid() ) {
			return;
		}

		$meta = [
			'_visibility'                => 'visible',
			'_stock'                     => '',
			'_stock_status'              => 'instock',
			'_manage_stock'              => false,
			'_downloadable'              => 'no',
			'_virtual'                   => 'no',
			'_backorders'                => 'no',
			'_purchase_note'             => '',
			'_product_url'               => $this->dfr_product['url'],
			'_regular_price'             => $this->dfr_product['price'] ?? 0,
			'_sale_price'                => $this->dfr_product['finalprice'] ?? 0,
			'_dfrps_is_dfrps_product'    => true,
			'_dfrps_is_dfrpswc_product'  => true,
			'_dfrps_product_id'          => $this->dfr_product['_id'],
			'_dfrps_product'             => $this->dfr_product,
			'_dfrps_product_check_image' => 1,
			'_dfrps_featured_image_url'  => $this->dfr_product['image'] ?? $this->dfr_product['thumbnail'] ?? '',
			'_dfrps_salediscount'        => absint( $this->dfr_product['salediscount'] ?? 0 ),
		];

		$meta = apply_filters(
			'dfrpswc_filter_postmeta_array',
			$meta,
			$this->post,
			$this->dfr_product,
			$this->product_set,
			$this->action
		);

		$this->wc_product->add_meta_data( '_dfrps_product_set_id', absint( $this->product_set['ID'] ) );

		$this->update_meta_fields( $meta );
		$this->update_unhandled_meta_fields( $meta );
	}

	/**
	 * Update a product's meta fields using a WC_Product method().
	 *
	 * @param array $meta
	 */
	private function update_meta_fields( array $meta ) {
		$fields = $this->get_wp_postmeta_wc_product_field_method_map();
		foreach ( $fields as $field => $handler ) {
			$method    = sanitize_key( $handler['method'] );
			$callbacks = $handler['callbacks'] ?? '';
			if ( isset( $meta[ $field ] ) && method_exists( $this->wc_product, $method ) ) {
				$this->wc_product->{$method}( $this->handle_callbacks( $meta[ $field ], $callbacks ) );
			}
		}
	}

	/**
	 * Updates a product's meta fields that were set but not handled by a WC_Product method.
	 *
	 * For example, post meta like _dfrps_* fields such as _dfrps_product and _dfrps_product_check_image.
	 *
	 * @param array $meta
	 */
	private function update_unhandled_meta_fields( array $meta ) {
		$unhandled_meta_fields = array_diff_key( $meta, $this->get_wp_postmeta_wc_product_field_method_map() );
		if ( ! empty( $unhandled_meta_fields ) ) {
			array_map( function ( $key, $value ) {
				$value = is_string( $value ) ? sanitize_text_field( $value ) : $value;
				$this->wc_product->update_meta_data( sanitize_key( $key ), $value );
			}, array_keys( $unhandled_meta_fields ), $unhandled_meta_fields );
		}
	}

	public function update_taxonomies() {

		if ( ! $this->post_is_valid() ) {
			return;
		}

		$category_ids = dfrps_get_cpt_terms( $this->product_set['ID'] );
		$category_ids = apply_filters( 'dfrpswc_product_cat_category_ids', $category_ids, $this );

		$taxonomies = apply_filters(
			'dfrpswc_filter_taxonomy_array',
			[ DFRPSWC_TAXONOMY => array_map( 'absint', array_filter( array_unique( $category_ids ) ) ) ],
			$this->post,
			$this->dfr_product,
			$this->product_set,
			$this->action
		);

		$taxonomies = array_filter( $taxonomies );

		if ( isset( $taxonomies[ DFRPSWC_TAXONOMY ] ) ) {
			$this->wc_product->set_category_ids( $taxonomies[ DFRPSWC_TAXONOMY ] );
		}

		$this->update_unhandled_taxonomies( $taxonomies );
	}

	// Handle unhandled taxonomies
	private function update_unhandled_taxonomies( array $taxonomies ) {
		unset( $taxonomies[ DFRPSWC_TAXONOMY ] );

		foreach ( $taxonomies as $taxonomy => $terms ) {
			wp_set_post_terms( $this->post['ID'], $terms, $taxonomy, false );
		}
	}

	public function update_attributes() {

		if ( ! $this->post_is_valid() ) {
			return;
		}

		$attributes = array_filter( array_merge(
			array_filter( $this->get_global_attributes() ),
			array_filter( $this->get_custom_attributes() )
		) );

		$this->wc_product->set_attributes( $attributes );
	}

	private function get_global_attributes() {

		$position   = 0;
		$attributes = [];

		foreach ( wc_get_attribute_taxonomies() as $attribute_taxonomy ) {

			// Taxonomy slug (ie. pa_brand)
			$attribute_taxonomy_name = wc_attribute_taxonomy_name( $attribute_taxonomy->attribute_name );
			$attribute_taxonomy_id   = $attribute_taxonomy->attribute_id;

			// Get terms of this taxonomy associated with current product
			$post_terms = wp_get_post_terms( $this->post['ID'], $attribute_taxonomy_name );

			$value = [];
			if ( ! is_wp_error( $post_terms ) ) {
				foreach ( $post_terms as $term ) {
					$value[] = $term->slug;
				}
			}

			$value     = $this->filter_attribute_value( $value, $attribute_taxonomy_name );
			$visible   = $this->filter_attribute_visibility( true, $attribute_taxonomy_name );
			$variation = $this->filter_attribute_variation( false, $attribute_taxonomy_name );

			$attributes[] = $this->get_wc_product_attribute( [
				'id'        => $attribute_taxonomy_id,
				'name'      => $attribute_taxonomy_name,
				'options'   => $value,
				'position'  => $position,
				'visible'   => $visible,
				'variation' => $variation,
			] );

			$position ++;
		}

		return $attributes;
	}

	private function get_custom_attributes() {

		$position   = 0;
		$attributes = [];

		/**
		 * This allows the user to add more custom attributes to the product.
		 */
		$product_attributes = apply_filters(
			'dfrpswc_product_attributes',
			maybe_unserialize( get_post_meta( $this->post['ID'], '_product_attributes', true ) ),
			$this->post,
			$this->dfr_product,
			$this->product_set,
			$this->action
		);

		if ( empty( $product_attributes ) ) {
			return $attributes;
		}

		foreach ( $product_attributes as $product_attribute ) {

			if ( isset( $product_attribute['is_taxonomy'] ) && boolval( $product_attribute['is_taxonomy'] ) ) {
				continue;
			}

			if ( ! isset( $product_attribute['name'] ) ) {
				continue;
			}

			$name = $product_attribute['name'];

			$value     = $this->filter_attribute_value( $product_attribute['value'] ?? '', $name );
			$visible   = $this->filter_attribute_visibility( true, $name );
			$variation = $this->filter_attribute_variation( false, $name );

			$attributes[] = $this->get_wc_product_attribute( [
				'name'      => $name,
				'options'   => $value,
				'position'  => $position,
				'visible'   => $visible,
				'variation' => $variation,
			] );

			$position ++;
		}

		return $attributes;
	}

	private function filter_attribute_value( $value, $name ) {
		return apply_filters(
			'dfrpswc_filter_attribute_value',
			$value, $name, $this->post, $this->dfr_product, $this->product_set, $this->action
		);
	}

	private function filter_attribute_visibility( $value, $name ) {
		return boolval( apply_filters(
			'dfrpswc_filter_attribute_visibility',
			$value, $name, $this->post, $this->dfr_product, $this->product_set, $this->action
		) );
	}

	private function filter_attribute_variation( $value, $name ) {
		return boolval( apply_filters(
			'dfrpswc_filter_attribute_variation',
			$value, $name, $this->post, $this->dfr_product, $this->product_set, $this->action
		) );
	}

	/**
	 * @param array $attribute
	 *
	 * @return null|WC_Product_Attribute
	 */
	private function get_wc_product_attribute( array $attribute ) {

		// ID of Product Attribute taxonomy (ie. ID of pa_brand)
		$attribute_id = absint( $attribute['id'] ?? 0 );

		// Check ID for global attributes or name for product attributes.
		$attribute_name = $attribute_id > 0
			? wc_attribute_taxonomy_name_by_id( $attribute_id )
			: wc_clean( $attribute['name'] ?? '' );

		// Skip if there is no attribute ID AND no attribute name.
		if ( ! $attribute_id && ! $attribute_name ) {
			return null;
		}

		// Skip if there is no attribute ID OR options is not set.
		if ( ! $attribute_id && ! isset( $attribute['options'] ) ) {
			return null;
		}

		$values    = null;
		$position  = absint( $attribute['position'] ?? 0 );
		$visible   = boolval( isset( $attribute['visible'] ) && $attribute['visible'] );
		$variation = boolval( isset( $attribute['variation'] ) && $attribute['variation'] );
		$options   = is_array( $attribute['options'] )
			? $attribute['options']
			: $this->explode_on_wc_delimiter( $attribute['options'] );

		// Set $values based on either Global or Custom Attributes
		$values = $attribute_id > 0
			? array_filter( array_map( 'wc_sanitize_term_text_based', $options ), 'strlen' )
			: $options;

		if ( empty( $values ) ) {
			return null;
		}

		$attribute_object = new WC_Product_Attribute();

		if ( $attribute_id > 0 ) {
			$attribute_object->set_id( $attribute_id );
		}

		$attribute_object->set_name( $attribute_name );
		$attribute_object->set_options( $values );
		$attribute_object->set_position( $position );
		$attribute_object->set_visible( $visible );
		$attribute_object->set_variation( $variation );

		do_action( 'dfrpswc_set_attribute', $attribute_object, $this );

		return $attribute_object;
	}

	private function explode_on_wc_delimiter( string $items ) {
		return explode( WC_DELIMITER, $items );
	}

	private function get_wp_post_wc_product_field_method_map() {
		return apply_filters( 'dfrpswc_wp_post_wc_product_field_method_map', [
			'post_title'     => [
				'method'    => 'set_name',
				'callbacks' => 'wp_filter_post_kses',
			],
			'post_content'   => [
				'method'    => 'set_description',
				'callbacks' => 'wp_filter_post_kses',
			],
			'post_excerpt'   => [
				'method'    => 'set_short_description',
				'callbacks' => 'wp_filter_post_kses',
			],
			'post_status'    => [
				'method'    => 'set_status',
				'callbacks' => '',
			],
			'post_date'      => [
				'method'    => 'set_date_created',
				'callbacks' => '',
			],
			'post_modified'  => [
				'method'    => 'set_date_modified',
				'callbacks' => '',
			],
			'comment_status' => [
				'method'    => 'set_reviews_allowed',
				'callbacks' => '',
			],
			'post_password'  => [
				'method'    => 'set_post_password',
				'callbacks' => '',
			],
			'post_name'      => [
				'method'    => 'set_slug',
				'callbacks' => '',
			],
			'post_parent'    => [
				'method'    => 'set_parent_id',
				'callbacks' => '',
			],
			'menu_order'     => [
				'method'    => 'set_menu_order',
				'callbacks' => '',
			],
		] );
	}

	private function get_wp_postmeta_wc_product_field_method_map() {
		return apply_filters( 'dfrpswc_wp_postmeta_wc_product_field_method_map', [
			'_visibility'    => [
				'method'    => 'set_catalog_visibility',
				'callbacks' => '',
			],
			// @todo This needs testing
			'_stock'         => [
				'method'    => 'set_stock_quantity',
				'callbacks' => 'wc_stock_amount',
			],
			'_stock_status'  => [
				'method'    => 'set_stock_status',
				'callbacks' => '',
			],
			'_manage_stock'  => [
				'method'    => 'set_manage_stock',
				'callbacks' => '',
			],
			'_downloadable'  => [
				'method'    => 'set_downloadable',
				'callbacks' => '',
			],
			'_virtual'       => [
				'method'    => 'set_virtual',
				'callbacks' => '',
			],
			'_backorders'    => [
				'method'    => 'set_backorders',
				'callbacks' => '',
			],
			'_product_url'   => [
				'method'    => 'set_product_url',
				'callbacks' => '',
			],
			'_regular_price' => [
				'method'    => 'set_regular_price',
				'callbacks' => [ 'absint', 'dfrps_int_to_price' ],
			],
			'_sale_price'    => [
				'method'    => 'set_sale_price',
				'callbacks' => [ 'absint', 'dfrps_int_to_price' ],
			],
			'_purchase_note' => [
				'method'    => 'set_purchase_note',
				'callbacks' => [ 'wp_unslash', 'wp_kses_post' ],
			],
		] );
	}

	/**
	 * @return int Product ID.
	 */
	public function save_product() {
		return $this->wc_product->save();
	}

	/**
	 * @param mixed $raw_value
	 * @param string|array $callbacks
	 *
	 * @return mixed processed value
	 */
	private function handle_callbacks( $raw_value, $callbacks ) {

		if ( empty( $callbacks ) ) {
			return $raw_value;
		}

		$callbacks = is_string( $callbacks ) ? [ $callbacks ] : $callbacks;
		$callbacks = array_filter( $callbacks );

		if ( empty( $callbacks ) ) {
			return $raw_value;
		}

		$value = $raw_value;

		foreach ( $callbacks as $callback ) {
			if ( is_callable( $callback ) ) {
				$value = $callback( $value );
			}
		}

		return $value;
	}

	/**
	 * Returns true if a $this->post['ID'] is set and is greater than 0. Otherwise returns false.
	 *
	 * @return bool
	 */
	private function post_is_valid() {
		return ( isset( $this->post['ID'] ) && absint( $this->post['ID'] ) > 0 );
	}
}
