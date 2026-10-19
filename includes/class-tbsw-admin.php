<?php
/**
 * TBSW_Admin: admin screen, AJAX endpoints, settings, i18n.
 *
 * All privileged actions: current_user_can( 'manage_options' ) + nonce.
 * All output escaped; all input sanitized.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TBSW_Admin {

	const NONCE_ACTION = 'tbsw_ajax';

	public static function init() {
		load_plugin_textdomain( 'tilda-bulk-sync-wrapper', false, dirname( TBSW_PLUGIN_BASENAME ) . '/languages' );
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );

		add_action( 'wp_ajax_tbsw_preview_import', array( __CLASS__, 'ajax_preview_import' ) );
		add_action( 'wp_ajax_tbsw_start', array( __CLASS__, 'ajax_start' ) );
		add_action( 'wp_ajax_tbsw_batch', array( __CLASS__, 'ajax_batch' ) );
		add_action( 'wp_ajax_tbsw_status', array( __CLASS__, 'ajax_status' ) );
		add_action( 'wp_ajax_tbsw_cancel', array( __CLASS__, 'ajax_cancel' ) );
		add_action( 'wp_ajax_tbsw_save_settings', array( __CLASS__, 'ajax_save_settings' ) );
	}

	public static function admin_menu() {
		add_submenu_page(
			'tools.php',
			__( 'Tilda Bulk Sync', 'tilda-bulk-sync-wrapper' ),
			__( 'Tilda Bulk Sync', 'tilda-bulk-sync-wrapper' ),
			'manage_options',
			'tbsw-sync',
			array( __CLASS__, 'render_screen' )
		);
	}

	public static function enqueue( $hook ) {
		if ( 'tools_page_tbsw-sync' !== $hook ) {
			return;
		}
		wp_enqueue_style( 'tbsw-admin', TBSW_PLUGIN_URL . 'assets/tbsw-admin.css', array(), TBSW_VERSION );
		wp_enqueue_script( 'tbsw-admin', TBSW_PLUGIN_URL . 'assets/tbsw-admin.js', array(), TBSW_VERSION, true );
		wp_localize_script(
			'tbsw-admin',
			'tbswData',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
				'i18n'    => array(
					'confirmSync'   => __( 'Start bulk synchronization of all published Tilda landings?', 'tilda-bulk-sync-wrapper' ),
					'confirmImport' => __( 'Start import of published Tilda pages into WordPress?', 'tilda-bulk-sync-wrapper' ),
					'running'       => __( 'Task is running…', 'tilda-bulk-sync-wrapper' ),
					'finished'      => __( 'Task finished.', 'tilda-bulk-sync-wrapper' ),
					'cancelled'     => __( 'Task cancelled.', 'tilda-bulk-sync-wrapper' ),
					'error'         => __( 'Error', 'tilda-bulk-sync-wrapper' ),
					'processed'     => __( 'Processed', 'tilda-bulk-sync-wrapper' ),
					'success'       => __( 'Success', 'tilda-bulk-sync-wrapper' ),
					'skipped'       => __( 'Skipped', 'tilda-bulk-sync-wrapper' ),
					'errors'        => __( 'Errors', 'tilda-bulk-sync-wrapper' ),
					'create'        => __( 'create', 'tilda-bulk-sync-wrapper' ),
					'update'        => __( 'update', 'tilda-bulk-sync-wrapper' ),
					'skip'          => __( 'skip', 'tilda-bulk-sync-wrapper' ),
					'yes'           => __( 'yes', 'tilda-bulk-sync-wrapper' ),
					'no'            => __( 'no', 'tilda-bulk-sync-wrapper' ),
				),
			)
		);
	}

	/* ---------------------------------------------------------------- screen */

	public static function render_screen() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'tilda-bulk-sync-wrapper' ) );
		}
		$issues    = TBSW_Core::compatibility_issues();
		$settings  = TBSW_Queue::get_settings();
		$projects  = TBSW_Core::get_available_projects();
		$linked    = TBSW_Core::get_linked_published_posts( $settings['project_ids'] );
		$task      = TBSW_Queue::get_task();
		$lock      = TBSW_Queue::get_lock();
		$log       = array_slice( TBSW_Queue::get_log(), -50 );
		?>
		<div class="wrap tbsw-wrap">
			<h1><?php esc_html_e( 'Tilda Bulk Sync Wrapper', 'tilda-bulk-sync-wrapper' ); ?></h1>

			<h2><?php esc_html_e( 'Official plugin status', 'tilda-bulk-sync-wrapper' ); ?></h2>
			<?php if ( empty( $issues ) ) : ?>
				<p class="tbsw-ok"><?php esc_html_e( 'Official Tilda Publishing plugin is active and compatible.', 'tilda-bulk-sync-wrapper' ); ?></p>
			<?php else : ?>
				<div class="notice notice-error inline"><p>
				<?php
				foreach ( $issues as $issue ) {
					echo esc_html( $issue ) . '<br>';
				}
				?>
				</p></div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Tilda projects', 'tilda-bulk-sync-wrapper' ); ?></h2>
			<?php if ( empty( $projects ) ) : ?>
				<p><?php esc_html_e( 'No projects found. Add an API key and sync projects in the official Tilda Publishing plugin settings first.', 'tilda-bulk-sync-wrapper' ); ?></p>
			<?php else : ?>
				<p><?php esc_html_e( 'Select which Tilda project(s) this site should work with. Both tasks below use only the selected projects. If nothing is selected, all enabled projects are used.', 'tilda-bulk-sync-wrapper' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Projects', 'tilda-bulk-sync-wrapper' ); ?></th>
						<td>
							<?php foreach ( $projects as $project ) : ?>
								<p>
									<label>
										<input type="checkbox" class="tbsw-project-cb" value="<?php echo esc_attr( $project['id'] ); ?>" <?php checked( in_array( $project['id'], $settings['project_ids'], true ) ); ?> <?php disabled( ! $project['enabled'] ); ?>>
										<?php echo esc_html( $project['title'] ? $project['title'] : __( '(untitled)', 'tilda-bulk-sync-wrapper' ) ); ?>
										<code><?php echo esc_html( $project['id'] ); ?></code>
										<?php if ( ! $project['enabled'] ) : ?>
											<em><?php esc_html_e( 'disabled in official plugin', 'tilda-bulk-sync-wrapper' ); ?></em>
										<?php endif; ?>
									</label>
								</p>
							<?php endforeach; ?>
							<p><button type="button" class="button" id="tbsw-save-projects"><?php esc_html_e( 'Save selected projects', 'tilda-bulk-sync-wrapper' ); ?></button> <span id="tbsw-projects-msg"></span></p>
						</td>
					</tr>
				</table>
			<?php endif; ?>

			<?php if ( $lock ) : ?>
				<div class="notice notice-warning inline"><p>
					<?php
					/* translators: %s: task id. */
					echo esc_html( sprintf( __( 'A task is currently running (lock held): %s. Wait for it to finish or cancel it below.', 'tilda-bulk-sync-wrapper' ), isset( $lock['task_id'] ) ? $lock['task_id'] : '?' ) );
					?>
				</p></div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Task 1. Bulk synchronization', 'tilda-bulk-sync-wrapper' ); ?></h2>
			<p>
				<?php
				/* translators: %d: number of linked published landings. */
				echo esc_html( sprintf( __( 'Published landings bound to Tilda: %d', 'tilda-bulk-sync-wrapper' ), count( $linked ) ) );
				?>
			</p>
			<p><button type="button" class="button button-primary" id="tbsw-start-sync" <?php disabled( ! empty( $issues ) || $lock ); ?>><?php esc_html_e( 'Synchronize all published Tilda landings', 'tilda-bulk-sync-wrapper' ); ?></button></p>

			<h2><?php esc_html_e( 'Task 2. Import all published pages of the Tilda project', 'tilda-bulk-sync-wrapper' ); ?></h2>
			<p><button type="button" class="button" id="tbsw-preview" <?php disabled( ! empty( $issues ) || $lock ); ?>><?php esc_html_e( 'Build preview of pages to import', 'tilda-bulk-sync-wrapper' ); ?></button></p>
			<div id="tbsw-preview-box" style="display:none;">
				<p id="tbsw-preview-counters"></p>
				<table class="widefat striped" id="tbsw-preview-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Include', 'tilda-bulk-sync-wrapper' ); ?></th>
							<th><?php esc_html_e( 'Title', 'tilda-bulk-sync-wrapper' ); ?></th>
							<th><?php esc_html_e( 'Tilda ID', 'tilda-bulk-sync-wrapper' ); ?></th>
							<th><?php esc_html_e( 'Address', 'tilda-bulk-sync-wrapper' ); ?></th>
							<th><?php esc_html_e( 'Action', 'tilda-bulk-sync-wrapper' ); ?></th>
							<th><?php esc_html_e( 'Future slug', 'tilda-bulk-sync-wrapper' ); ?></th>
						</tr>
					</thead>
					<tbody></tbody>
				</table>
				<p>
					<label><input type="checkbox" id="tbsw-make-front"> <?php esc_html_e( 'Make the Tilda index page the site front page (Settings → Reading)', 'tilda-bulk-sync-wrapper' ); ?></label>
				</p>
				<p><button type="button" class="button button-primary" id="tbsw-start-import"><?php esc_html_e( 'Import all published pages from Tilda', 'tilda-bulk-sync-wrapper' ); ?></button></p>
			</div>

			<h2><?php esc_html_e( 'Progress', 'tilda-bulk-sync-wrapper' ); ?></h2>
			<div id="tbsw-progress-box">
				<p id="tbsw-progress-line"><?php esc_html_e( 'No running task.', 'tilda-bulk-sync-wrapper' ); ?></p>
				<div id="tbsw-progress-bar" style="display:none;"><div></div></div>
				<p><button type="button" class="button" id="tbsw-cancel" style="display:none;"><?php esc_html_e( 'Cancel task', 'tilda-bulk-sync-wrapper' ); ?></button></p>
			</div>

			<h2><?php esc_html_e( 'Last task report', 'tilda-bulk-sync-wrapper' ); ?></h2>
			<?php if ( $task ) : ?>
				<p>
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: task type, 2: status, 3: date. */
							__( 'Type: %1$s. Status: %2$s. Started: %3$s.', 'tilda-bulk-sync-wrapper' ),
							$task['type'],
							$task['status'],
							wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), intval( $task['started_at'] ) )
						)
					);
					?>
				</p>
				<ul>
					<li><?php echo esc_html( sprintf( /* translators: %d: count. */ __( 'Total: %d', 'tilda-bulk-sync-wrapper' ), intval( $task['counters']['total'] ) ) ); ?></li>
					<li><?php echo esc_html( sprintf( /* translators: %d: count. */ __( 'Processed: %d', 'tilda-bulk-sync-wrapper' ), intval( $task['counters']['processed'] ) ) ); ?></li>
					<li><?php echo esc_html( sprintf( /* translators: %d: count. */ __( 'Success: %d', 'tilda-bulk-sync-wrapper' ), intval( $task['counters']['success'] ) ) ); ?></li>
					<li><?php echo esc_html( sprintf( /* translators: %d: count. */ __( 'Skipped: %d', 'tilda-bulk-sync-wrapper' ), intval( $task['counters']['skipped'] ) ) ); ?></li>
					<li><?php echo esc_html( sprintf( /* translators: %d: count. */ __( 'Errors: %d', 'tilda-bulk-sync-wrapper' ), intval( $task['counters']['errors'] ) ) ); ?></li>
				</ul>
				<?php if ( ! empty( $task['errors'] ) ) : ?>
					<table class="widefat striped">
						<thead><tr><th><?php esc_html_e( 'Page', 'tilda-bulk-sync-wrapper' ); ?></th><th><?php esc_html_e( 'Error', 'tilda-bulk-sync-wrapper' ); ?></th></tr></thead>
						<tbody>
						<?php foreach ( $task['errors'] as $err ) : ?>
							<tr><td><?php echo esc_html( $err['title'] ); ?></td><td><?php echo esc_html( $err['message'] ); ?></td></tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			<?php else : ?>
				<p><?php esc_html_e( 'No tasks have been run yet.', 'tilda-bulk-sync-wrapper' ); ?></p>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Journal (last 50 lines)', 'tilda-bulk-sync-wrapper' ); ?></h2>
			<?php if ( empty( $log ) ) : ?>
				<p><?php esc_html_e( 'Journal is empty.', 'tilda-bulk-sync-wrapper' ); ?></p>
			<?php else : ?>
				<ul class="tbsw-log">
				<?php foreach ( array_reverse( $log ) as $line ) : ?>
					<li><code><?php echo esc_html( $line['ts'] ); ?></code> <?php echo esc_html( $line['message'] ); ?></li>
				<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Settings', 'tilda-bulk-sync-wrapper' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="tbsw-excluded"><?php esc_html_e( 'Never import these Tilda page IDs (comma separated)', 'tilda-bulk-sync-wrapper' ); ?></label></th>
					<td><input type="text" class="regular-text" id="tbsw-excluded" value="<?php echo esc_attr( implode( ',', $settings['excluded_page_ids'] ) ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="tbsw-batch"><?php esc_html_e( 'Batch size (pages per request, 1–10)', 'tilda-bulk-sync-wrapper' ); ?></label></th>
					<td><input type="number" min="1" max="10" id="tbsw-batch" value="<?php echo esc_attr( $settings['batch_size'] ); ?>"></td>
				</tr>
			</table>
			<p><button type="button" class="button" id="tbsw-save-settings"><?php esc_html_e( 'Save settings', 'tilda-bulk-sync-wrapper' ); ?></button> <span id="tbsw-settings-msg"></span></p>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------- ajax helpers */

	private static function guard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'error' => __( 'Forbidden.', 'tilda-bulk-sync-wrapper' ) ), 403 );
		}
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
	}

	private static function task_payload( $task ) {
		if ( ! $task ) {
			return array( 'status' => 'idle' );
		}
		return array(
			'id'        => $task['id'],
			'type'      => $task['type'],
			'status'    => $task['status'],
			'counters'  => $task['counters'],
			'errors'    => $task['errors'],
			'queued'    => count( $task['queue'] ),
		);
	}

	/* ---------------------------------------------------------------- ajax endpoints */

	public static function ajax_preview_import() {
		self::guard();
		$settings = TBSW_Queue::get_settings();
		$plan     = TBSW_Core::build_import_plan( $settings['excluded_page_ids'] );
		set_transient( 'tbsw_preview', $plan, 15 * MINUTE_IN_SECONDS );
		wp_send_json_success(
			array(
				'pages'    => $plan['pages'],
				'counters' => $plan['counters'],
				'errors'   => $plan['errors'],
			)
		);
	}

	public static function ajax_start() {
		self::guard();
		$type = isset( $_POST['task'] ) ? sanitize_key( wp_unslash( $_POST['task'] ) ) : '';
		if ( ! in_array( $type, array( 'sync', 'import' ), true ) ) {
			wp_send_json_error( array( 'error' => __( 'Unknown task type.', 'tilda-bulk-sync-wrapper' ) ), 422 );
		}
		$issues = TBSW_Core::compatibility_issues();
		if ( ! empty( $issues ) ) {
			wp_send_json_error( array( 'error' => implode( ' ', $issues ) ), 422 );
		}
		if ( ! TBSW_Queue::acquire_lock( 'pending' ) ) {
			wp_send_json_error( array( 'error' => __( 'Another task is already running. Wait for it to finish or cancel it.', 'tilda-bulk-sync-wrapper' ) ), 409 );
		}

		$settings = TBSW_Queue::get_settings();
		$queue    = array();
		$skipped  = 0;
		$meta     = array();

		if ( 'sync' === $type ) {
			foreach ( TBSW_Core::get_linked_published_posts( $settings['project_ids'] ) as $row ) {
				$queue[] = array(
					'kind'       => 'sync',
					'page_id'    => $row['page_id'],
					'project_id' => $row['project_id'],
					'post_id'    => $row['post_id'],
					'title'      => $row['title'],
				);
			}
		} else {
			$extra = array();
			if ( isset( $_POST['excluded_extra'] ) && is_array( $_POST['excluded_extra'] ) ) {
				$extra = array_map( 'intval', wp_unslash( $_POST['excluded_extra'] ) );
			}
			$excluded            = array_unique( array_merge( $settings['excluded_page_ids'], $extra ) );
			$plan                = TBSW_Core::build_import_plan( $excluded );
			$meta['skipped']     = $plan['counters']['skip'];
			$meta['make_front_page'] = isset( $_POST['make_front_page'] ) && '1' === $_POST['make_front_page'];
			foreach ( $plan['pages'] as $row ) {
				if ( 'skip' === $row['action'] ) {
					continue;
				}
				$queue[] = array(
					'kind'       => ( 'create' === $row['action'] ) ? 'create' : 'sync',
					'page_id'    => $row['page_id'],
					'project_id' => $row['project_id'],
					'post_id'    => $row['post_id'],
					'title'      => $row['title'],
					'slug'       => $row['slug'],
					'is_index'   => ! empty( $row['is_index'] ),
				);
			}
		}

		if ( empty( $queue ) ) {
			TBSW_Queue::release_lock();
			wp_send_json_error( array( 'error' => __( 'Nothing to process: no published Tilda-bound landings or published Tilda pages found.', 'tilda-bulk-sync-wrapper' ) ), 422 );
		}

		$task = TBSW_Queue::start_task( $type, $queue, $meta );
		$lock = array( 'ts' => time(), 'task_id' => $task['id'] );
		update_option( TBSW_Queue::OPTION_LOCK, $lock, false );
		wp_send_json_success( array( 'task' => self::task_payload( $task ) ) );
	}

	public static function ajax_batch() {
		self::guard();
		$task = TBSW_Queue::get_task();
		if ( ! $task || 'running' !== $task['status'] ) {
			wp_send_json_success(
				array(
					'task' => self::task_payload( $task ),
					'done' => true,
				)
			);
		}
		$lock = TBSW_Queue::get_lock();
		if ( ! $lock || ( isset( $lock['task_id'] ) && $lock['task_id'] !== $task['id'] ) ) {
			wp_send_json_error( array( 'error' => __( 'Lock lost or belongs to another task.', 'tilda-bulk-sync-wrapper' ) ), 409 );
		}
		$result = TBSW_Queue::process_batch();
		wp_send_json_success(
			array(
				'task' => self::task_payload( $result['task'] ),
				'done' => $result['done'],
			)
		);
	}

	public static function ajax_status() {
		self::guard();
		$task = TBSW_Queue::get_task();
		wp_send_json_success(
			array(
				'task' => self::task_payload( $task ),
				'lock' => TBSW_Queue::get_lock(),
			)
		);
	}

	public static function ajax_cancel() {
		self::guard();
		TBSW_Queue::request_cancel();
		wp_send_json_success( array( 'requested' => true ) );
	}

	public static function ajax_save_settings() {
		self::guard();
		$raw_excluded = isset( $_POST['excluded'] ) ? sanitize_text_field( wp_unslash( $_POST['excluded'] ) ) : '';
		$ids          = array();
		foreach ( explode( ',', $raw_excluded ) as $part ) {
			$part = trim( $part );
			if ( '' !== $part && ctype_digit( $part ) ) {
				$ids[] = intval( $part );
			}
		}
		$batch = isset( $_POST['batch_size'] ) ? intval( $_POST['batch_size'] ) : TBSW_Queue::DEFAULT_BATCH_SIZE;
		$new   = array(
			'excluded_page_ids' => array_unique( $ids ),
			'batch_size'        => $batch,
		);
		if ( isset( $_POST['project_ids'] ) && is_array( $_POST['project_ids'] ) ) {
			$new['project_ids'] = array_map( 'intval', wp_unslash( $_POST['project_ids'] ) );
		}
		TBSW_Queue::update_settings( $new );
		wp_send_json_success( array( 'settings' => TBSW_Queue::get_settings() ) );
	}
}
