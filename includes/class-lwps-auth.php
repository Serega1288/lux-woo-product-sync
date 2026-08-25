<?php

defined( 'ABSPATH' ) || exit;

final class LWPS_Auth {
	public static function authorize_read( WP_REST_Request $request ) {
		return self::authorize( $request, false );
	}

	public static function authorize_write( WP_REST_Request $request ) {
		return self::authorize( $request, true );
	}

	private static function authorize( WP_REST_Request $request, $write ) {
		if ( current_user_can( 'manage_woocommerce' ) ) {
			return true;
		}

		$header = $request->get_header( 'authorization' );
		if ( ! $header || 0 !== stripos( $header, 'basic ' ) ) {
			return new WP_Error( 'lwps_unauthorized', __( 'WooCommerce REST authentication is required.', 'lux-woo-product-sync' ), array( 'status' => 401 ) );
		}

		$decoded = base64_decode( trim( substr( $header, 6 ) ), true );
		if ( false === $decoded || false === strpos( $decoded, ':' ) ) {
			return new WP_Error( 'lwps_unauthorized', __( 'Invalid REST authentication header.', 'lux-woo-product-sync' ), array( 'status' => 401 ) );
		}

		list( $consumer_key, $consumer_secret ) = explode( ':', $decoded, 2 );
		if ( 0 !== strpos( $consumer_key, 'ck_' ) || 0 !== strpos( $consumer_secret, 'cs_' ) ) {
			return new WP_Error( 'lwps_unauthorized', __( 'Invalid WooCommerce REST credentials.', 'lux-woo-product-sync' ), array( 'status' => 401 ) );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'woocommerce_api_keys';
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT user_id, consumer_secret, permissions FROM {$table} WHERE consumer_key = %s LIMIT 1",
				wc_api_hash( $consumer_key )
			)
		);

		if ( ! $row || ! hash_equals( (string) $row->consumer_secret, (string) $consumer_secret ) ) {
			return new WP_Error( 'lwps_unauthorized', __( 'The WooCommerce REST credentials were rejected.', 'lux-woo-product-sync' ), array( 'status' => 401 ) );
		}

		$allowed = $write ? in_array( $row->permissions, array( 'write', 'read_write' ), true ) : in_array( $row->permissions, array( 'read', 'read_write' ), true );
		if ( ! $allowed || ! user_can( (int) $row->user_id, 'manage_woocommerce' ) ) {
			return new WP_Error( 'lwps_forbidden', __( 'The REST key does not have sufficient permissions.', 'lux-woo-product-sync' ), array( 'status' => 403 ) );
		}

		wp_set_current_user( (int) $row->user_id );
		return true;
	}
}
