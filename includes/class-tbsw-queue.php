<?php
/**
 * TBSW_Queue: task state, locking, batch processing, log.
 *
 * State is stored in own options (no PHP sessions, no official plugin internals):
 *  - tbsw_task: current/last task (queue, counters, errors, heartbeat)
 *  - tbsw_lock: atomic single-run lock with heartbeat timeout
 *  - tbsw_log:  rotated journal (no secrets, no HTML payloads)
 *  - tbsw_settings: excluded page ids, batch size
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TBSW_Queue {

	const OPTION_TASK     = 'tbsw_task';
	const OPTION_LOCK     = 'tbsw_lock';
	const OPTION_LOG      = 'tbsw_log';
	const OPTION_SETTINGS = 'tbsw_settings';

	const LOCK_TTL_SECONDS   = 300;
	const BATCH_TIME_CAP     = 25;
	const LOG_MAX_LINES      = 200;
	const DEFAULT_BATCH_SIZE = 3;

	/* ---------------------------------------------------------------- settings */

	/**
	 * Wrapper settings with defaults.
	 *
	 * @return array { project_ids: int[], excluded_page_ids: int[], batch_size: int }
	 */
	public static function get_settings() {
		$settings = get_option( self::OPTION_SETTINGS, array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}
		return array(
			'project_ids'       => isset( $settings['project_ids'] ) ? array_map( 'intval', (array) $settings['project_ids'] ) : array(),
			'excluded_page_ids' => isset( $settings['excluded_page_ids'] ) ? array_map( 'intval', (array) $settings['excluded_page_ids'] ) : array(),
			'batch_size'        => isset( $settings['batch_size'] ) ? max( 1, min( 10, intval( $settings['batch_size'] ) ) ) : self::DEFAULT_BATCH_SIZE,
		);
	}

	/**
	 * @param array $settings Partial settings.
	 */
	public static function update_settings( $settings ) {
		$current = self::get_settings();
		if ( isset( $settings['project_ids'] ) ) {
			$current['project_ids'] = array_map( 'intval', (array) $settings['project_ids'] );
		}
		if ( isset( $settings['excluded_page_ids'] ) ) {
			$current['excluded_page_ids'] = array_map( 'intval', (array) $settings['excluded_page_ids'] );
		}
		if ( isset( $settings['batch_size'] ) ) {
			$current['batch_size'] = max( 1, min( 10, intval( $settings['batch_size'] ) ) );
		}
		update_option( self::OPTION_SETTINGS, $current );
		return $current;
	}

	/* ---------------------------------------------------------------- lock */

	/**
	 * Atomic lock acquire.
	 *
	 * @param string $task_id Task identifier.
	 * @return bool True when lock acquired.
	 */
	public static function acquire_lock( $task_id ) {
		self::release_stale_lock();
		return add_option( self::OPTION_LOCK, array( 'ts' => time(), 'task_id' => $task_id ), '', false );
	}

	/**
	 * Release lock unconditionally (deactivation, task end).
	 */
	public static function release_lock() {
		delete_option( self::OPTION_LOCK );
	}

	/**
	 * Drop lock when its heartbeat is older than TTL (crashed run protection).
	 */
	private static function release_stale_lock() {
		$lock = get_option( self::OPTION_LOCK, false );
		if ( is_array( $lock ) && isset( $lock['ts'] ) && ( time() - intval( $lock['ts'] ) ) > self::LOCK_TTL_SECONDS ) {
			delete_option( self::OPTION_LOCK );
			$task = self::get_task();
			if ( $task && 'running' === $task['status'] ) {
				$task['status'] = 'error';
				$task['errors'][] = array(
					'title'   => __( 'Task', 'tilda-bulk-sync-wrapper' ),
					'message' => __( 'Previous run lost its heartbeat; lock released as stale.', 'tilda-bulk-sync-wrapper' ),
				);
				self::save_task( $task );
				self::log( 'lock released as stale' );
			}
		}
	}

	/**
	 * @return array|false Current lock payload.
	 */
	public static function get_lock() {
		$lock = get_option( self::OPTION_LOCK, false );
		return is_array( $lock ) ? $lock : false;
	}

	/* ---------------------------------------------------------------- task */

	/**
	 * @return array|null Task state or null.
	 */
	public static function get_task() {
		$task = get_option( self::OPTION_TASK, null );
		return is_array( $task ) ? $task : null;
	}

	/**
	 * @param array $task Task state.
	 */
	public static function save_task( $task ) {
		update_option( self::OPTION_TASK, $task, false );
	}

	/**
	 * Start a new task; caller must hold the lock.
	 *
	 * @param string $type    sync|import.
	 * @param array  $queue   Queue items.
	 * @param array  $meta    Extra: skipped counters, make_front_page, preview counters.
	 * @return array Task state.
	 */
	public static function start_task( $type, $queue, $meta = array() ) {
		delete_option( 'tbsw_cancel_flag' );
		$task = array(
			'id'              => uniqid( 'tbsw_' ),
			'type'            => ( 'import' === $type ) ? 'import' : 'sync',
			'status'          => 'running',
			'started_at'      => time(),
			'finished_at'     => 0,
			'heartbeat'       => time(),
			'make_front_page' => ! empty( $meta['make_front_page'] ),
			'queue'           => $queue,
			'counters'        => array(
				'total'     => count( $queue ) + intval( isset( $meta['skipped'] ) ? $meta['skipped'] : 0 ),
				'queued'    => count( $queue ),
				'skipped'   => intval( isset( $meta['skipped'] ) ? $meta['skipped'] : 0 ),
				'processed' => 0,
				'success'   => 0,
				'errors'    => 0,
			),
			'errors'          => array(),
		);
		self::save_task( $task );
		self::log( sprintf( 'task %s started: type=%s queued=%d skipped=%d', $task['id'], $task['type'], count( $queue ), $task['counters']['skipped'] ) );
		return $task;
	}

	/**
	 * Mark task cancelled (safe: only state change).
	 *
	 * @param string $reason Reason for journal.
	 */
	public static function cancel_task( $reason = '' ) {
		$task = self::get_task();
		if ( $task && 'running' === $task['status'] ) {
			$task['status']      = 'cancelled';
			$task['finished_at'] = time();
			self::save_task( $task );
			self::log( sprintf( 'task %s cancelled: %s', $task['id'], $reason ) );
		}
	}

	/**
	 * Process one batch of the running task.
	 *
	 * @return array { task, batch_processed, done }
	 */
	public static function process_batch() {
		$task = self::get_task();
		if ( ! $task || 'running' !== $task['status'] ) {
			return array(
				'task'            => $task,
				'batch_processed' => 0,
				'done'            => true,
			);
		}
		$settings = self::get_settings();
		$batch    = max( 1, min( 10, intval( $settings['batch_size'] ) ) );
		$start    = time();
		$done_now = 0;

		while ( $done_now < $batch && ! empty( $task['queue'] ) && ( time() - $start ) < self::BATCH_TIME_CAP ) {
			$cancelled = self::cancel_requested();
			if ( $cancelled ) {
				break;
			}
			$item = array_shift( $task['queue'] );
			$done_now++;
			self::process_item( $task, $item );
			$task['counters']['processed']++;
			$task['heartbeat'] = time();
			self::save_task( $task );
			$lock = array( 'ts' => time(), 'task_id' => $task['id'] );
			update_option( self::OPTION_LOCK, $lock, false );
		}

		if ( self::cancel_requested() ) {
			$task['status']      = 'cancelled';
			$task['finished_at'] = time();
			delete_option( 'tbsw_cancel_flag' );
			self::save_task( $task );
			self::release_lock();
			self::log( sprintf( 'task %s cancelled by user at processed=%d', $task['id'], $task['counters']['processed'] ) );
		} elseif ( empty( $task['queue'] ) ) {
			$task['status']      = 'finished';
			$task['finished_at'] = time();
			self::save_task( $task );
			self::release_lock();
			self::log( sprintf( 'task %s finished: success=%d errors=%d skipped=%d', $task['id'], $task['counters']['success'], $task['counters']['errors'], $task['counters']['skipped'] ) );
		}

		return array(
			'task'            => $task,
			'batch_processed' => $done_now,
			'done'            => ( 'running' !== $task['status'] ),
		);
	}

	/**
	 * Process a single queue item, updating task counters/errors in place.
	 *
	 * @param array $task Task state (by reference).
	 * @param array $item Queue item.
	 */
	private static function process_item( &$task, $item ) {
		$kind = isset( $item['kind'] ) ? $item['kind'] : 'sync';
		$post_id = isset( $item['post_id'] ) ? intval( $item['post_id'] ) : 0;
		$collision_resolved = false;
		$slug_used = '';

		if ( 'create' === $kind ) {
			$created = TBSW_Core::create_post_for_page( $item, ! empty( $task['make_front_page'] ) );
			if ( is_wp_error( $created ) ) {
				$task['counters']['errors']++;
				$task['errors'][] = array(
					'title'   => $item['title'],
					'message' => sprintf(
						/* translators: %s: error message. */
						__( 'Cannot create page: %s', 'tilda-bulk-sync-wrapper' ),
						implode( ' | ', $created->get_error_messages() )
					),
				);
				self::log( sprintf( 'create failed page_id=%d: %s', $item['page_id'], implode( ' | ', $created->get_error_messages() ) ) );
				return;
			}
			$post_id            = $created['post_id'];
			$collision_resolved = $created['collision_resolved'];
			$slug_used          = $created['slug_used'];
			$item['post_id']    = $post_id;
		}

		if ( $post_id <= 0 ) {
			$task['counters']['errors']++;
			$task['errors'][] = array(
				'title'   => $item['title'],
				'message' => __( 'No WordPress post bound to this Tilda page.', 'tilda-bulk-sync-wrapper' ),
			);
			return;
		}

		$pending = isset( $item['pending'] ) && is_array( $item['pending'] ) ? $item['pending'] : array();
		$result  = TBSW_Core::sync_page( $item['page_id'], $item['project_id'], $post_id, $pending );

		foreach ( $result['warnings'] as $warning ) {
			self::log( sprintf( 'warning page_id=%d post_id=%d: %s', $item['page_id'], $post_id, $warning ) );
		}

		if ( 'error' === $result['status'] ) {
			$task['counters']['errors']++;
			$task['errors'][] = array(
				'title'   => $item['title'],
				'message' => implode( ' | ', $result['messages'] ),
			);
			self::log( sprintf( 'sync failed page_id=%d post_id=%d: %s', $item['page_id'], $post_id, implode( ' | ', $result['messages'] ) ) );
			return;
		}

		if ( 'partial' === $result['status'] ) {
			$item['pending'] = $result['pending'];
			$task['queue'][] = $item;
			self::log( sprintf( 'sync partial page_id=%d post_id=%d pending=%d', $item['page_id'], $post_id, count( $result['pending'] ) ) );
			return;
		}

		$task['counters']['success']++;
		TBSW_Core::ensure_page_template( $post_id );
		$note = '';
		if ( $collision_resolved ) {
			/* translators: %s: slug used. */
			$note = ' ' . sprintf( __( 'Slug collision resolved by WordPress suffix: %s', 'tilda-bulk-sync-wrapper' ), $slug_used );
		}
		self::log( sprintf( 'sync ok kind=%s page_id=%d post_id=%d files=%d%s', $kind, $item['page_id'], $post_id, $result['downloaded'], $note ) );
	}

	/* ---------------------------------------------------------------- cancel flag */

	/**
	 * Request cancellation (checked between queue items).
	 */
	public static function request_cancel() {
		update_option( 'tbsw_cancel_flag', time(), false );
	}

	/**
	 * @return bool
	 */
	public static function cancel_requested() {
		return false !== get_option( 'tbsw_cancel_flag', false );
	}

	/* ---------------------------------------------------------------- log */

	/**
	 * Append a journal line (rotated, no secrets/HTML).
	 *
	 * @param string $message Plain message.
	 */
	public static function log( $message ) {
		$log   = get_option( self::OPTION_LOG, array() );
		$log   = is_array( $log ) ? $log : array();
		$log[] = array( 'ts' => current_time( 'mysql' ), 'message' => wp_strip_all_tags( $message ) );
		if ( count( $log ) > self::LOG_MAX_LINES ) {
			$log = array_slice( $log, - self::LOG_MAX_LINES );
		}
		update_option( self::OPTION_LOG, $log, false );
	}

	/**
	 * @return array Journal lines, newest last.
	 */
	public static function get_log() {
		$log = get_option( self::OPTION_LOG, array() );
		return is_array( $log ) ? $log : array();
	}
}
