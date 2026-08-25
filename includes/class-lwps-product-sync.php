<?php

defined( 'ABSPATH' ) || exit;

final class LWPS_Product_Sync {
	const OPERATIONS = array( 'import', 'update_main', 'update_variations', 'add_variations', 'overwrite' );

	public static function execute( $remote_uid, $operation, array $options = array() ) {
		$remote_uid = LWPS_Identity::sanitize_uid( $remote_uid );
		$operation  = sanitize_key( $operation );
		if ( ! $remote_uid || ! in_array( $operation, self::OPERATIONS, true ) ) {
			return new WP_Error( 'lwps_invalid_operation', __( 'Invalid synchronization operation.', 'lux-woo-product-sync' ) );
		}

		$client = new LWPS_Api_Client();
		$remote = $client->product_payload( $remote_uid, isset( $options['remote_id'] ) ? absint( $options['remote_id'] ) : 0 );
		if ( is_wp_error( $remote ) ) {
			return $remote;
		}
		if ( empty( $remote['manifest'] ) || empty( $remote['product'] ) ) {
			return new WP_Error( 'lwps_invalid_product', __( 'The donor product payload is incomplete.', 'lux-woo-product-sync' ) );
		}

		$product_id = LWPS_Identity::find( $remote_uid, 'product' );
		$is_new     = ! $product_id;
		if ( $is_new && ! in_array( $operation, array( 'import', 'overwrite' ), true ) ) {
			return new WP_Error( 'lwps_product_missing', __( 'The product must be imported before it can be updated.', 'lux-woo-product-sync' ) );
		}
		if ( $product_id && 'yes' === get_post_meta( $product_id, '_lwps_local_lock', true ) && empty( $options['force_locked'] ) ) {
			return new WP_Error( 'lwps_product_locked', __( 'The product is protected from synchronization.', 'lux-woo-product-sync' ) );
		}

		try {
			$product = $product_id ? wc_get_product( $product_id ) : false;
			if ( ! $product || ( 'overwrite' === $operation && $product->get_type() !== $remote['product']['type'] ) ) {
				$product = self::product_for_type( $remote['product']['type'], $product_id );
			}

			if ( in_array( $operation, array( 'import', 'update_main', 'overwrite' ), true ) ) {
				self::apply_main( $product, $remote['product'] );
				$product_id = $product->save();
				LWPS_Identity::assign( $product_id, $remote_uid );
			}

			if ( in_array( $operation, array( 'update_variations', 'add_variations' ), true ) && ! ( $product instanceof WC_Product_Variable ) && 'variable' === $remote['product']['type'] ) {
				return new WP_Error( 'lwps_product_type_mismatch', __( 'Use full overwrite to convert the local product to a variable product.', 'lux-woo-product-sync' ) );
			}

			if ( in_array( $operation, array( 'import', 'update_variations', 'add_variations', 'overwrite' ), true ) ) {
				if ( in_array( $operation, array( 'update_variations', 'add_variations' ), true ) && $product instanceof WC_Product_Variable ) {
					$product->set_attributes( self::attributes( isset( $remote['product']['attributes'] ) ? $remote['product']['attributes'] : array() ) );
					$product->set_default_attributes( isset( $remote['product']['default_attributes'] ) && is_array( $remote['product']['default_attributes'] ) ? $remote['product']['default_attributes'] : array() );
					$product->save();
				}
				self::apply_variations( $product, isset( $remote['variations'] ) ? $remote['variations'] : array(), $operation, $options );
			}

			$product = wc_get_product( $product_id );
			if ( $product ) {
				if ( $product instanceof WC_Product_Variable ) {
					WC_Product_Variable::sync( $product_id );
					$product = wc_get_product( $product_id );
				}
				wc_delete_product_transients( $product_id );
				$local_hash = LWPS_Snapshot::local_hash( $product );
				update_post_meta( $product_id, '_lwps_last_local_hash', $local_hash );
				update_post_meta( $product_id, '_lwps_last_donor_hash', $remote['manifest']['full_hash'] );
				update_post_meta( $product_id, '_lwps_last_synced_at', current_time( 'mysql', true ) );
				delete_post_meta( $product_id, '_lwps_initial_mismatch' );
			}

			return array(
				'product_id' => $product_id,
				'created'    => $is_new,
				'operation'  => $operation,
				'in_sync'    => isset( $local_hash ) && hash_equals( (string) $remote['manifest']['full_hash'], (string) $local_hash ),
				'edit_url'   => get_edit_post_link( $product_id, 'raw' ),
			);
		} catch ( Throwable $error ) {
			return new WP_Error( 'lwps_sync_failed', $error->getMessage() );
		}
	}

	private static function product_for_type( $type, $product_id = 0 ) {
		$type = sanitize_key( $type );
		if ( $product_id ) {
			wp_set_object_terms( $product_id, $type, 'product_type' );
		}

		switch ( $type ) {
			case 'variable':
				return new WC_Product_Variable( $product_id );
			case 'grouped':
				return new WC_Product_Grouped( $product_id );
			case 'external':
				return new WC_Product_External( $product_id );
			default:
				return new WC_Product_Simple( $product_id );
		}
	}

	private static function apply_main( WC_Product $product, array $data ) {
		$product->set_name( isset( $data['name'] ) ? wp_strip_all_tags( $data['name'] ) : '' );
		$product->set_slug( isset( $data['slug'] ) ? sanitize_title( $data['slug'] ) : '' );
		$product->set_status( isset( $data['status'] ) ? sanitize_key( $data['status'] ) : 'draft' );
		$product->set_description( isset( $data['description'] ) ? wp_kses_post( $data['description'] ) : '' );
		$product->set_short_description( isset( $data['short_description'] ) ? wp_kses_post( $data['short_description'] ) : '' );
		$product->set_featured( ! empty( $data['featured'] ) );
		$product->set_catalog_visibility( isset( $data['catalog_visibility'] ) ? wc_clean( $data['catalog_visibility'] ) : 'visible' );
		$product->set_regular_price( isset( $data['regular_price'] ) ? wc_format_decimal( $data['regular_price'] ) : '' );
		$product->set_sale_price( isset( $data['sale_price'] ) ? wc_format_decimal( $data['sale_price'] ) : '' );
		$product->set_date_on_sale_from( self::date( isset( $data['date_on_sale_from'] ) ? $data['date_on_sale_from'] : '' ) );
		$product->set_date_on_sale_to( self::date( isset( $data['date_on_sale_to'] ) ? $data['date_on_sale_to'] : '' ) );
		$product->set_tax_status( isset( $data['tax_status'] ) ? wc_clean( $data['tax_status'] ) : 'taxable' );
		$product->set_tax_class( isset( $data['tax_class'] ) ? wc_clean( $data['tax_class'] ) : '' );
		$product->set_manage_stock( ! empty( $data['manage_stock'] ) );
		$product->set_stock_quantity( isset( $data['stock_quantity'] ) && null !== $data['stock_quantity'] ? wc_stock_amount( $data['stock_quantity'] ) : null );
		$product->set_stock_status( isset( $data['stock_status'] ) ? wc_clean( $data['stock_status'] ) : 'instock' );
		$product->set_backorders( isset( $data['backorders'] ) ? wc_clean( $data['backorders'] ) : 'no' );
		$product->set_sold_individually( ! empty( $data['sold_individually'] ) );
		$product->set_weight( isset( $data['weight'] ) ? wc_format_decimal( $data['weight'] ) : '' );
		$product->set_length( isset( $data['dimensions']['length'] ) ? wc_format_decimal( $data['dimensions']['length'] ) : '' );
		$product->set_width( isset( $data['dimensions']['width'] ) ? wc_format_decimal( $data['dimensions']['width'] ) : '' );
		$product->set_height( isset( $data['dimensions']['height'] ) ? wc_format_decimal( $data['dimensions']['height'] ) : '' );
		$product->set_purchase_note( isset( $data['purchase_note'] ) ? wp_kses_post( $data['purchase_note'] ) : '' );
		$product->set_menu_order( isset( $data['menu_order'] ) ? (int) $data['menu_order'] : 0 );
		$product->set_virtual( ! empty( $data['virtual'] ) );
		$product->set_downloadable( ! empty( $data['downloadable'] ) );
		$product->set_reviews_allowed( ! empty( $data['reviews_allowed'] ) );

		$product->set_category_ids( self::ensure_terms( 'product_cat', isset( $data['categories'] ) ? $data['categories'] : array() ) );
		$product->set_tag_ids( self::ensure_terms( 'product_tag', isset( $data['tags'] ) ? $data['tags'] : array() ) );
		$product->set_attributes( self::attributes( isset( $data['attributes'] ) ? $data['attributes'] : array() ) );
		$product->set_default_attributes( isset( $data['default_attributes'] ) && is_array( $data['default_attributes'] ) ? $data['default_attributes'] : array() );

		if ( ! empty( $data['shipping_class'] ) ) {
			$shipping_ids = self::ensure_terms(
				'product_shipping_class',
				array( array( 'name' => $data['shipping_class'], 'slug' => sanitize_title( $data['shipping_class'] ) ) )
			);
			$product->set_shipping_class_id( $shipping_ids ? reset( $shipping_ids ) : 0 );
		} else {
			$product->set_shipping_class_id( 0 );
		}

		$image_ids = array();
		foreach ( isset( $data['images'] ) ? $data['images'] : array() as $image ) {
			$image_id = self::sideload_image( $image, $product->get_id() );
			if ( $image_id ) {
				$image_ids[] = $image_id;
			}
		}
		$product->set_image_id( $image_ids ? array_shift( $image_ids ) : 0 );
		$product->set_gallery_image_ids( $image_ids );

		self::apply_meta( $product, isset( $data['meta'] ) ? $data['meta'] : array() );
	}

	private static function apply_variations( WC_Product $product, array $variations, $operation, array $options ) {
		if ( ! ( $product instanceof WC_Product_Variable ) ) {
			return;
		}

		$remote_uids = array();
		foreach ( $variations as $data ) {
			if ( empty( $data['uid'] ) ) {
				continue;
			}
			$remote_uids[] = $data['uid'];
			$variation_id = LWPS_Identity::find( $data['uid'], 'product_variation' );
			$variation    = $variation_id ? wc_get_product( $variation_id ) : false;
			if ( $variation instanceof WC_Product_Variation && (int) $variation->get_parent_id() !== (int) $product->get_id() ) {
				throw new RuntimeException( __( 'A variation UUID is already linked to another product.', 'lux-woo-product-sync' ) );
			}
			if ( 'add_variations' === $operation && $variation ) {
				continue;
			}
			if ( ! $variation ) {
				$variation = new WC_Product_Variation();
				$variation->set_parent_id( $product->get_id() );
			}
			self::apply_variation( $variation, $data, $product->get_id() );
			$variation_id = $variation->save();
			LWPS_Identity::assign( $variation_id, $data['uid'] );
		}

		if ( 'overwrite' === $operation && ! empty( $options['delete_missing_variations'] ) ) {
			foreach ( $product->get_children() as $variation_id ) {
				$uid = get_post_meta( $variation_id, LWPS_Identity::META_KEY, true );
				if ( $uid && ! in_array( $uid, $remote_uids, true ) ) {
					wp_trash_post( $variation_id );
				}
			}
		}
	}

	private static function apply_variation( WC_Product_Variation $variation, array $data, $parent_id ) {
		$variation->set_parent_id( $parent_id );
		$variation->set_status( isset( $data['status'] ) ? wc_clean( $data['status'] ) : 'publish' );
		$variation->set_description( isset( $data['description'] ) ? wp_kses_post( $data['description'] ) : '' );
		$variation->set_regular_price( isset( $data['regular_price'] ) ? wc_format_decimal( $data['regular_price'] ) : '' );
		$variation->set_sale_price( isset( $data['sale_price'] ) ? wc_format_decimal( $data['sale_price'] ) : '' );
		$variation->set_date_on_sale_from( self::date( isset( $data['date_on_sale_from'] ) ? $data['date_on_sale_from'] : '' ) );
		$variation->set_date_on_sale_to( self::date( isset( $data['date_on_sale_to'] ) ? $data['date_on_sale_to'] : '' ) );
		$variation->set_manage_stock( ! empty( $data['manage_stock'] ) );
		$variation->set_stock_quantity( isset( $data['stock_quantity'] ) && null !== $data['stock_quantity'] ? wc_stock_amount( $data['stock_quantity'] ) : null );
		$variation->set_stock_status( isset( $data['stock_status'] ) ? wc_clean( $data['stock_status'] ) : 'instock' );
		$variation->set_backorders( isset( $data['backorders'] ) ? wc_clean( $data['backorders'] ) : 'no' );
		$variation->set_weight( isset( $data['weight'] ) ? wc_format_decimal( $data['weight'] ) : '' );
		$variation->set_length( isset( $data['dimensions']['length'] ) ? wc_format_decimal( $data['dimensions']['length'] ) : '' );
		$variation->set_width( isset( $data['dimensions']['width'] ) ? wc_format_decimal( $data['dimensions']['width'] ) : '' );
		$variation->set_height( isset( $data['dimensions']['height'] ) ? wc_format_decimal( $data['dimensions']['height'] ) : '' );
		$variation->set_virtual( ! empty( $data['virtual'] ) );
		$variation->set_downloadable( ! empty( $data['downloadable'] ) );
		$variation->set_menu_order( isset( $data['menu_order'] ) ? (int) $data['menu_order'] : 0 );
		$variation->set_attributes( isset( $data['attributes'] ) && is_array( $data['attributes'] ) ? array_map( 'wc_clean', $data['attributes'] ) : array() );

		$image_id = self::sideload_image( isset( $data['image'] ) ? $data['image'] : array(), $parent_id );
		$variation->set_image_id( $image_id );
		self::apply_meta( $variation, isset( $data['meta'] ) ? $data['meta'] : array() );
	}

	private static function attributes( array $rows ) {
		$attributes = array();
		foreach ( $rows as $row ) {
			$name      = isset( $row['name'] ) ? wc_clean( $row['name'] ) : '';
			$taxonomy  = ! empty( $row['taxonomy'] );
			$attribute = new WC_Product_Attribute();
			$options   = array();

			if ( $taxonomy ) {
				$taxonomy_name = 0 === strpos( $name, 'pa_' ) ? $name : wc_attribute_taxonomy_name( $name );
				$attribute_id  = self::ensure_attribute_taxonomy( $taxonomy_name, isset( $row['label'] ) ? $row['label'] : $name );
				$attribute->set_id( $attribute_id );
				$attribute->set_name( $taxonomy_name );
				foreach ( isset( $row['options'] ) ? $row['options'] : array() as $option ) {
					$term = term_exists( sanitize_title( $option['slug'] ), $taxonomy_name );
					if ( ! $term ) {
						$term = wp_insert_term( sanitize_text_field( $option['name'] ), $taxonomy_name, array( 'slug' => sanitize_title( $option['slug'] ) ) );
					}
					if ( ! is_wp_error( $term ) ) {
						$options[] = (int) ( is_array( $term ) ? $term['term_id'] : $term );
					}
				}
			} else {
				$attribute->set_id( 0 );
				$attribute->set_name( isset( $row['label'] ) ? sanitize_text_field( $row['label'] ) : $name );
				$options = wp_list_pluck( isset( $row['options'] ) ? $row['options'] : array(), 'name' );
			}

			$attribute->set_options( $options );
			$attribute->set_position( isset( $row['position'] ) ? (int) $row['position'] : 0 );
			$attribute->set_visible( ! empty( $row['visible'] ) );
			$attribute->set_variation( ! empty( $row['variation'] ) );
			$attributes[] = $attribute;
		}
		return $attributes;
	}

	private static function ensure_attribute_taxonomy( $taxonomy_name, $label ) {
		$attribute_id = wc_attribute_taxonomy_id_by_name( $taxonomy_name );
		if ( ! $attribute_id ) {
			$attribute_id = wc_create_attribute(
				array(
					'name'         => sanitize_text_field( $label ),
					'slug'         => preg_replace( '/^pa_/', '', sanitize_title( $taxonomy_name ) ),
					'type'         => 'select',
					'order_by'     => 'menu_order',
					'has_archives' => false,
				)
			);
			if ( is_wp_error( $attribute_id ) ) {
				throw new RuntimeException( $attribute_id->get_error_message() );
			}
			delete_transient( 'wc_attribute_taxonomies' );
		}

		if ( ! taxonomy_exists( $taxonomy_name ) ) {
			register_taxonomy(
				$taxonomy_name,
				array( 'product' ),
				array(
					'hierarchical' => false,
					'show_ui'      => false,
					'query_var'    => true,
					'rewrite'      => false,
				)
			);
		}
		return (int) $attribute_id;
	}

	private static function ensure_terms( $taxonomy, array $rows ) {
		$ids     = array();
		$by_slug = array();
		foreach ( $rows as $row ) {
			$slug = isset( $row['slug'] ) ? sanitize_title( $row['slug'] ) : '';
			$name = isset( $row['name'] ) ? sanitize_text_field( $row['name'] ) : $slug;
			if ( '' === $slug ) {
				continue;
			}

			$by_slug[ $slug ] = $row;
			$term = term_exists( $slug, $taxonomy );
			if ( ! $term ) {
				$term = wp_insert_term( $name, $taxonomy, array( 'slug' => $slug ) );
			}
			if ( ! is_wp_error( $term ) ) {
				$term_id = (int) ( is_array( $term ) ? $term['term_id'] : $term );
				$current = get_term( $term_id, $taxonomy );
				if ( $current && ! is_wp_error( $current ) && $name !== $current->name ) {
					wp_update_term( $term_id, $taxonomy, array( 'name' => $name ) );
				}
				$ids[] = $term_id;
			}
		}

		if ( is_taxonomy_hierarchical( $taxonomy ) ) {
			foreach ( $by_slug as $slug => $row ) {
				if ( empty( $row['parent_slug'] ) ) {
					continue;
				}
				$parent_slug = sanitize_title( $row['parent_slug'] );
				$parent      = term_exists( $parent_slug, $taxonomy );
				if ( ! $parent ) {
					$parent = wp_insert_term( $parent_slug, $taxonomy, array( 'slug' => $parent_slug ) );
				}
				$child = term_exists( $slug, $taxonomy );
				if ( ! is_wp_error( $parent ) && ! is_wp_error( $child ) && $parent && $child ) {
					$parent_id = (int) ( is_array( $parent ) ? $parent['term_id'] : $parent );
					$child_id  = (int) ( is_array( $child ) ? $child['term_id'] : $child );
					wp_update_term( $child_id, $taxonomy, array( 'parent' => $parent_id ) );
				}
			}
		}
		return $ids;
	}

	private static function sideload_image( array $image, $post_id ) {
		if ( empty( $image['src'] ) || ! wp_http_validate_url( $image['src'] ) ) {
			return 0;
		}

		$existing = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => '_lwps_source_image_url',
				'meta_value'     => esc_url_raw( $image['src'] ),
			)
		);
		if ( $existing ) {
			return (int) $existing[0];
		}

		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$image_id = media_sideload_image(
			esc_url_raw( $image['src'] ),
			$post_id,
			isset( $image['name'] ) ? sanitize_text_field( $image['name'] ) : '',
			'id'
		);
		if ( is_wp_error( $image_id ) ) {
			return 0;
		}
		update_post_meta( $image_id, '_lwps_source_image_url', esc_url_raw( $image['src'] ) );
		if ( isset( $image['alt'] ) ) {
			update_post_meta( $image_id, '_wp_attachment_image_alt', sanitize_text_field( $image['alt'] ) );
		}
		return (int) $image_id;
	}

	private static function apply_meta( WC_Data $object, array $meta ) {
		foreach ( $meta as $key => $value ) {
			$key = sanitize_key( $key );
			if ( '' !== $key && LWPS_Identity::META_KEY !== $key ) {
				$object->update_meta_data( $key, $value );
			}
		}
	}

	private static function date( $value ) {
		if ( empty( $value ) ) {
			return null;
		}
		try {
			return wc_string_to_datetime( $value );
		} catch ( Exception $error ) {
			return null;
		}
	}

}
