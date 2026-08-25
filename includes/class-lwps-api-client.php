<?php

defined( 'ABSPATH' ) || exit;

final class LWPS_Api_Client {
	const PRODUCT_FIELDS = 'id,type,name,slug,status,date_modified,description,short_description,featured,catalog_visibility,regular_price,sale_price,date_on_sale_from,date_on_sale_to,tax_status,tax_class,manage_stock,stock_quantity,stock_status,backorders,sold_individually,weight,dimensions,shipping_class,purchase_note,menu_order,virtual,downloadable,reviews_allowed,categories,tags,images,attributes,default_attributes,meta_data,variations';
	const VARIATION_FIELDS = 'id,status,description,regular_price,sale_price,date_on_sale_from,date_on_sale_to,manage_stock,stock_quantity,stock_status,backorders,weight,dimensions,shipping_class,virtual,downloadable,menu_order,attributes,image,meta_data';

	private $url;
	private $key;
	private $secret;
	private $mode;

	public function __construct( array $credentials = array() ) {
		$stored       = LWPS_Settings::get_credentials();
		$credentials  = wp_parse_args( $credentials, $stored );
		$this->url    = isset( $credentials['donor_url'] ) && '' !== $credentials['donor_url'] ? LWPS_Settings::normalize_url( $credentials['donor_url'] ) : $stored['donor_url'];
		$this->key    = isset( $credentials['consumer_key'] ) && '' !== $credentials['consumer_key'] ? sanitize_text_field( $credentials['consumer_key'] ) : $stored['consumer_key'];
		$this->secret = isset( $credentials['consumer_secret'] ) && '' !== $credentials['consumer_secret'] ? sanitize_text_field( $credentials['consumer_secret'] ) : $stored['consumer_secret'];
		$this->mode   = isset( $stored['source_mode'] ) ? sanitize_key( $stored['source_mode'] ) : 'unknown';
	}

	public function is_configured() {
		return '' !== $this->url && '' !== $this->key && '' !== $this->secret;
	}

	public function test() {
		$woocommerce = $this->wc_get( '/products', array( 'per_page' => 1, '_fields' => 'id,name,type' ) );
		if ( is_wp_error( $woocommerce ) ) {
			return new WP_Error(
				'lwps_woocommerce_connection_failed',
				sprintf( __( 'WooCommerce REST API connection failed: %s', 'lux-woo-product-sync' ), $woocommerce->get_error_message() ),
				$woocommerce->get_error_data()
			);
		}

		$manifest       = $this->get( '/manifest', array( 'per_page' => 1, 'page' => 1 ) );
		$protocol_ready = ! is_wp_error( $manifest );
		$mode           = $protocol_ready ? 'enhanced' : 'standard_readonly';
		$host           = wp_parse_url( $this->url, PHP_URL_HOST );
		$this->remember_mode( $mode );

		return array(
			'connected'          => true,
			'donor_name'         => $protocol_ready && isset( $manifest['site']['name'] ) ? $manifest['site']['name'] : $host,
			'woocommerce'        => $protocol_ready && isset( $manifest['site']['woocommerce'] ) ? $manifest['site']['woocommerce'] : '',
			'mode'               => $mode,
			'read_only'          => 'standard_readonly' === $mode,
			'protocol_ready'     => $protocol_ready,
			'protocol_error'     => $protocol_ready ? '' : $manifest->get_error_message(),
			'requires_bootstrap' => $protocol_ready && ! empty( $manifest['requires_bootstrap'] ),
		);
	}

	public function manifest_page( $page, $per_page = 20 ) {
		$page     = max( 1, absint( $page ) );
		$per_page = min( 50, max( 1, absint( $per_page ) ) );

		if ( 'standard_readonly' !== $this->mode ) {
			$response = $this->get( '/manifest', array( 'page' => $page, 'per_page' => $per_page ) );
			if ( ! is_wp_error( $response ) ) {
				return $response;
			}
			if ( ! $this->protocol_unavailable( $response ) ) {
				return $response;
			}
			$this->remember_mode( 'standard_readonly' );
		}

		return $this->wc_manifest_page( $page, min( 20, $per_page ) );
	}

	public function product_payload( $remote_uid, $remote_id = 0 ) {
		$remote_uid = LWPS_Identity::sanitize_uid( $remote_uid );
		if ( ! $remote_uid ) {
			return new WP_Error( 'lwps_invalid_uid', __( 'Invalid synchronization UUID.', 'lux-woo-product-sync' ) );
		}

		if ( 'standard_readonly' !== $this->mode ) {
			$response = $this->get( '/product/' . rawurlencode( $remote_uid ) );
			if ( ! is_wp_error( $response ) ) {
				return $response;
			}
			if ( ! $this->protocol_unavailable( $response ) ) {
				return $response;
			}
			$this->remember_mode( 'standard_readonly' );
		}

		$remote_id = absint( $remote_id ) ? absint( $remote_id ) : $this->remote_id_for_uid( $remote_uid );
		if ( ! $remote_id ) {
			return new WP_Error( 'lwps_remote_mapping_missing', __( 'Run catalog analysis again to refresh the donor product mapping.', 'lux-woo-product-sync' ) );
		}

		$product = $this->wc_get( '/products/' . $remote_id, array( '_fields' => self::PRODUCT_FIELDS ) );
		if ( is_wp_error( $product ) ) {
			return $product;
		}
		$variations = 'variable' === ( isset( $product['type'] ) ? $product['type'] : '' ) ? $this->wc_variations( $remote_id ) : array();
		if ( is_wp_error( $variations ) ) {
			return $variations;
		}

		$adapter = new LWPS_WC_Adapter( $this->url, $this->wc_category_map() );
		return $adapter->full( $product, $variations );
	}

	public function get( $path, array $query = array() ) {
		return $this->request( 'GET', $path, $query );
	}

	public function post( $path, array $body = array() ) {
		return $this->request( 'POST', $path, $body );
	}

	private function wc_manifest_page( $page, $per_page ) {
		$response = $this->wc_get(
			'/products',
			array(
				'page'     => $page,
				'per_page' => $per_page,
				'status'   => 'any',
				'_fields'  => self::PRODUCT_FIELDS,
			),
			true
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$adapter = new LWPS_WC_Adapter( $this->url, $this->wc_category_map() );
		$items   = array();
		foreach ( $response['body'] as $product ) {
			$variations = 'variable' === ( isset( $product['type'] ) ? $product['type'] : '' ) ? $this->wc_variations( (int) $product['id'] ) : array();
			if ( is_wp_error( $variations ) ) {
				return $variations;
			}
			$full = $adapter->full( $product, $variations );
			if ( is_wp_error( $full ) ) {
				return $full;
			}
			$items[] = $full['manifest'];
		}

		$total       = isset( $response['headers']['total'] ) && $response['headers']['total'] ? (int) $response['headers']['total'] : ( ( $page - 1 ) * $per_page + count( $items ) );
		$total_pages = isset( $response['headers']['total_pages'] ) && $response['headers']['total_pages'] ? (int) $response['headers']['total_pages'] : ( count( $items ) < $per_page ? $page : $page + 1 );
		return array(
			'site'               => array( 'name' => wp_parse_url( $this->url, PHP_URL_HOST ), 'woocommerce' => '' ),
			'source_mode'        => 'standard_readonly',
			'requires_bootstrap' => false,
			'page'               => $page,
			'per_page'           => $per_page,
			'total'              => $total,
			'total_pages'        => max( 1, $total_pages ),
			'items'              => $items,
		);
	}

	private function wc_variations( $product_id ) {
		$items = array();
		$page  = 1;
		do {
			$response = $this->wc_get(
				'/products/' . absint( $product_id ) . '/variations',
				array(
					'page'     => $page,
					'per_page' => 100,
					'_fields'  => self::VARIATION_FIELDS,
				),
				true
			);
			if ( is_wp_error( $response ) ) {
				return $response;
			}
			$items = array_merge( $items, $response['body'] );
			$total_pages = isset( $response['headers']['total_pages'] ) && $response['headers']['total_pages'] ? max( 1, (int) $response['headers']['total_pages'] ) : ( count( $response['body'] ) < 100 ? $page : $page + 1 );
			++$page;
		} while ( $page <= $total_pages && $page <= 100 );

		return $items;
	}

	private function wc_category_map() {
		$cache_key = 'lwps_wc_categories_' . md5( $this->url );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$map  = array();
		$page = 1;
		do {
			$response = $this->wc_get(
				'/products/categories',
				array( 'page' => $page, 'per_page' => 100, 'hide_empty' => 'false', '_fields' => 'id,name,slug,parent' ),
				true
			);
			if ( is_wp_error( $response ) ) {
				return array();
			}
			foreach ( $response['body'] as $row ) {
				$map[ (int) $row['id'] ] = array(
					'name'   => isset( $row['name'] ) ? (string) $row['name'] : '',
					'slug'   => isset( $row['slug'] ) ? (string) $row['slug'] : '',
					'parent' => isset( $row['parent'] ) ? (int) $row['parent'] : 0,
				);
			}
			$total_pages = isset( $response['headers']['total_pages'] ) && $response['headers']['total_pages'] ? max( 1, (int) $response['headers']['total_pages'] ) : ( count( $response['body'] ) < 100 ? $page : $page + 1 );
			++$page;
		} while ( $page <= $total_pages && $page <= 100 );

		foreach ( $map as $id => $row ) {
			$map[ $id ]['parent_slug'] = $row['parent'] && isset( $map[ $row['parent'] ] ) ? $map[ $row['parent'] ]['slug'] : '';
		}
		set_transient( $cache_key, $map, 10 * MINUTE_IN_SECONDS );
		return $map;
	}

	private function remote_id_for_uid( $remote_uid ) {
		global $wpdb;
		$table = $wpdb->prefix . 'lwps_changes';
		$json  = $wpdb->get_var( $wpdb->prepare( "SELECT details_json FROM {$table} WHERE remote_uid = %s LIMIT 1", $remote_uid ) );
		$data  = $json ? json_decode( $json, true ) : array();
		return is_array( $data ) && ! empty( $data['remote_id'] ) ? absint( $data['remote_id'] ) : 0;
	}

	private function wc_get( $path, array $query = array(), $with_headers = false ) {
		$url = $this->url . '/wp-json/wc/v3/' . ltrim( $path, '/' );
		return $this->request_url( 'GET', $url, $query, $with_headers );
	}

	private function request( $method, $path, array $data ) {
		$url = $this->url . '/wp-json/lwps/v1/' . ltrim( $path, '/' );
		return $this->request_url( $method, $url, $data );
	}

	private function request_url( $method, $url, array $data, $with_headers = false ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'lwps_not_configured', __( 'Configure the donor connection first.', 'lux-woo-product-sync' ) );
		}
		$scheme      = strtolower( (string) wp_parse_url( $this->url, PHP_URL_SCHEME ) );
		$host        = (string) wp_parse_url( $this->url, PHP_URL_HOST );
		$environment = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';
		$local_http  = in_array( $environment, array( 'local', 'development' ), true ) && in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true );
		if ( 'https' !== $scheme && ! $local_http ) {
			return new WP_Error( 'lwps_https_required', __( 'Use HTTPS for the donor connection.', 'lux-woo-product-sync' ) );
		}
		if ( 0 !== strpos( $this->key, 'ck_' ) || 0 !== strpos( $this->secret, 'cs_' ) ) {
			return new WP_Error( 'lwps_invalid_credentials', __( 'Enter valid WooCommerce REST credentials.', 'lux-woo-product-sync' ) );
		}

		$args = array(
			'method'             => $method,
			'timeout'            => 60,
			'redirection'        => 0,
			'sslverify'          => true,
			'reject_unsafe_urls' => true,
			'headers'            => array(
				'Authorization' => 'Basic ' . base64_encode( $this->key . ':' . $this->secret ),
				'Accept'        => 'application/json',
			),
		);

		if ( 'GET' === $method ) {
			$url = add_query_arg( $data, $url );
		} else {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body'] = wp_json_encode( $data );
		}

		$response = $local_http ? wp_remote_request( $url, $args ) : wp_safe_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'lwps_donor_unreachable', $response->get_error_message() );
		}

		$status       = wp_remote_retrieve_response_code( $response );
		$content_type = strtolower( (string) wp_remote_retrieve_header( $response, 'content-type' ) );
		$raw_body     = wp_remote_retrieve_body( $response );
		$body         = json_decode( $raw_body, true );
		if ( $status < 200 || $status >= 300 ) {
			$message = is_array( $body ) && ! empty( $body['message'] ) ? $body['message'] : sprintf( __( 'Donor returned HTTP %d.', 'lux-woo-product-sync' ), $status );
			$code = is_array( $body ) && ! empty( $body['code'] ) ? sanitize_key( $body['code'] ) : 'lwps_donor_error';
			return new WP_Error( $code, sanitize_text_field( $message ), array( 'status' => $status ) );
		}

		if ( ! is_array( $body ) ) {
			$message = false === strpos( $content_type, 'json' )
				? __( 'The donor returned HTML instead of REST JSON. Check security or redirect rules for /wp-json/.', 'lux-woo-product-sync' )
				: __( 'The donor returned an invalid JSON response.', 'lux-woo-product-sync' );
			return new WP_Error( 'lwps_invalid_response', $message );
		}

		if ( ! $with_headers ) {
			return $body;
		}
		return array(
			'body'    => $body,
			'headers' => array(
				'total'       => (int) wp_remote_retrieve_header( $response, 'x-wp-total' ),
				'total_pages' => (int) wp_remote_retrieve_header( $response, 'x-wp-totalpages' ),
			),
		);
	}

	private function protocol_unavailable( WP_Error $error ) {
		return 'rest_no_route' === $error->get_error_code();
	}

	private function remember_mode( $mode ) {
		$stored = LWPS_Settings::get_credentials();
		if ( $stored['donor_url'] === $this->url && hash_equals( (string) $stored['consumer_key'], (string) $this->key ) && hash_equals( (string) $stored['consumer_secret'], (string) $this->secret ) ) {
			LWPS_Settings::set_source_mode( $mode );
			$this->mode = $mode;
		}
	}
}
