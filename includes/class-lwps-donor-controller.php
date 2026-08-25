<?php

defined( 'ABSPATH' ) || exit;

final class LWPS_Donor_Controller {
	public static function register() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes() {
		register_rest_route(
			'lwps/v1',
			'/manifest',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'manifest' ),
				'permission_callback' => array( 'LWPS_Auth', 'authorize_read' ),
				'args'                => array(
					'page'     => array( 'default' => 1, 'sanitize_callback' => 'absint' ),
					'per_page' => array( 'default' => 50, 'sanitize_callback' => 'absint' ),
				),
			)
		);

		register_rest_route(
			'lwps/v1',
			'/product/(?P<uid>[a-f0-9-]{36})',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'product' ),
				'permission_callback' => array( 'LWPS_Auth', 'authorize_read' ),
			)
		);

		register_rest_route(
			'lwps/v1',
			'/bootstrap',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'bootstrap' ),
				'permission_callback' => array( 'LWPS_Auth', 'authorize_write' ),
			)
		);
	}

	public static function manifest( WP_REST_Request $request ) {
		$page     = max( 1, (int) $request['page'] );
		$per_page = min( 100, max( 1, (int) $request['per_page'] ) );
		$query    = new WP_Query(
			array(
				'post_type'      => 'product',
				'post_status'    => array( 'publish', 'private', 'draft', 'pending' ),
				'posts_per_page' => $per_page,
				'paged'          => $page,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'fields'         => 'ids',
			)
		);

		$items              = array();
		$requires_bootstrap = false;
		foreach ( $query->posts as $product_id ) {
			$product = wc_get_product( $product_id );
			if ( ! $product ) {
				continue;
			}
			$item = LWPS_Snapshot::manifest( $product );
			if ( ! $item ) {
				$requires_bootstrap = true;
				continue;
			}
			$items[] = $item;
		}

		return rest_ensure_response(
			array(
				'site'               => array(
					'name'        => get_bloginfo( 'name' ),
					'url'         => home_url( '/' ),
					'woocommerce' => defined( 'WC_VERSION' ) ? WC_VERSION : '',
				),
				'page'               => $page,
				'per_page'           => $per_page,
				'total'              => (int) $query->found_posts,
				'total_pages'        => (int) $query->max_num_pages,
				'requires_bootstrap' => $requires_bootstrap,
				'items'              => $items,
			)
		);
	}

	public static function product( WP_REST_Request $request ) {
		$product_id = LWPS_Identity::find( $request['uid'], 'product' );
		$product    = $product_id ? wc_get_product( $product_id ) : false;
		if ( ! $product ) {
			return new WP_Error( 'lwps_product_not_found', __( 'Product not found on donor.', 'lux-woo-product-sync' ), array( 'status' => 404 ) );
		}

		return rest_ensure_response( LWPS_Snapshot::full( $product ) );
	}

	public static function bootstrap( WP_REST_Request $request ) {
		$page     = max( 1, absint( $request->get_param( 'page' ) ) );
		$per_page = min( 100, max( 1, absint( $request->get_param( 'per_page' ) ) ) );
		$query    = new WP_Query(
			array(
				'post_type'      => 'product',
				'post_status'    => 'any',
				'posts_per_page' => $per_page,
				'paged'          => $page,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'fields'         => 'ids',
			)
		);

		$assigned = 0;
		foreach ( $query->posts as $product_id ) {
			if ( ! wp_is_uuid( get_post_meta( $product_id, LWPS_Identity::META_KEY, true ) ) ) {
				LWPS_Identity::ensure( $product_id );
				++$assigned;
			}
			$product = wc_get_product( $product_id );
			if ( $product && $product->is_type( 'variable' ) ) {
				foreach ( $product->get_children() as $variation_id ) {
					if ( ! wp_is_uuid( get_post_meta( $variation_id, LWPS_Identity::META_KEY, true ) ) ) {
						LWPS_Identity::ensure( $variation_id );
						++$assigned;
					}
				}
			}
		}

		return rest_ensure_response(
			array(
				'assigned'    => $assigned,
				'processed'   => count( $query->posts ),
				'total'       => (int) $query->found_posts,
				'page'        => $page,
				'total_pages' => (int) $query->max_num_pages,
				'done'        => $page >= (int) $query->max_num_pages,
			)
		);
	}
}

