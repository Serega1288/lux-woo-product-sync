<?php

defined( 'ABSPATH' ) || exit;

final class LWPS_Analyzer {
	const STATE_OPTION = 'lwps_analysis_state';

	public static function start() {
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'lwps_changes' );

		$state = array(
			'token'      => wp_generate_uuid4(),
			'page'       => 1,
			'total'      => 0,
			'processed'  => 0,
			'changes'    => 0,
			'summary'    => self::empty_summary(),
			'started_at' => current_time( 'mysql', true ),
			'user_id'    => get_current_user_id(),
		);
		update_option( self::STATE_OPTION, $state, false );
		return $state;
	}

	public static function step( $token ) {
		$state = get_option( self::STATE_OPTION, array() );
		if ( empty( $state['token'] ) || ! hash_equals( (string) $state['token'], (string) $token ) ) {
			return new WP_Error( 'lwps_invalid_analysis', __( 'The catalog analysis session has expired.', 'lux-woo-product-sync' ) );
		}

		$client   = new LWPS_Api_Client();
		$response = $client->manifest_page( max( 1, (int) $state['page'] ), 20 );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( ! empty( $response['requires_bootstrap'] ) ) {
			return new WP_Error( 'lwps_bootstrap_required', __( 'Some donor products have no synchronization UUID. Run initial linking first.', 'lux-woo-product-sync' ) );
		}

		$state['total'] = isset( $response['total'] ) ? (int) $response['total'] : 0;
		foreach ( isset( $response['items'] ) ? $response['items'] : array() as $remote ) {
			$status = self::analyze_item( $remote );
			++$state['processed'];
			if ( $status ) {
				++$state['changes'];
				if ( isset( $state['summary'][ $status ] ) ) {
					++$state['summary'][ $status ];
				}
			}
		}

		$total_pages = isset( $response['total_pages'] ) ? max( 1, (int) $response['total_pages'] ) : 1;
		$done        = (int) $state['page'] >= $total_pages;
		if ( $done ) {
			$state['completed_at'] = current_time( 'mysql', true );
		} else {
			++$state['page'];
		}
		update_option( self::STATE_OPTION, $state, false );

		return array(
			'token'       => $state['token'],
			'done'        => $done,
			'page'        => (int) $state['page'],
			'total_pages' => $total_pages,
			'total'       => (int) $state['total'],
			'processed'   => (int) $state['processed'],
			'changes'     => (int) $state['changes'],
			'summary'     => $state['summary'],
		);
	}

	private static function analyze_item( array $remote ) {
		$product_id = LWPS_Identity::find( $remote['uid'], 'product' );
		if ( ! $product_id ) {
			$product_id = self::link_existing_product( $remote );
		}
		$product    = $product_id ? wc_get_product( $product_id ) : false;

		if ( ! $product ) {
			self::store_change(
				$remote,
				array(
					'status'            => 'new',
					'local_product_id'  => 0,
					'local_hash'        => '',
					'local_variations'  => 0,
					'variation_added'   => isset( $remote['variation_count'] ) ? (int) $remote['variation_count'] : 0,
					'variation_updated' => 0,
					'variation_removed' => 0,
					'is_locked'         => 0,
				)
			);
			return 'new';
		}

		self::link_existing_variations( $product, isset( $remote['variations'] ) ? $remote['variations'] : array() );

		$local      = LWPS_Snapshot::manifest( $product );
		$local_hash = $local ? $local['full_hash'] : '';
		$is_locked  = 'yes' === get_post_meta( $product_id, '_lwps_local_lock', true );

		$remote_variations = wp_list_pluck( isset( $remote['variations'] ) ? $remote['variations'] : array(), 'hash', 'uid' );
		$local_variations  = $local ? wp_list_pluck( $local['variations'], 'hash', 'uid' ) : array();
		$added             = array_diff_key( $remote_variations, $local_variations );
		$removed           = array_diff_key( $local_variations, $remote_variations );

		if ( ! $added ) {
			return '';
		}

		$unlocked_status = 'missing_variations';
		$status          = $is_locked ? 'locked' : $unlocked_status;

		self::store_change(
			$remote,
			array(
				'status'            => $status,
				'local_product_id'  => $product_id,
				'local_hash'        => $local_hash,
				'local_variations'  => count( $local_variations ),
				'variation_added'   => count( $added ),
				'variation_updated' => 0,
				'variation_removed' => count( $removed ),
				'is_locked'         => $is_locked ? 1 : 0,
				'unlocked_status'   => $unlocked_status,
				'variation_uids'    => array(
					'added'   => array_keys( $added ),
					'updated' => array(),
					'removed' => array_keys( $removed ),
				),
			)
		);
		return $status;
	}

	private static function link_existing_product( array $remote ) {
		global $wpdb;

		$slug = isset( $remote['slug'] ) ? sanitize_title( $remote['slug'] ) : '';
		$ids  = $slug ? get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => array( 'publish', 'private', 'draft', 'pending', 'future' ),
				'name'           => $slug,
				'posts_per_page' => 2,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		) : array();

		if ( 1 !== count( $ids ) && ! empty( $remote['name'] ) ) {
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status NOT IN ('trash', 'auto-draft') AND post_title = %s ORDER BY ID ASC LIMIT 2",
					wp_strip_all_tags( $remote['name'] )
				)
			);
		}

		if ( 1 !== count( $ids ) ) {
			return 0;
		}

		$product = wc_get_product( (int) $ids[0] );
		if ( ! $product || ( isset( $remote['type'] ) && $product->get_type() !== $remote['type'] ) ) {
			return 0;
		}

		$current_uid = get_post_meta( $product->get_id(), LWPS_Identity::META_KEY, true );
		if ( wp_is_uuid( $current_uid ) && $current_uid !== $remote['uid'] && get_post_meta( $product->get_id(), '_lwps_last_donor_hash', true ) ) {
			return 0;
		}

		$assigned = LWPS_Identity::assign( $product->get_id(), $remote['uid'] );
		if ( is_wp_error( $assigned ) ) {
			return 0;
		}

		self::link_existing_variations( $product, isset( $remote['variations'] ) ? $remote['variations'] : array() );
		return $product->get_id();
	}

	private static function link_existing_variations( WC_Product $product, array $remote_variations ) {
		if ( ! $product->is_type( 'variable' ) ) {
			return;
		}

		$local_by_signature = array();
		foreach ( $product->get_children() as $variation_id ) {
			$variation = wc_get_product( $variation_id );
			if ( $variation instanceof WC_Product_Variation ) {
				$signature = LWPS_Snapshot::hash( array( 'attributes' => $variation->get_attributes() ) );
				$local_by_signature[ $signature ][] = $variation_id;
			}
		}

		$matched = array();
		foreach ( $remote_variations as $remote_variation ) {
			if ( empty( $remote_variation['uid'] ) ) {
				continue;
			}
			$signature = LWPS_Snapshot::hash( array( 'attributes' => isset( $remote_variation['attributes'] ) ? $remote_variation['attributes'] : array() ) );
			$candidates = isset( $local_by_signature[ $signature ] ) ? array_values( array_diff( $local_by_signature[ $signature ], $matched ) ) : array();
			if ( 1 === count( $candidates ) && ! is_wp_error( LWPS_Identity::assign( $candidates[0], $remote_variation['uid'] ) ) ) {
				$matched[] = $candidates[0];
			}
		}

		foreach ( $product->get_children() as $variation_id ) {
			if ( ! in_array( $variation_id, $matched, true ) ) {
				LWPS_Identity::ensure( $variation_id );
			}
		}
	}

	private static function store_change( array $remote, array $local ) {
		global $wpdb;
		$table = $wpdb->prefix . 'lwps_changes';
		$details = array(
			'remote_id'      => isset( $remote['remote_id'] ) ? absint( $remote['remote_id'] ) : 0,
			'core_hash'      => isset( $remote['core_hash'] ) ? $remote['core_hash'] : '',
			'unlocked_status' => isset( $local['unlocked_status'] ) ? sanitize_key( $local['unlocked_status'] ) : '',
			'variation_uids' => isset( $local['variation_uids'] ) ? $local['variation_uids'] : array(
				'added'   => wp_list_pluck( isset( $remote['variations'] ) ? $remote['variations'] : array(), 'uid' ),
				'updated' => array(),
				'removed' => array(),
			),
		);

		$wpdb->replace(
			$table,
			array(
				'remote_uid'          => sanitize_text_field( $remote['uid'] ),
				'local_product_id'    => (int) $local['local_product_id'],
				'product_name'        => isset( $remote['name'] ) ? sanitize_text_field( $remote['name'] ) : '',
				'product_type'        => isset( $remote['type'] ) ? sanitize_key( $remote['type'] ) : 'simple',
				'change_status'       => sanitize_key( $local['status'] ),
				'donor_hash'          => sanitize_text_field( $remote['full_hash'] ),
				'local_hash'          => sanitize_text_field( $local['local_hash'] ),
				'donor_variations'    => isset( $remote['variation_count'] ) ? (int) $remote['variation_count'] : 0,
				'local_variations'    => (int) $local['local_variations'],
				'variation_added'     => (int) $local['variation_added'],
				'variation_updated'   => (int) $local['variation_updated'],
				'variation_removed'   => (int) $local['variation_removed'],
				'is_locked'           => (int) $local['is_locked'],
				'details_json'        => wp_json_encode( $details ),
				'analyzed_at'         => current_time( 'mysql', true ),
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s' )
		);
	}

	private static function empty_summary() {
		return array(
			'new'                => 0,
			'update'             => 0,
			'missing_variations' => 0,
			'local_changes'      => 0,
			'locked'             => 0,
		);
	}

}
