<?php

defined( 'ABSPATH' ) || exit;

final class LWPS_Jobs {
	public static function preview( array $uids, $operation, array $options = array(), $scope = 'selected' ) {
		$rows = self::change_rows( $uids, $scope, $operation );
		$summary = array(
			'products_created'   => 0,
			'products_updated'   => 0,
			'variations_added'   => 0,
			'variations_updated' => 0,
			'variations_deleted' => 0,
			'skipped_locked'     => 0,
			'skipped_invalid'    => 0,
			'total_items'        => 0,
		);

		foreach ( $rows as $row ) {
			if ( ! self::eligible( $row, $operation, $options ) ) {
				if ( $row->is_locked && empty( $options['force_locked'] ) ) {
					++$summary['skipped_locked'];
				} else {
					++$summary['skipped_invalid'];
				}
				continue;
			}
			++$summary['total_items'];
			if ( ! $row->local_product_id ) {
				++$summary['products_created'];
			} elseif ( in_array( $operation, array( 'update_main', 'overwrite' ), true ) ) {
				++$summary['products_updated'];
			}

			switch ( $operation ) {
				case 'import':
					$summary['variations_added'] += (int) $row->donor_variations;
					break;
				case 'update_variations':
					$summary['variations_added'] += (int) $row->variation_added;
					$summary['variations_updated'] += (int) $row->variation_updated;
					break;
				case 'add_variations':
					$summary['variations_added'] += (int) $row->variation_added;
					break;
				case 'overwrite':
					$summary['variations_added'] += $row->local_product_id ? (int) $row->variation_added : (int) $row->donor_variations;
					$summary['variations_updated'] += $row->local_product_id ? (int) $row->variation_updated : 0;
					if ( ! empty( $options['delete_missing_variations'] ) ) {
						$summary['variations_deleted'] += (int) $row->variation_removed;
					}
					break;
			}
		}

		return array(
			'operation' => $operation,
			'summary'   => $summary,
			'items'     => array_map(
				static function ( $row ) {
					return array(
						'uid'    => $row->remote_uid,
						'name'   => $row->product_name,
						'status' => $row->change_status,
					);
				},
				$rows
			),
		);
	}

	public static function create( array $uids, $operation, array $options = array(), $scope = 'selected' ) {
		$operation = sanitize_key( $operation );
		if ( ! in_array( $operation, LWPS_Product_Sync::OPERATIONS, true ) ) {
			return new WP_Error( 'lwps_invalid_operation', __( 'Choose a valid synchronization operation.', 'lux-woo-product-sync' ) );
		}

		$rows = self::change_rows( $uids, $scope, $operation );
		$rows = array_values(
			array_filter(
				$rows,
				static function ( $row ) use ( $operation, $options ) {
					return self::eligible( $row, $operation, $options );
				}
			)
		);
		if ( ! $rows ) {
			return new WP_Error( 'lwps_empty_job', __( 'No selected products can use this operation.', 'lux-woo-product-sync' ) );
		}

		global $wpdb;
		$jobs  = $wpdb->prefix . 'lwps_jobs';
		$items = $wpdb->prefix . 'lwps_job_items';
		$preview = self::preview( $uids, $operation, $options, $scope );

		$wpdb->insert(
			$jobs,
			array(
				'operation'      => $operation,
				'status'         => 'pending',
				'total_items'    => count( $rows ),
				'processed_items'=> 0,
				'success_items'  => 0,
				'failed_items'   => 0,
				'summary_json'   => wp_json_encode( $preview['summary'] ),
				'created_by'     => get_current_user_id(),
				'created_at'     => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%d', '%s' )
		);
		$job_id = (int) $wpdb->insert_id;

		foreach ( $rows as $row ) {
			$item_options = $options;
			$details      = ! empty( $row->details_json ) ? json_decode( $row->details_json, true ) : array();
			if ( is_array( $details ) && ! empty( $details['remote_id'] ) ) {
				$item_options['remote_id'] = absint( $details['remote_id'] );
			}
			$wpdb->insert(
				$items,
				array(
					'job_id'          => $job_id,
					'remote_uid'      => $row->remote_uid,
					'local_product_id'=> (int) $row->local_product_id,
					'operation'       => $operation,
					'options_json'    => wp_json_encode( $item_options ),
					'status'          => 'pending',
					'attempts'        => 0,
				),
				array( '%d', '%s', '%d', '%s', '%s', '%s', '%d' )
			);
		}

		self::schedule( $job_id );
		return self::get( $job_id );
	}

	public static function run_batch( $job_id, $limit = 5 ) {
		global $wpdb;
		$jobs  = $wpdb->prefix . 'lwps_jobs';
		$items = $wpdb->prefix . 'lwps_job_items';
		$job   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$jobs} WHERE id = %d", $job_id ) );
		if ( ! $job ) {
			return new WP_Error( 'lwps_job_not_found', __( 'Synchronization job not found.', 'lux-woo-product-sync' ) );
		}

		if ( in_array( $job->status, array( 'completed', 'completed_with_errors' ), true ) ) {
			return self::get( $job_id );
		}

		$wpdb->update(
			$jobs,
			array(
				'status'     => 'running',
				'started_at' => $job->started_at ? $job->started_at : current_time( 'mysql', true ),
			),
			array( 'id' => $job_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		$pending = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$items} WHERE job_id = %d AND status = 'pending' ORDER BY id ASC LIMIT %d",
				$job_id,
				min( 20, max( 1, (int) $limit ) )
			)
		);

		foreach ( $pending as $item ) {
			$claimed = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$items} SET status = 'running', attempts = attempts + 1, started_at = %s WHERE id = %d AND status = 'pending'",
					current_time( 'mysql', true ),
					$item->id
				)
			);
			if ( 1 !== (int) $claimed ) {
				continue;
			}

			$options = json_decode( (string) $item->options_json, true );
			$result  = LWPS_Product_Sync::execute( $item->remote_uid, $item->operation, is_array( $options ) ? $options : array() );
			if ( is_wp_error( $result ) ) {
				$wpdb->update(
					$items,
					array(
						'status'        => 'failed',
						'error_message' => $result->get_error_message(),
						'completed_at'  => current_time( 'mysql', true ),
					),
					array( 'id' => $item->id ),
					array( '%s', '%s', '%s' ),
					array( '%d' )
				);
			} else {
				$wpdb->update(
					$items,
					array(
						'status'           => 'success',
						'local_product_id' => (int) $result['product_id'],
						'error_message'    => '',
						'result_json'      => wp_json_encode( $result ),
						'completed_at'     => current_time( 'mysql', true ),
					),
					array( 'id' => $item->id ),
					array( '%s', '%d', '%s', '%s', '%s' ),
					array( '%d' )
				);
				if ( ! empty( $result['in_sync'] ) ) {
					$wpdb->delete( $wpdb->prefix . 'lwps_changes', array( 'remote_uid' => $item->remote_uid ), array( '%s' ) );
				}
			}
		}

		self::refresh_totals( $job_id );
		return self::get( $job_id );
	}

	public static function retry_failed( $job_id ) {
		global $wpdb;
		$jobs  = $wpdb->prefix . 'lwps_jobs';
		$items = $wpdb->prefix . 'lwps_job_items';
		$wpdb->query( $wpdb->prepare( "UPDATE {$items} SET status = 'pending', error_message = NULL WHERE job_id = %d AND status = 'failed'", $job_id ) );
		$wpdb->update( $jobs, array( 'status' => 'running', 'completed_at' => null ), array( 'id' => $job_id ), array( '%s', '%s' ), array( '%d' ) );
		self::refresh_totals( $job_id );
		self::schedule( $job_id );
		return self::get( $job_id );
	}

	public static function run_scheduled( $job_id ) {
		$result = self::run_batch( absint( $job_id ), 5 );
		if ( ! is_wp_error( $result ) && in_array( $result['status'], array( 'pending', 'running' ), true ) ) {
			self::schedule( absint( $job_id ) );
		}
	}

	public static function get( $job_id ) {
		global $wpdb;
		$jobs  = $wpdb->prefix . 'lwps_jobs';
		$items = $wpdb->prefix . 'lwps_job_items';
		$job   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$jobs} WHERE id = %d", $job_id ), ARRAY_A );
		if ( ! $job ) {
			return new WP_Error( 'lwps_job_not_found', __( 'Synchronization job not found.', 'lux-woo-product-sync' ) );
		}

		$logs = $wpdb->get_results(
			$wpdb->prepare( "SELECT id, remote_uid, local_product_id, operation, status, attempts, error_message, result_json, completed_at FROM {$items} WHERE job_id = %d ORDER BY id DESC LIMIT 100", $job_id ),
			ARRAY_A
		);
		$job['summary'] = json_decode( (string) $job['summary_json'], true );
		$job['logs']    = $logs;
		unset( $job['summary_json'] );
		return $job;
	}

	public static function recent( $limit = 10 ) {
		global $wpdb;
		$table = $wpdb->prefix . 'lwps_jobs';
		return $wpdb->get_results( $wpdb->prepare( "SELECT id, operation, status, total_items, processed_items, success_items, failed_items, created_at, completed_at FROM {$table} ORDER BY id DESC LIMIT %d", min( 50, max( 1, (int) $limit ) ) ), ARRAY_A );
	}

	private static function refresh_totals( $job_id ) {
		global $wpdb;
		$jobs  = $wpdb->prefix . 'lwps_jobs';
		$items = $wpdb->prefix . 'lwps_job_items';
		$counts = $wpdb->get_results( $wpdb->prepare( "SELECT status, COUNT(*) amount FROM {$items} WHERE job_id = %d GROUP BY status", $job_id ), OBJECT_K );
		$success = isset( $counts['success'] ) ? (int) $counts['success']->amount : 0;
		$failed  = isset( $counts['failed'] ) ? (int) $counts['failed']->amount : 0;
		$pending = isset( $counts['pending'] ) ? (int) $counts['pending']->amount : 0;
		$running = isset( $counts['running'] ) ? (int) $counts['running']->amount : 0;
		$total   = $success + $failed + $pending + $running;
		$status  = 'running';
		$completed_at = null;
		if ( 0 === $pending && 0 === $running ) {
			$status = $failed ? 'completed_with_errors' : 'completed';
			$completed_at = current_time( 'mysql', true );
		}
		$wpdb->update(
			$jobs,
			array(
				'status'          => $status,
				'total_items'     => $total,
				'processed_items' => $success + $failed,
				'success_items'   => $success,
				'failed_items'    => $failed,
				'completed_at'    => $completed_at,
			),
			array( 'id' => $job_id ),
			array( '%s', '%d', '%d', '%d', '%d', '%s' ),
			array( '%d' )
		);
	}

	private static function schedule( $job_id ) {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			$has_action = function_exists( 'as_has_scheduled_action' ) ? as_has_scheduled_action( 'lwps_run_job', array( 'job_id' => (int) $job_id ), 'lwps' ) : false;
			if ( ! $has_action ) {
				as_enqueue_async_action( 'lwps_run_job', array( 'job_id' => (int) $job_id ), 'lwps' );
			}
			return;
		}

		if ( ! wp_next_scheduled( 'lwps_run_job', array( (int) $job_id ) ) ) {
			wp_schedule_single_event( time() + 5, 'lwps_run_job', array( (int) $job_id ) );
		}
	}

	private static function change_rows( array $uids, $scope = 'selected', $operation = '' ) {
		global $wpdb;
		$table = $wpdb->prefix . 'lwps_changes';
		$uids  = array_values( array_filter( array_map( array( 'LWPS_Identity', 'sanitize_uid' ), $uids ) ) );
		if ( 'all' === $scope ) {
			if ( 'import' === $operation ) {
				return $wpdb->get_results( "SELECT * FROM {$table} WHERE change_status = 'new' AND local_product_id = 0 ORDER BY id ASC" );
			}
			if ( 'add_variations' === $operation ) {
				return $wpdb->get_results( "SELECT * FROM {$table} WHERE local_product_id > 0 AND variation_added > 0 ORDER BY id ASC" );
			}
			if ( in_array( $operation, array( 'update_main', 'update_variations' ), true ) ) {
				return $wpdb->get_results( "SELECT * FROM {$table} WHERE local_product_id > 0 ORDER BY id ASC" );
			}
			if ( 'overwrite' === $operation ) {
				return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id ASC" );
			}
			return array();
		}
		if ( ! $uids ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $uids ), '%s' ) );
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE remote_uid IN ({$placeholders}) ORDER BY id ASC", $uids ) );
	}

	private static function eligible( $row, $operation, array $options ) {
		if ( $row->is_locked && empty( $options['force_locked'] ) ) {
			return false;
		}
		if ( 'import' === $operation ) {
			return 'new' === $row->change_status && ! $row->local_product_id;
		}
		if ( 'add_variations' === $operation ) {
			return (bool) $row->local_product_id && (int) $row->variation_added > 0;
		}
		if ( in_array( $operation, array( 'update_main', 'update_variations' ), true ) ) {
			return (bool) $row->local_product_id;
		}
		return 'overwrite' === $operation;
	}
}

