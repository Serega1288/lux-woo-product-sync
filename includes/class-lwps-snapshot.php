<?php

defined( 'ABSPATH' ) || exit;

final class LWPS_Snapshot {
	public static function manifest( WC_Product $product ) {
		$uid = get_post_meta( $product->get_id(), LWPS_Identity::META_KEY, true );
		if ( ! wp_is_uuid( $uid ) ) {
			return null;
		}

		$core       = self::core( $product );
		$variations = array();

		if ( $product->is_type( 'variable' ) ) {
			foreach ( $product->get_children() as $variation_id ) {
				$variation = wc_get_product( $variation_id );
				if ( ! ( $variation instanceof WC_Product_Variation ) ) {
					continue;
				}

				$variation_uid = get_post_meta( $variation_id, LWPS_Identity::META_KEY, true );
				if ( ! wp_is_uuid( $variation_uid ) ) {
					return null;
				}

				$data = self::variation( $variation );
				$variations[] = array(
					'uid'        => $variation_uid,
					'hash'       => self::hash( $data ),
					'attributes' => $data['attributes'],
				);
			}
		}

		usort(
			$variations,
			static function ( $a, $b ) {
				return strcmp( $a['uid'], $b['uid'] );
			}
		);

		$core_hash = self::hash( $core );
		$full_hash = self::hash(
			array(
				'core'       => $core_hash,
				'variations' => wp_list_pluck( $variations, 'hash', 'uid' ),
			)
		);

		return array(
			'uid'            => $uid,
			'name'           => $product->get_name(),
			'slug'           => $product->get_slug(),
			'type'           => $product->get_type(),
			'status'         => $product->get_status(),
			'modified_at'    => $product->get_date_modified() ? $product->get_date_modified()->date( DATE_ATOM ) : '',
			'core_hash'      => $core_hash,
			'full_hash'      => $full_hash,
			'variation_count' => count( $variations ),
			'variations'     => $variations,
		);
	}

	public static function full( WC_Product $product ) {
		$manifest = self::manifest( $product );
		if ( ! $manifest ) {
			return null;
		}

		$variations = array();
		if ( $product->is_type( 'variable' ) ) {
			foreach ( $product->get_children() as $variation_id ) {
				$variation = wc_get_product( $variation_id );
				if ( $variation instanceof WC_Product_Variation ) {
					$variations[] = self::variation( $variation );
				}
			}
		}

		return array(
			'manifest'   => $manifest,
			'product'    => self::core( $product ),
			'variations' => $variations,
		);
	}

	public static function local_hash( WC_Product $product ) {
		$manifest = self::manifest( $product );
		return $manifest ? $manifest['full_hash'] : '';
	}

	public static function core( WC_Product $product ) {
		$data = array(
			'uid'                => get_post_meta( $product->get_id(), LWPS_Identity::META_KEY, true ),
			'type'               => $product->get_type(),
			'name'               => $product->get_name(),
			'slug'               => $product->get_slug(),
			'status'             => $product->get_status(),
			'description'        => $product->get_description(),
			'short_description'  => $product->get_short_description(),
			'featured'           => $product->get_featured(),
			'catalog_visibility' => $product->get_catalog_visibility(),
			'regular_price'      => $product->get_regular_price(),
			'sale_price'         => $product->get_sale_price(),
			'date_on_sale_from'  => self::date_value( $product->get_date_on_sale_from() ),
			'date_on_sale_to'    => self::date_value( $product->get_date_on_sale_to() ),
			'tax_status'         => $product->get_tax_status(),
			'tax_class'          => $product->get_tax_class(),
			'manage_stock'       => $product->get_manage_stock(),
			'stock_quantity'     => $product->get_stock_quantity(),
			'stock_status'       => $product->get_stock_status(),
			'backorders'         => $product->get_backorders(),
			'sold_individually'  => $product->get_sold_individually(),
			'weight'             => $product->get_weight(),
			'dimensions'         => array(
				'length' => $product->get_length(),
				'width'  => $product->get_width(),
				'height' => $product->get_height(),
			),
			'shipping_class'     => $product->get_shipping_class(),
			'purchase_note'      => $product->get_purchase_note(),
			'menu_order'         => $product->get_menu_order(),
			'virtual'            => $product->get_virtual(),
			'downloadable'       => $product->get_downloadable(),
			'reviews_allowed'    => $product->get_reviews_allowed(),
			'categories'         => self::terms( $product->get_id(), 'product_cat' ),
			'tags'               => self::terms( $product->get_id(), 'product_tag' ),
			'custom_taxonomies'  => self::custom_taxonomies( $product->get_id() ),
			'images'             => self::images( $product ),
			'attributes'         => self::attributes( $product ),
			'default_attributes' => $product->get_default_attributes(),
			'meta'               => self::allowed_meta( $product->get_id(), 'product' ),
		);

		return apply_filters( 'lwps_product_snapshot', $data, $product );
	}

	public static function variation( WC_Product_Variation $variation ) {
		$data = array(
			'uid'               => get_post_meta( $variation->get_id(), LWPS_Identity::META_KEY, true ),
			'status'            => $variation->get_status(),
			'description'       => $variation->get_description(),
			'regular_price'     => $variation->get_regular_price(),
			'sale_price'        => $variation->get_sale_price(),
			'date_on_sale_from' => self::date_value( $variation->get_date_on_sale_from() ),
			'date_on_sale_to'   => self::date_value( $variation->get_date_on_sale_to() ),
			'manage_stock'      => $variation->get_manage_stock(),
			'stock_quantity'    => $variation->get_stock_quantity(),
			'stock_status'      => $variation->get_stock_status(),
			'backorders'        => $variation->get_backorders(),
			'weight'            => $variation->get_weight(),
			'dimensions'        => array(
				'length' => $variation->get_length(),
				'width'  => $variation->get_width(),
				'height' => $variation->get_height(),
			),
			'shipping_class'    => $variation->get_shipping_class(),
			'virtual'           => $variation->get_virtual(),
			'downloadable'      => $variation->get_downloadable(),
			'menu_order'        => $variation->get_menu_order(),
			'attributes'        => $variation->get_attributes(),
			'image'             => self::image( $variation->get_image_id() ),
			'meta'              => self::allowed_meta( $variation->get_id(), 'variation' ),
		);

		return apply_filters( 'lwps_variation_snapshot', $data, $variation );
	}

	public static function hash( array $data ) {
		$data = self::canonicalize( $data );
		return hash( 'sha256', wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	private static function canonicalize( $value ) {
		if ( is_array( $value ) ) {
			if ( self::is_associative( $value ) ) {
				ksort( $value );
			}
			foreach ( $value as $key => $item ) {
				if ( in_array( $key, array( 'src', 'url' ), true ) && is_string( $item ) ) {
					$path = wp_parse_url( $item, PHP_URL_PATH );
					$value[ $key ] = $path ? wp_basename( $path ) : $item;
				} else {
					$value[ $key ] = self::canonicalize( $item );
				}
			}
		}
		return $value;
	}

	private static function is_associative( array $array ) {
		return array_keys( $array ) !== range( 0, count( $array ) - 1 );
	}

	private static function date_value( $date ) {
		return $date instanceof WC_DateTime ? $date->date( DATE_ATOM ) : '';
	}

	private static function terms( $product_id, $taxonomy ) {
		$terms = wp_get_post_terms( $product_id, $taxonomy );
		if ( is_wp_error( $terms ) ) {
			return array();
		}

		$data = array();
		foreach ( $terms as $term ) {
			$parent_slug = '';
			$parent_name = '';
			if ( $term->parent ) {
				$parent = get_term( $term->parent, $taxonomy );
				$parent_slug = $parent && ! is_wp_error( $parent ) ? $parent->slug : '';
				$parent_name = $parent && ! is_wp_error( $parent ) ? $parent->name : '';
			}
			$data[] = array(
				'name'        => $term->name,
				'slug'        => $term->slug,
				'parent_slug' => $parent_slug,
				'parent_name' => $parent_name,
			);
		}
		usort( $data, static function ( $a, $b ) { return strcmp( $a['slug'], $b['slug'] ); } );
		return $data;
	}

	private static function custom_taxonomies( $product_id ) {
		$excluded = array( 'product_cat', 'product_tag', 'product_shipping_class', 'product_type', 'product_visibility' );
		$taxonomies = get_object_taxonomies( 'product', 'objects' );
		$taxonomies = apply_filters( 'lwps_synced_product_taxonomies', $taxonomies, $product_id );
		$data = array();

		foreach ( $taxonomies as $taxonomy => $object ) {
			if ( in_array( $taxonomy, $excluded, true ) || 0 === strpos( $taxonomy, 'pa_' ) ) {
				continue;
			}

			$terms = self::terms( $product_id, $taxonomy );
			if ( ! $terms ) {
				continue;
			}

			$data[] = array(
				'taxonomy'     => sanitize_key( $taxonomy ),
				'label'        => isset( $object->label ) ? (string) $object->label : $taxonomy,
				'hierarchical' => ! empty( $object->hierarchical ),
				'terms'        => $terms,
			);
		}

		usort( $data, static function ( $a, $b ) { return strcmp( $a['taxonomy'], $b['taxonomy'] ); } );
		return $data;
	}

	private static function images( WC_Product $product ) {
		$ids = array_filter( array_merge( array( $product->get_image_id() ), $product->get_gallery_image_ids() ) );
		$data = array();
		foreach ( array_values( array_unique( $ids ) ) as $position => $image_id ) {
			$image = self::image( $image_id );
			if ( $image ) {
				$image['position'] = $position;
				$data[] = $image;
			}
		}
		return $data;
	}

	private static function image( $image_id ) {
		if ( ! $image_id ) {
			return array();
		}

		$source = get_post_meta( $image_id, '_lwps_source_image_url', true );
		$src    = $source ? $source : wp_get_attachment_url( $image_id );
		if ( ! $src ) {
			return array();
		}

		return array(
			'src'  => $src,
			'alt'  => get_post_meta( $image_id, '_wp_attachment_image_alt', true ),
			'name' => get_the_title( $image_id ),
		);
	}

	private static function attributes( WC_Product $product ) {
		$data = array();
		foreach ( $product->get_attributes() as $attribute ) {
			if ( ! ( $attribute instanceof WC_Product_Attribute ) ) {
				continue;
			}

			$options = array();
			if ( $attribute->is_taxonomy() ) {
				foreach ( $attribute->get_terms() as $term ) {
					$options[] = array( 'name' => $term->name, 'slug' => $term->slug );
				}
			} else {
				foreach ( $attribute->get_options() as $option ) {
					$options[] = array( 'name' => (string) $option, 'slug' => sanitize_title( $option ) );
				}
			}
			usort( $options, static function ( $a, $b ) { return strcmp( $a['slug'], $b['slug'] ); } );

			$data[] = array(
				'name'      => $attribute->get_name(),
				'label'     => wc_attribute_label( $attribute->get_name(), $product ),
				'taxonomy'  => $attribute->is_taxonomy(),
				'position'  => $attribute->get_position(),
				'visible'   => $attribute->get_visible(),
				'variation' => $attribute->get_variation(),
				'options'   => $options,
			);
		}
		usort( $data, static function ( $a, $b ) { return $a['position'] === $b['position'] ? strcmp( $a['name'], $b['name'] ) : $a['position'] - $b['position']; } );
		return $data;
	}

	private static function allowed_meta( $post_id, $context ) {
		$defaults = 'variation' === $context
			? array( '_variation_custom_label', '_variation_discount_percent', '_lux_variation_flag' )
			: array( 'style', 'style_custom', 'logo_background_page_product', 'opacity', 'background_size_page_product', 'background_position_horizont_page_product', 'background_position_vertical_page_product' );
		$keys = apply_filters( 'lwps_synced_meta_keys', $defaults, $context, $post_id );
		$data = array();
		foreach ( array_unique( array_map( 'sanitize_key', (array) $keys ) ) as $key ) {
			if ( '' !== $key && LWPS_Identity::META_KEY !== $key && metadata_exists( 'post', $post_id, $key ) ) {
				$data[ $key ] = get_post_meta( $post_id, $key, true );
			}
		}
		return $data;
	}
}
