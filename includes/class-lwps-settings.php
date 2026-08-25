<?php

defined( 'ABSPATH' ) || exit;

final class LWPS_Settings {
	const OPTION = 'lwps_settings';

	public static function get_public() {
		$settings = get_option( self::OPTION, array() );
		$key      = LWPS_Crypto::decrypt( isset( $settings['consumer_key'] ) ? $settings['consumer_key'] : '' );
		$secret   = LWPS_Crypto::decrypt( isset( $settings['consumer_secret'] ) ? $settings['consumer_secret'] : '' );

		return array(
			'donor_url'          => isset( $settings['donor_url'] ) ? $settings['donor_url'] : '',
			'consumer_key'        => self::mask( $key ),
			'consumer_secret'     => self::mask( $secret ),
			'has_consumer_key'    => '' !== $key,
			'has_consumer_secret' => '' !== $secret,
			'source_mode'         => isset( $settings['source_mode'] ) ? sanitize_key( $settings['source_mode'] ) : 'unknown',
			'updated_at'          => isset( $settings['updated_at'] ) ? $settings['updated_at'] : '',
		);
	}

	public static function get_credentials() {
		$settings = get_option( self::OPTION, array() );
		return array(
			'donor_url'      => isset( $settings['donor_url'] ) ? untrailingslashit( $settings['donor_url'] ) : '',
			'consumer_key'    => LWPS_Crypto::decrypt( isset( $settings['consumer_key'] ) ? $settings['consumer_key'] : '' ),
			'consumer_secret' => LWPS_Crypto::decrypt( isset( $settings['consumer_secret'] ) ? $settings['consumer_secret'] : '' ),
			'source_mode'     => isset( $settings['source_mode'] ) ? sanitize_key( $settings['source_mode'] ) : 'unknown',
		);
	}

	public static function save( array $input ) {
		$current = self::get_credentials();
		$url     = isset( $input['donor_url'] ) ? self::normalize_url( $input['donor_url'] ) : '';
		$key     = isset( $input['consumer_key'] ) ? trim( sanitize_text_field( $input['consumer_key'] ) ) : '';
		$secret  = isset( $input['consumer_secret'] ) ? trim( sanitize_text_field( $input['consumer_secret'] ) ) : '';

		if ( ! wp_http_validate_url( $url ) || ! preg_match( '#^https?://#i', $url ) ) {
			return new WP_Error( 'lwps_invalid_url', __( 'Enter a valid donor site URL.', 'lux-woo-product-sync' ) );
		}
		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		$host   = wp_parse_url( $url, PHP_URL_HOST );
		$environment = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';
		$local_http  = in_array( $environment, array( 'local', 'development' ), true ) && in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true );
		if ( 'https' !== strtolower( (string) $scheme ) && ! $local_http ) {
			return new WP_Error( 'lwps_https_required', __( 'Use HTTPS for the donor connection so REST credentials are not exposed.', 'lux-woo-product-sync' ) );
		}

		if ( '' === $key ) {
			$key = $current['consumer_key'];
		}
		if ( '' === $secret ) {
			$secret = $current['consumer_secret'];
		}

		if ( 0 !== strpos( $key, 'ck_' ) || 0 !== strpos( $secret, 'cs_' ) ) {
			return new WP_Error( 'lwps_invalid_credentials', __( 'WooCommerce REST keys must start with ck_ and cs_.', 'lux-woo-product-sync' ) );
		}

		$encrypted_key    = LWPS_Crypto::encrypt( $key );
		$encrypted_secret = LWPS_Crypto::encrypt( $secret );
		if ( is_wp_error( $encrypted_key ) ) {
			return $encrypted_key;
		}
		if ( is_wp_error( $encrypted_secret ) ) {
			return $encrypted_secret;
		}

		update_option(
			self::OPTION,
			array(
				'donor_url'      => $url,
				'consumer_key'    => $encrypted_key,
				'consumer_secret' => $encrypted_secret,
				'source_mode'     => 'unknown',
				'updated_at'      => current_time( 'mysql', true ),
			),
			false
		);

		return self::get_public();
	}

	public static function set_source_mode( $mode ) {
		$mode = sanitize_key( $mode );
		if ( ! in_array( $mode, array( 'unknown', 'enhanced', 'standard_readonly' ), true ) ) {
			return;
		}
		$settings = get_option( self::OPTION, array() );
		$settings['source_mode'] = $mode;
		update_option( self::OPTION, $settings, false );
	}

	public static function normalize_url( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			$url = 'https://' . ltrim( $url, '/' );
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '';
		}

		$scheme = isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : 'https';
		$base   = $scheme . '://' . $parts['host'];
		if ( isset( $parts['port'] ) ) {
			$base .= ':' . absint( $parts['port'] );
		}
		$path = isset( $parts['path'] ) ? $parts['path'] : '';
		$path = preg_replace( '#/(?:wp-admin|wp-json)(?:/.*)?$#i', '', $path );

		return esc_url_raw( untrailingslashit( $base . $path ) );
	}

	private static function mask( $value ) {
		if ( '' === $value ) {
			return '';
		}

		$length = strlen( $value );
		if ( $length <= 10 ) {
			return str_repeat( '*', $length );
		}

		return substr( $value, 0, 5 ) . str_repeat( '*', max( 8, $length - 9 ) ) . substr( $value, -4 );
	}
}
