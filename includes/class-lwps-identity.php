<?php

defined( 'ABSPATH' ) || exit;

final class LWPS_Identity {
	const META_KEY = '_lwps_uid';

	public static function register() {
		register_post_meta(
			'product',
			self::META_KEY,
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => false,
				'sanitize_callback' => array( __CLASS__, 'sanitize_uid' ),
				'auth_callback'     => static function () {
					return current_user_can( 'manage_woocommerce' );
				},
			)
		);

		register_post_meta(
			'product_variation',
			self::META_KEY,
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => false,
				'sanitize_callback' => array( __CLASS__, 'sanitize_uid' ),
				'auth_callback'     => static function () {
					return current_user_can( 'manage_woocommerce' );
				},
			)
		);

		add_action( 'save_post_product', array( __CLASS__, 'ensure_on_save' ), 20, 1 );
		add_action( 'woocommerce_save_product_variation', array( __CLASS__, 'ensure_on_save' ), 20, 1 );
	}

	public static function sanitize_uid( $uid ) {
		$uid = strtolower( sanitize_text_field( $uid ) );
		return wp_is_uuid( $uid ) ? $uid : '';
	}

	public static function ensure_on_save( $post_id ) {
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}
		self::ensure( $post_id );
	}

	public static function ensure( $post_id ) {
		$uid = get_post_meta( $post_id, self::META_KEY, true );
		if ( wp_is_uuid( $uid ) ) {
			return $uid;
		}

		$uid = wp_generate_uuid4();
		update_post_meta( $post_id, self::META_KEY, $uid );
		return $uid;
	}

	public static function for_remote( $source_url, $object_type, $remote_id ) {
		$source_url  = strtolower( untrailingslashit( (string) $source_url ) );
		$object_type = sanitize_key( $object_type );
		$remote_id   = absint( $remote_id );
		if ( '' === $source_url || '' === $object_type || ! $remote_id ) {
			return '';
		}

		// UUID v5 keeps the source identity stable without writing metadata to the donor.
		$namespace = '6ba7b8109dad11d180b400c04fd430c8';
		$hash      = sha1( pack( 'H*', $namespace ) . $source_url . '|' . $object_type . '|' . $remote_id );
		$time_hi   = ( hexdec( substr( $hash, 12, 4 ) ) & 0x0fff ) | 0x5000;
		$clock_seq = ( hexdec( substr( $hash, 16, 4 ) ) & 0x3fff ) | 0x8000;

		return sprintf(
			'%s-%s-%04x-%04x-%s',
			substr( $hash, 0, 8 ),
			substr( $hash, 8, 4 ),
			$time_hi,
			$clock_seq,
			substr( $hash, 20, 12 )
		);
	}

	public static function find( $uid, $post_type = array( 'product', 'product_variation' ) ) {
		$ids = get_posts(
			array(
				'post_type'              => $post_type,
				'post_status'            => 'any',
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_key'               => self::META_KEY,
				'meta_value'             => self::sanitize_uid( $uid ),
			)
		);

		return $ids ? (int) $ids[0] : 0;
	}

	public static function assign( $post_id, $uid ) {
		$uid = self::sanitize_uid( $uid );
		if ( ! $uid ) {
			return new WP_Error( 'lwps_invalid_uid', __( 'Invalid synchronization UUID.', 'lux-woo-product-sync' ) );
		}

		$existing = self::find( $uid );
		if ( $existing && (int) $existing !== (int) $post_id ) {
			return new WP_Error( 'lwps_duplicate_uid', __( 'This synchronization UUID is already assigned to another product.', 'lux-woo-product-sync' ) );
		}

		update_post_meta( $post_id, self::META_KEY, $uid );
		return $uid;
	}
}
