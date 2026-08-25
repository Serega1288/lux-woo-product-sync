<?php

defined( 'ABSPATH' ) || exit;

final class LWPS_Admin_Controller {
	public static function register() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'wp_ajax_lwps_save_settings', array( __CLASS__, 'ajax_save_settings' ) );
		add_action( 'wp_ajax_lwps_test_connection', array( __CLASS__, 'ajax_test_connection' ) );
		add_action( 'wp_ajax_lwps_get_settings', array( __CLASS__, 'ajax_get_settings' ) );
	}

	public static function routes() {
		register_rest_route( 'lwps/v1', '/admin/settings', self::route( WP_REST_Server::READABLE, 'settings' ) );
		register_rest_route( 'lwps/v1', '/admin/settings', self::route( WP_REST_Server::CREATABLE, 'save_settings' ) );
		register_rest_route( 'lwps/v1', '/admin/test', self::route( WP_REST_Server::CREATABLE, 'test_connection' ) );
		register_rest_route( 'lwps/v1', '/admin/analysis/start', self::route( WP_REST_Server::CREATABLE, 'analysis_start' ) );
		register_rest_route( 'lwps/v1', '/admin/analysis/step', self::route( WP_REST_Server::CREATABLE, 'analysis_step' ) );
		register_rest_route( 'lwps/v1', '/admin/changes', self::route( WP_REST_Server::READABLE, 'changes' ) );
		register_rest_route( 'lwps/v1', '/admin/preview', self::route( WP_REST_Server::CREATABLE, 'preview' ) );
		register_rest_route( 'lwps/v1', '/admin/jobs', self::route( WP_REST_Server::READABLE, 'jobs' ) );
		register_rest_route( 'lwps/v1', '/admin/jobs', self::route( WP_REST_Server::CREATABLE, 'create_job' ) );
		register_rest_route( 'lwps/v1', '/admin/jobs/(?P<id>\d+)', self::route( WP_REST_Server::READABLE, 'job' ) );
		register_rest_route( 'lwps/v1', '/admin/jobs/(?P<id>\d+)/run', self::route( WP_REST_Server::CREATABLE, 'run_job' ) );
		register_rest_route( 'lwps/v1', '/admin/jobs/(?P<id>\d+)/retry', self::route( WP_REST_Server::CREATABLE, 'retry_job' ) );
		register_rest_route( 'lwps/v1', '/admin/products/(?P<id>\d+)/lock', self::route( WP_REST_Server::EDITABLE, 'toggle_lock' ) );
	}

	private static function route( $methods, $callback ) {
		return array(
			'methods'             => $methods,
			'callback'            => array( __CLASS__, $callback ),
			'permission_callback' => array( __CLASS__, 'can_manage' ),
		);
	}

	public static function can_manage() {
		return current_user_can( 'manage_woocommerce' );
	}

	public static function ajax_save_settings() {
		self::verify_ajax_request();
		$result = LWPS_Settings::save( self::ajax_connection_data() );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'code' => $result->get_error_code(), 'message' => $result->get_error_message() ), 400 );
		}
		wp_send_json_success( array( 'settings' => $result ) );
	}

	public static function ajax_get_settings() {
		self::verify_ajax_request();
		wp_send_json_success( self::dashboard_data() );
	}

	public static function ajax_test_connection() {
		self::verify_ajax_request();
		$result = ( new LWPS_Api_Client( self::ajax_connection_data() ) )->test();
		if ( is_wp_error( $result ) ) {
			$status = $result->get_error_data();
			$status = is_array( $status ) && isset( $status['status'] ) ? (int) $status['status'] : 400;
			wp_send_json_error( array( 'code' => $result->get_error_code(), 'message' => $result->get_error_message() ), $status >= 400 && $status < 600 ? $status : 400 );
		}
		wp_send_json_success( $result );
	}

	public static function settings() {
		return rest_ensure_response( self::dashboard_data() );
	}

	public static function save_settings( WP_REST_Request $request ) {
		$data   = self::body( $request );
		$result = LWPS_Settings::save( $data );
		return is_wp_error( $result ) ? $result : rest_ensure_response( array( 'settings' => $result ) );
	}

	public static function test_connection( WP_REST_Request $request ) {
		$data   = self::body( $request );
		$result = ( new LWPS_Api_Client( $data ) )->test();
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	public static function analysis_start() {
		return rest_ensure_response( LWPS_Analyzer::start() );
	}

	public static function analysis_step( WP_REST_Request $request ) {
		$data = self::body( $request );
		try {
			$result = LWPS_Analyzer::step( isset( $data['token'] ) ? sanitize_text_field( $data['token'] ) : '' );
		} catch ( Throwable $error ) {
			$result = new WP_Error(
				'lwps_analysis_failed',
				sprintf( __( 'Catalog analysis failed: %s', 'lux-woo-product-sync' ), $error->getMessage() ),
				array( 'status' => 500 )
			);
		}
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	public static function changes( WP_REST_Request $request ) {
		global $wpdb;
		$table    = $wpdb->prefix . 'lwps_changes';
		$page     = max( 1, absint( $request->get_param( 'page' ) ) );
		$per_page = min( 100, max( 1, absint( $request->get_param( 'per_page' ) ) ) );
		$status   = sanitize_key( $request->get_param( 'status' ) );
		$search   = sanitize_text_field( $request->get_param( 'search' ) );
		$where    = array( '1=1' );
		$values   = array();

		if ( in_array( $status, array( 'variation_added', 'missing_variations' ), true ) ) {
			$where[] = 'local_product_id > 0';
			$where[] = 'variation_added > 0';
		} elseif ( 'locked' === $status ) {
			$where[] = 'is_locked = 1';
		} elseif ( in_array( $status, array( 'new', 'update', 'local_changes' ), true ) ) {
			$where[]  = 'change_status = %s';
			$values[] = $status;
		}
		if ( '' !== $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where[]  = 'product_name LIKE %s';
			$values[] = $like;
		}

		$where_sql = implode( ' AND ', $where );
		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		$total     = (int) $wpdb->get_var( $values ? $wpdb->prepare( $count_sql, $values ) : $count_sql );
		$offset    = ( $page - 1 ) * $per_page;
		$list_sql  = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY FIELD(change_status, 'local_changes', 'locked', 'new', 'update', 'missing_variations'), product_name ASC LIMIT %d OFFSET %d";
		$list_args = array_merge( $values, array( $per_page, $offset ) );
		$rows      = $wpdb->get_results( $wpdb->prepare( $list_sql, $list_args ), ARRAY_A );

		foreach ( $rows as &$row ) {
			$row['details']  = json_decode( (string) $row['details_json'], true );
			$row['edit_url'] = $row['local_product_id'] ? get_edit_post_link( (int) $row['local_product_id'], 'raw' ) : '';
			unset( $row['details_json'] );
		}

		return rest_ensure_response(
			array(
				'items'       => $rows,
				'total'       => $total,
				'page'        => $page,
				'total_pages' => max( 1, (int) ceil( $total / $per_page ) ),
				'summary'     => self::change_summary(),
			)
		);
	}

	public static function preview( WP_REST_Request $request ) {
		$data      = self::body( $request );
		$operation = isset( $data['operation'] ) ? sanitize_key( $data['operation'] ) : '';
		$uids      = isset( $data['uids'] ) && is_array( $data['uids'] ) ? $data['uids'] : array();
		$scope     = isset( $data['scope'] ) && 'all' === sanitize_key( $data['scope'] ) ? 'all' : 'selected';
		$options   = self::options( $data );
		return rest_ensure_response( LWPS_Jobs::preview( $uids, $operation, $options, $scope, self::filters( $data ) ) );
	}

	public static function create_job( WP_REST_Request $request ) {
		$data      = self::body( $request );
		$operation = isset( $data['operation'] ) ? sanitize_key( $data['operation'] ) : '';
		$uids      = isset( $data['uids'] ) && is_array( $data['uids'] ) ? $data['uids'] : array();
		$scope     = isset( $data['scope'] ) && 'all' === sanitize_key( $data['scope'] ) ? 'all' : 'selected';
		$result    = LWPS_Jobs::create( $uids, $operation, self::options( $data ), $scope, self::filters( $data ) );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	public static function jobs() {
		return rest_ensure_response( array( 'items' => LWPS_Jobs::recent( 25 ) ) );
	}

	public static function job( WP_REST_Request $request ) {
		$result = LWPS_Jobs::get( absint( $request['id'] ) );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	public static function run_job( WP_REST_Request $request ) {
		$data   = self::body( $request );
		$limit  = isset( $data['limit'] ) ? absint( $data['limit'] ) : 5;
		$result = LWPS_Jobs::run_batch( absint( $request['id'] ), $limit );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	public static function retry_job( WP_REST_Request $request ) {
		$result = LWPS_Jobs::retry_failed( absint( $request['id'] ) );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	public static function toggle_lock( WP_REST_Request $request ) {
		global $wpdb;

		$product_id = absint( $request['id'] );
		if ( 'product' !== get_post_type( $product_id ) || ! current_user_can( 'edit_post', $product_id ) ) {
			return new WP_Error( 'lwps_product_not_found', __( 'Product not found.', 'lux-woo-product-sync' ), array( 'status' => 404 ) );
		}
		$data   = self::body( $request );
		$locked = ! empty( $data['locked'] );
		if ( $locked ) {
			update_post_meta( $product_id, '_lwps_local_lock', 'yes' );
		} else {
			delete_post_meta( $product_id, '_lwps_local_lock' );
		}

		$table   = $wpdb->prefix . 'lwps_changes';
		$change  = $wpdb->get_row( $wpdb->prepare( "SELECT id, change_status, details_json FROM {$table} WHERE local_product_id = %d", $product_id ), ARRAY_A );
		$status  = $locked ? 'locked' : 'update';
		$details = array();
		if ( $change ) {
			$details = json_decode( (string) $change['details_json'], true );
			$details = is_array( $details ) ? $details : array();
			if ( $locked && 'locked' !== $change['change_status'] ) {
				$details['unlocked_status'] = $change['change_status'];
			}
			if ( ! $locked && isset( $details['unlocked_status'] ) && in_array( $details['unlocked_status'], array( 'update', 'missing_variations', 'local_changes' ), true ) ) {
				$status = $details['unlocked_status'];
			}
			$wpdb->update(
				$table,
				array(
					'is_locked'     => $locked ? 1 : 0,
					'change_status' => $status,
					'details_json'  => wp_json_encode( $details ),
				),
				array( 'id' => (int) $change['id'] ),
				array( '%d', '%s', '%s' ),
				array( '%d' )
			);
		}

		return rest_ensure_response( array( 'product_id' => $product_id, 'locked' => $locked, 'status' => $status ) );
	}

	private static function body( WP_REST_Request $request ) {
		$data = $request->get_json_params();
		return is_array( $data ) ? $data : $request->get_params();
	}

	private static function verify_ajax_request() {
		check_ajax_referer( 'lwps_admin', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'code' => 'lwps_forbidden', 'message' => __( 'You are not allowed to manage synchronization settings.', 'lux-woo-product-sync' ) ), 403 );
		}
	}

	private static function ajax_connection_data() {
		return array(
			'donor_url'      => isset( $_POST['donor_url'] ) ? esc_url_raw( wp_unslash( $_POST['donor_url'] ) ) : '',
			'consumer_key'    => isset( $_POST['consumer_key'] ) ? sanitize_text_field( wp_unslash( $_POST['consumer_key'] ) ) : '',
			'consumer_secret' => isset( $_POST['consumer_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['consumer_secret'] ) ) : '',
		);
	}

	private static function options( array $data ) {
		return array(
			'force_locked'              => ! empty( $data['force_locked'] ),
			'delete_missing_variations' => ! empty( $data['delete_missing_variations'] ),
		);
	}

	private static function filters( array $data ) {
		return array(
			'status' => isset( $data['filter_status'] ) ? sanitize_key( $data['filter_status'] ) : '',
			'search' => isset( $data['filter_search'] ) ? sanitize_text_field( $data['filter_search'] ) : '',
		);
	}

	private static function dashboard_data() {
		return array(
			'settings' => LWPS_Settings::get_public(),
			'state'    => get_option( LWPS_Analyzer::STATE_OPTION, array() ),
			'summary'  => self::change_summary(),
			'jobs'     => LWPS_Jobs::recent( 8 ),
		);
	}

	private static function change_summary() {
		global $wpdb;
		$table  = $wpdb->prefix . 'lwps_changes';
		$rows   = $wpdb->get_results( "SELECT change_status, COUNT(*) amount, SUM(variation_added) variation_added, SUM(variation_updated) variation_updated, SUM(variation_removed) variation_removed FROM {$table} GROUP BY change_status", ARRAY_A );
		$result = array(
			'new'                => 0,
			'update'             => 0,
			'missing_variations' => 0,
			'local_changes'      => 0,
			'locked'             => 0,
			'variation_added'    => 0,
			'variation_updated'  => 0,
			'variation_removed'  => 0,
		);
		foreach ( $rows as $row ) {
			if ( isset( $result[ $row['change_status'] ] ) ) {
				$result[ $row['change_status'] ] = (int) $row['amount'];
			}
			$result['variation_added'] += (int) $row['variation_added'];
			$result['variation_updated'] += (int) $row['variation_updated'];
			$result['variation_removed'] += (int) $row['variation_removed'];
		}
		return $result;
	}
}

