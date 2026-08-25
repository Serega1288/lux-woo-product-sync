<?php

defined( 'ABSPATH' ) || exit;

final class LWPS_Crypto {
	const PREFIX = 'lwps:v1:';

	public static function encrypt( $value ) {
		$value = (string) $value;
		if ( '' === $value ) {
			return '';
		}

		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return new WP_Error( 'lwps_openssl_missing', __( 'OpenSSL is required to store REST credentials safely.', 'lux-woo-product-sync' ) );
		}

		$key    = self::key();
		$iv     = random_bytes( 16 );
		$cipher = openssl_encrypt( $value, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
		if ( false === $cipher ) {
			return new WP_Error( 'lwps_encrypt_failed', __( 'Could not encrypt the REST credentials.', 'lux-woo-product-sync' ) );
		}

		$mac = hash_hmac( 'sha256', $iv . $cipher, $key, true );
		return self::PREFIX . base64_encode( $iv . $mac . $cipher );
	}

	public static function decrypt( $value ) {
		$value = (string) $value;
		if ( '' === $value ) {
			return '';
		}

		if ( 0 !== strpos( $value, self::PREFIX ) || ! function_exists( 'openssl_decrypt' ) ) {
			return '';
		}

		$raw = base64_decode( substr( $value, strlen( self::PREFIX ) ), true );
		if ( false === $raw || strlen( $raw ) < 49 ) {
			return '';
		}

		$key      = self::key();
		$iv       = substr( $raw, 0, 16 );
		$mac      = substr( $raw, 16, 32 );
		$cipher   = substr( $raw, 48 );
		$expected = hash_hmac( 'sha256', $iv . $cipher, $key, true );

		if ( ! hash_equals( $expected, $mac ) ) {
			return '';
		}

		$plain = openssl_decrypt( $cipher, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
		return false === $plain ? '' : $plain;
	}

	private static function key() {
		$material = ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' ) . ( defined( 'SECURE_AUTH_KEY' ) ? SECURE_AUTH_KEY : '' );
		return hash( 'sha256', $material . wp_salt( 'auth' ), true );
	}
}

