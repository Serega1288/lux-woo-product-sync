<?php

defined( 'ABSPATH' ) || exit;

final class LWPS_WC_Adapter {
	private $source_url;
	private $categories;

	public function __construct( $source_url, array $categories = array() ) {
		$this->source_url = untrailingslashit( (string) $source_url );
		$this->categories = $categories;
	}

	public function full( array $product, array $variations = array() ) {
		if ( empty( $product['id'] ) ) {
			return new WP_Error( 'lwps_invalid_product', __( 'The donor returned a product without an ID.', 'lux-woo-product-sync' ) );
		}

		$core       = $this->product( $product );
		$normalized = array();
		foreach ( $variations as $variation ) {
			if ( ! empty( $variation['id'] ) ) {
				$normalized[] = $this->variation( $variation );
			}
		}

		$manifest_variations = array();
		foreach ( $normalized as $variation ) {
			$manifest_variations[] = array(
				'uid'        => $variation['uid'],
				'remote_id'  => $variation['remote_id'],
				'hash'       => LWPS_Snapshot::hash( $this->without_remote_id( $variation ) ),
				'attributes' => $variation['attributes'],
			);
		}
		usort( $manifest_variations, static function ( $a, $b ) { return strcmp( $a['uid'], $b['uid'] ); } );

		$core_hash = LWPS_Snapshot::hash( $core );
		$full_hash = LWPS_Snapshot::hash(
			array(
				'core'       => $core_hash,
				'variations' => wp_list_pluck( $manifest_variations, 'hash', 'uid' ),
			)
		);

		return array(
			'manifest' => array(
				'uid'             => $core['uid'],
				'remote_id'       => (int) $product['id'],
				'name'            => $core['name'],
				'slug'            => $core['slug'],
				'type'            => $core['type'],
				'status'          => $core['status'],
				'modified_at'     => isset( $product['date_modified'] ) ? sanitize_text_field( $product['date_modified'] ) : '',
				'core_hash'       => $core_hash,
				'full_hash'       => $full_hash,
				'variation_count' => count( $manifest_variations ),
				'variations'      => $manifest_variations,
			),
			'product'    => $core,
			'variations' => array_map( array( $this, 'without_remote_id' ), $normalized ),
		);
	}

	public function without_remote_id( array $data ) {
		unset( $data['remote_id'] );
		return $data;
	}

	private function product( array $data ) {
		return array(
			'uid'                => LWPS_Identity::for_remote( $this->source_url, 'product', $data['id'] ),
			'type'               => isset( $data['type'] ) ? sanitize_key( $data['type'] ) : 'simple',
			'name'               => isset( $data['name'] ) ? (string) $data['name'] : '',
			'slug'               => isset( $data['slug'] ) ? (string) $data['slug'] : '',
			'status'             => isset( $data['status'] ) ? sanitize_key( $data['status'] ) : 'draft',
			'description'        => isset( $data['description'] ) ? (string) $data['description'] : '',
			'short_description'  => isset( $data['short_description'] ) ? (string) $data['short_description'] : '',
			'featured'           => ! empty( $data['featured'] ),
			'catalog_visibility' => isset( $data['catalog_visibility'] ) ? (string) $data['catalog_visibility'] : 'visible',
			'regular_price'      => isset( $data['regular_price'] ) ? (string) $data['regular_price'] : '',
			'sale_price'         => isset( $data['sale_price'] ) ? (string) $data['sale_price'] : '',
			'date_on_sale_from'  => $this->date( isset( $data['date_on_sale_from'] ) ? $data['date_on_sale_from'] : '' ),
			'date_on_sale_to'    => $this->date( isset( $data['date_on_sale_to'] ) ? $data['date_on_sale_to'] : '' ),
			'tax_status'         => isset( $data['tax_status'] ) ? (string) $data['tax_status'] : 'taxable',
			'tax_class'          => isset( $data['tax_class'] ) ? (string) $data['tax_class'] : '',
			'manage_stock'       => ! empty( $data['manage_stock'] ),
			'stock_quantity'     => array_key_exists( 'stock_quantity', $data ) ? $data['stock_quantity'] : null,
			'stock_status'       => isset( $data['stock_status'] ) ? (string) $data['stock_status'] : 'instock',
			'backorders'         => isset( $data['backorders'] ) ? (string) $data['backorders'] : 'no',
			'sold_individually'  => ! empty( $data['sold_individually'] ),
			'weight'             => isset( $data['weight'] ) ? (string) $data['weight'] : '',
			'dimensions'         => $this->dimensions( isset( $data['dimensions'] ) ? $data['dimensions'] : array() ),
			'shipping_class'     => isset( $data['shipping_class'] ) ? (string) $data['shipping_class'] : '',
			'purchase_note'      => isset( $data['purchase_note'] ) ? (string) $data['purchase_note'] : '',
			'menu_order'         => isset( $data['menu_order'] ) ? (int) $data['menu_order'] : 0,
			'virtual'            => ! empty( $data['virtual'] ),
			'downloadable'       => ! empty( $data['downloadable'] ),
			'reviews_allowed'    => ! empty( $data['reviews_allowed'] ),
			'categories'         => $this->terms( isset( $data['categories'] ) ? $data['categories'] : array(), true ),
			'tags'               => $this->terms( isset( $data['tags'] ) ? $data['tags'] : array(), false ),
			'custom_taxonomies'  => array(),
			'images'             => $this->images( isset( $data['images'] ) ? $data['images'] : array() ),
			'attributes'         => $this->attributes( isset( $data['attributes'] ) ? $data['attributes'] : array() ),
			'default_attributes' => $this->default_attributes( isset( $data['default_attributes'] ) ? $data['default_attributes'] : array() ),
			'meta'               => $this->allowed_meta( isset( $data['meta_data'] ) ? $data['meta_data'] : array(), 'product', (int) $data['id'] ),
		);
	}

	private function variation( array $data ) {
		$attributes = array();
		foreach ( isset( $data['attributes'] ) && is_array( $data['attributes'] ) ? $data['attributes'] : array() as $attribute ) {
			$key = ! empty( $attribute['slug'] ) ? $attribute['slug'] : ( isset( $attribute['name'] ) ? $attribute['name'] : '' );
			if ( '' !== $key ) {
				$attributes[ sanitize_title( $key ) ] = 0 === strpos( sanitize_title( $key ), 'pa_' ) ? sanitize_title( isset( $attribute['option'] ) ? $attribute['option'] : '' ) : ( isset( $attribute['option'] ) ? (string) $attribute['option'] : '' );
			}
		}
		ksort( $attributes );

		return array(
			'uid'               => LWPS_Identity::for_remote( $this->source_url, 'variation', $data['id'] ),
			'remote_id'         => (int) $data['id'],
			'status'            => isset( $data['status'] ) ? sanitize_key( $data['status'] ) : 'publish',
			'description'       => isset( $data['description'] ) ? (string) $data['description'] : '',
			'regular_price'     => isset( $data['regular_price'] ) ? (string) $data['regular_price'] : '',
			'sale_price'        => isset( $data['sale_price'] ) ? (string) $data['sale_price'] : '',
			'date_on_sale_from' => $this->date( isset( $data['date_on_sale_from'] ) ? $data['date_on_sale_from'] : '' ),
			'date_on_sale_to'   => $this->date( isset( $data['date_on_sale_to'] ) ? $data['date_on_sale_to'] : '' ),
			'manage_stock'      => ! empty( $data['manage_stock'] ),
			'stock_quantity'    => array_key_exists( 'stock_quantity', $data ) ? $data['stock_quantity'] : null,
			'stock_status'      => isset( $data['stock_status'] ) ? (string) $data['stock_status'] : 'instock',
			'backorders'        => isset( $data['backorders'] ) ? (string) $data['backorders'] : 'no',
			'weight'            => isset( $data['weight'] ) ? (string) $data['weight'] : '',
			'dimensions'        => $this->dimensions( isset( $data['dimensions'] ) ? $data['dimensions'] : array() ),
			'shipping_class'    => isset( $data['shipping_class'] ) ? (string) $data['shipping_class'] : '',
			'virtual'           => ! empty( $data['virtual'] ),
			'downloadable'      => ! empty( $data['downloadable'] ),
			'menu_order'        => isset( $data['menu_order'] ) ? (int) $data['menu_order'] : 0,
			'attributes'        => $attributes,
			'image'             => $this->image( isset( $data['image'] ) ? $data['image'] : array() ),
			'meta'              => $this->allowed_meta( isset( $data['meta_data'] ) ? $data['meta_data'] : array(), 'variation', (int) $data['id'] ),
		);
	}

	private function attributes( array $rows ) {
		$data = array();
		foreach ( $rows as $row ) {
			$taxonomy = ! empty( $row['id'] ) || ( ! empty( $row['slug'] ) && 0 === strpos( $row['slug'], 'pa_' ) );
			$name     = $taxonomy && ! empty( $row['slug'] ) ? sanitize_title( $row['slug'] ) : ( isset( $row['name'] ) ? (string) $row['name'] : '' );
			$options  = array();
			foreach ( isset( $row['options'] ) && is_array( $row['options'] ) ? $row['options'] : array() as $option ) {
				$options[] = array( 'name' => (string) $option, 'slug' => sanitize_title( $option ) );
			}
			usort( $options, static function ( $a, $b ) { return strcmp( $a['slug'], $b['slug'] ); } );
			$data[] = array(
				'name'      => $name,
				'label'     => isset( $row['name'] ) ? (string) $row['name'] : $name,
				'taxonomy'  => $taxonomy,
				'position'  => isset( $row['position'] ) ? (int) $row['position'] : 0,
				'visible'   => ! empty( $row['visible'] ),
				'variation' => ! empty( $row['variation'] ),
				'options'   => $options,
			);
		}
		usort( $data, static function ( $a, $b ) { return $a['position'] === $b['position'] ? strcmp( $a['name'], $b['name'] ) : $a['position'] - $b['position']; } );
		return $data;
	}

	private function default_attributes( array $rows ) {
		$data = array();
		foreach ( $rows as $row ) {
			$key = ! empty( $row['slug'] ) ? $row['slug'] : ( isset( $row['name'] ) ? $row['name'] : '' );
			if ( '' !== $key ) {
				$key = sanitize_title( $key );
				$data[ $key ] = 0 === strpos( $key, 'pa_' ) ? sanitize_title( isset( $row['option'] ) ? $row['option'] : '' ) : ( isset( $row['option'] ) ? (string) $row['option'] : '' );
			}
		}
		ksort( $data );
		return $data;
	}

	private function terms( array $rows, $hierarchical ) {
		$data = array();
		foreach ( $rows as $row ) {
			$id = isset( $row['id'] ) ? (int) $row['id'] : 0;
			$data[] = array(
				'name'        => isset( $row['name'] ) ? (string) $row['name'] : '',
				'slug'        => isset( $row['slug'] ) ? (string) $row['slug'] : '',
				'parent_slug' => $hierarchical && $id && isset( $this->categories[ $id ]['parent_slug'] ) ? $this->categories[ $id ]['parent_slug'] : '',
				'parent_name' => $hierarchical && $id && isset( $this->categories[ $id ]['parent_name'] ) ? $this->categories[ $id ]['parent_name'] : '',
			);
		}
		usort( $data, static function ( $a, $b ) { return strcmp( $a['slug'], $b['slug'] ); } );
		return $data;
	}

	private function images( array $rows ) {
		$data = array();
		foreach ( $rows as $position => $row ) {
			$image = $this->image( $row );
			if ( $image ) {
				$image['position'] = (int) $position;
				$data[] = $image;
			}
		}
		return $data;
	}

	private function image( array $row ) {
		if ( empty( $row['src'] ) ) {
			return array();
		}
		return array(
			'src'  => esc_url_raw( $row['src'] ),
			'alt'  => isset( $row['alt'] ) ? (string) $row['alt'] : '',
			'name' => isset( $row['name'] ) ? (string) $row['name'] : '',
		);
	}

	private function dimensions( array $data ) {
		return array(
			'length' => isset( $data['length'] ) ? (string) $data['length'] : '',
			'width'  => isset( $data['width'] ) ? (string) $data['width'] : '',
			'height' => isset( $data['height'] ) ? (string) $data['height'] : '',
		);
	}

	private function date( $value ) {
		if ( empty( $value ) ) {
			return '';
		}
		try {
			return wc_string_to_datetime( $value )->date( DATE_ATOM );
		} catch ( Exception $error ) {
			return '';
		}
	}

	private function allowed_meta( array $rows, $context, $remote_id ) {
		$defaults = 'variation' === $context
			? array( '_variation_custom_label', '_variation_discount_percent', '_lux_variation_flag' )
			: array( 'style', 'style_custom', 'logo_background_page_product', 'opacity', 'background_size_page_product', 'background_position_horizont_page_product', 'background_position_vertical_page_product' );
		$keys = apply_filters( 'lwps_synced_meta_keys', $defaults, $context, $remote_id );
		$keys = array_unique( array_map( 'sanitize_key', (array) $keys ) );
		$data = array();
		foreach ( $rows as $row ) {
			$key = isset( $row['key'] ) ? sanitize_key( $row['key'] ) : '';
			if ( '' !== $key && in_array( $key, $keys, true ) && LWPS_Identity::META_KEY !== $key ) {
				$data[ $key ] = isset( $row['value'] ) ? $row['value'] : '';
			}
		}
		ksort( $data );
		return $data;
	}
}
