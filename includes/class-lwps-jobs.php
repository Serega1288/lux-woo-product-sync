<?php

defined( 'ABSPATH' ) || exit;

final class LWPS_Jobs {
	public static function preview( array $uids, $operation, array $options = array(), $scope = 'selected', array $filters = array() ) {
		$rows = self::change_rows( $uids, $scope, $operation, $filters );
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
			} elseif ( 'update_main' === $operation ) {
				++$summary['products_updated'];
			}

			switch ( $operation ) {
				case 'import':
					$summary['variations_added'] += (int) $row->donor_variations;
					break;
				case 'update_variations':
					$summary['variations_added'] += (int) $row->variation_added;
					$summary['variations_updated'] += max( 0, (int) $row->donor_variations - (int) $row->variation_added );
					break;
				case 'add_variations':
					$summary['variations_added'] += (int) $row->variation_added;
					break;
				case 'delete_variations':
					$summary['variations_deleted'] += (int) $row->variation_removed;
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

	public static function create( array $uids, $operation, array $options = array(), $scope = 'selected', array $filters = array() ) {
		$operation = sanitize_key( $operation );
		if ( ! in_array( $operation, LWPS_Product_Sync::OPERATIONS, true ) ) {
			return new WP_Error( 'lwps_invalid_operation', __( 'Choose a valid synchronization operation.', 'lux-woo-product-sync' ) );
		}

		$rows = self::change_rows( $uids, $scope, $operation, $filters );
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
		$preview = self::preview( $uids, $operation, $options, $scope, $filters );

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
			if ( ! empty( $row->local_product_id ) ) {
				$item_options['local_product_id'] = absint( $row->local_product_id );
			}
			if ( isset( $row->remote_id ) && ! empty( $row->remote_id ) ) {
				$item_options['remote_id'] = absint( $row->remote_id );
				$item_options['_lwps_remote_id'] = absint( $row->remote_id );
			} elseif ( is_array( $details ) && ! empty( $details['remote_id'] ) ) {
				$item_options['remote_id'] = absint( $details['remote_id'] );
				$item_options['_lwps_remote_id'] = absint( $details['remote_id'] );
			}
			$item_options['_lwps_product_name'] = sanitize_text_field( $row->product_name );
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
				if ( 'delete_variations' === $item->operation ) {
					self::record_deleted_variations( $item, $result );
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
			$wpdb->prepare(
				"SELECT i.id, i.remote_uid, i.local_product_id, i.operation, i.status, i.attempts, i.error_message, i.result_json, i.options_json, i.completed_at, c.product_name, c.details_json AS change_details_json
				FROM {$items} i
				LEFT JOIN {$wpdb->prefix}lwps_changes c ON c.remote_uid = i.remote_uid
				WHERE i.job_id = %d
				ORDER BY i.id DESC
				LIMIT 100",
				$job_id
			),
			ARRAY_A
		);
		$job['summary'] = json_decode( (string) $job['summary_json'], true );
		$job['logs']    = self::decorate_logs( $logs );
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

	private static function decorate_logs( array $logs ) {
		foreach ( $logs as &$log ) {
			$options = ! empty( $log['options_json'] ) ? json_decode( (string) $log['options_json'], true ) : array();
			$options = is_array( $options ) ? $options : array();
			$details = ! empty( $log['change_details_json'] ) ? json_decode( (string) $log['change_details_json'], true ) : array();
			$details = is_array( $details ) ? $details : array();

			$name = '';
			if ( ! empty( $log['product_name'] ) ) {
				$name = $log['product_name'];
			} elseif ( ! empty( $options['_lwps_product_name'] ) ) {
				$name = $options['_lwps_product_name'];
			} elseif ( ! empty( $log['local_product_id'] ) ) {
				$name = get_the_title( (int) $log['local_product_id'] );
			}

			$remote_id = 0;
			if ( ! empty( $details['remote_id'] ) ) {
				$remote_id = absint( $details['remote_id'] );
			} elseif ( ! empty( $options['_lwps_remote_id'] ) ) {
				$remote_id = absint( $options['_lwps_remote_id'] );
			} elseif ( ! empty( $options['remote_id'] ) ) {
				$remote_id = absint( $options['remote_id'] );
			}

			$log['product_label'] = $name ? wp_strip_all_tags( $name ) : __( 'Товар', 'lux-woo-product-sync' );
			$log['product_meta']  = $remote_id ? sprintf( __( 'ID донора #%d', 'lux-woo-product-sync' ), $remote_id ) : '';

			unset( $log['options_json'], $log['change_details_json'], $log['product_name'] );
		}
		unset( $log );
		return $logs;
	}

	private static function change_rows( array $uids, $scope = 'selected', $operation = '', array $filters = array() ) {
		global $wpdb;
		$operation = sanitize_key( $operation );
		$table     = $wpdb->prefix . ( self::uses_catalog( $operation ) ? 'lwps_catalog' : 'lwps_changes' );
		$uids      = array_values( array_filter( array_map( array( 'LWPS_Identity', 'sanitize_uid' ), $uids ) ) );
		if ( 'all' === $scope ) {
			$where  = array( '1=1' );
			$values = array();
			if ( 'import' === $operation ) {
				$where[] = "change_status = 'new'";
				$where[] = 'local_product_id = 0';
			} elseif ( 'add_variations' === $operation ) {
				$where[] = 'local_product_id > 0';
				$where[] = 'variation_added > 0';
			} elseif ( 'update_main' === $operation ) {
				$where[] = 'local_product_id > 0';
			} elseif ( 'update_variations' === $operation ) {
				$where[] = 'local_product_id > 0';
				$where[] = "product_type = 'variable'";
			} elseif ( 'delete_variations' === $operation ) {
				$where[] = 'local_product_id > 0';
				$where[] = "product_type = 'variable'";
				$where[] = 'variation_removed > 0';
			} else {
				return array();
			}

			$status = isset( $filters['status'] ) ? sanitize_key( $filters['status'] ) : '';
			$search = isset( $filters['search'] ) ? sanitize_text_field( $filters['search'] ) : '';
			if ( in_array( $status, array( 'variation_added', 'missing_variations' ), true ) ) {
				$where[] = 'local_product_id > 0';
				$where[] = 'variation_added > 0';
			} elseif ( 'variation_removed' === $status ) {
				$where[] = 'local_product_id > 0';
				$where[] = 'variation_removed > 0';
			} elseif ( 'locked' === $status ) {
				$where[] = 'is_locked = 1';
			} elseif ( in_array( $status, array( 'existing', 'all_catalog' ), true ) && self::uses_catalog( $operation ) ) {
				if ( 'existing' === $status ) {
					$where[] = 'local_product_id > 0';
				}
			} elseif ( in_array( $status, array( 'new', 'update', 'local_changes' ), true ) ) {
				$where[]  = 'change_status = %s';
				$values[] = $status;
			}
			if ( '' !== $search ) {
				$where[]  = 'product_name LIKE %s';
				$values[] = '%' . $wpdb->esc_like( $search ) . '%';
			}

			$sql = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY id ASC';
			return $wpdb->get_results( $values ? $wpdb->prepare( $sql, $values ) : $sql );
		}
		if ( ! $uids ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $uids ), '%s' ) );
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE remote_uid IN ({$placeholders}) ORDER BY id ASC", $uids ) );
	}

	private static function uses_catalog( $operation ) {
		return in_array( $operation, array( 'update_main', 'update_variations', 'delete_variations' ), true );
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
		if ( 'update_main' === $operation ) {
			return (bool) $row->local_product_id;
		}
		if ( 'update_variations' === $operation ) {
			return (bool) $row->local_product_id && 'variable' === $row->product_type;
		}
		if ( 'delete_variations' === $operation ) {
			return (bool) $row->local_product_id && 'variable' === $row->product_type && (int) $row->variation_removed > 0;
		}
		return false;
	}

	private static function record_deleted_variations( $item, array $result ) {
		global $wpdb;
		$catalog = $wpdb->prefix . 'lwps_catalog';
		$changes = $wpdb->prefix . 'lwps_changes';
		$catalog_row = $wpdb->get_row( $wpdb->prepare( "SELECT is_locked FROM {$catalog} WHERE remote_uid = %s LIMIT 1", $item->remote_uid ) );
		$variation_added = isset( $result['variation_added'] ) ? (int) $result['variation_added'] : 0;
		$variation_removed = isset( $result['variation_removed'] ) ? (int) $result['variation_removed'] : 0;
		$catalog_status = self::status_for_variation_counts( $variation_added, $variation_removed, $catalog_row && (int) $catalog_row->is_locked > 0 );

		$catalog_update = array(
			'change_status'      => $catalog_status,
			'variation_added'    => $variation_added,
			'variation_updated'  => 0,
			'variation_removed'  => $variation_removed,
			'local_variations'   => isset( $result['local_variations'] ) ? (int) $result['local_variations'] : 0,
			'local_hash'         => isset( $result['local_hash'] ) ? sanitize_text_field( $result['local_hash'] ) : '',
			'analyzed_at'        => current_time( 'mysql', true ),
		);
		$wpdb->update(
			$catalog,
			$catalog_update,
			array( 'remote_uid' => $item->remote_uid ),
			array( '%s', '%d', '%d', '%d', '%d', '%s', '%s' ),
			array( '%s' )
		);

		$change = $wpdb->get_row( $wpdb->prepare( "SELECT id, is_locked FROM {$changes} WHERE remote_uid = %s LIMIT 1", $item->remote_uid ) );
		if ( ! $change ) {
			return;
		}
		if ( $variation_added > 0 || $variation_removed > 0 ) {
			$change_status = self::status_for_variation_counts( $variation_added, $variation_removed, (int) $change->is_locked > 0 );
			$change_update = $catalog_update;
			$change_update['change_status'] = $change_status;
			$wpdb->update(
				$changes,
				$change_update,
				array( 'id' => (int) $change->id ),
				array( '%s', '%d', '%d', '%d', '%d', '%s', '%s' ),
				array( '%d' )
			);
		} else {
			$wpdb->delete( $changes, array( 'id' => (int) $change->id ), array( '%d' ) );
		}
	}

	private static function status_for_variation_counts( $variation_added, $variation_removed, $is_locked ) {
		if ( $is_locked ) {
			return 'locked';
		}
		if ( (int) $variation_added > 0 ) {
			return 'missing_variations';
		}
		if ( (int) $variation_removed > 0 ) {
			return 'extra_variations';
		}
		return 'existing';
	}
}
