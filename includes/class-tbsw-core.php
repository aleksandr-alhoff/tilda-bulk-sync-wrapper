<?php
/**
 * TBSW_Core: discovery, import plan, adapter to the official plugin sync routine.
 *
 * Reuses official Tilda Publishing public static API only:
 *  - Tilda::get_local_map()/get_local_keys()/get_local_projects()/get_local_project()
 *  - Tilda::get_pageslist()/get_projectinfo() (Tilda API getPagesList/getProjectInfo)
 *  - Tilda_Admin::export_tilda_page() + Tilda::getRemoteFile() for file download
 * The official plugin files are never modified.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TBSW_Core {

	/** Seconds a single download chunk may run (same order as official plugin's 20s). */
	const DOWNLOAD_CHUNK_SECONDS = 20;

	/**
	 * Load official plugin admin class when needed (cron/AJAX context),
	 * mirroring Tilda::sync_single_event() behaviour.
	 */
	public static function ensure_official_classes() {
		if ( ! defined( 'TILDA_PLUGIN_DIR' ) ) {
			return false;
		}
		if ( ! class_exists( 'Tilda', false ) ) {
			require_once TILDA_PLUGIN_DIR . 'class.tilda.php';
		}
		if ( ! class_exists( 'Tilda_Admin', false ) ) {
			require_once TILDA_PLUGIN_DIR . 'class.tilda-admin.php';
		}
		return true;
	}

	/**
	 * Is the official Tilda Publishing plugin active?
	 *
	 * @return bool
	 */
	public static function official_plugin_active() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		return is_plugin_active( TBSW_OFFICIAL_BASENAME );
	}

	/**
	 * Page template enforced on every page created or synced by this plugin:
	 * "FW Fullwidth No Header Footer" from the Fullwidth Templates plugin.
	 */
	const PAGE_TEMPLATE = 'template-page-builder-no-header-footer.php';

	/**
	 * Whether the required Fullwidth Templates plugin is active.
	 *
	 * @return bool
	 */
	public static function fpt_plugin_active() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		return is_plugin_active( TBSW_FPT_BASENAME );
	}

	/**
	 * Force the required fullwidth template on a page.
	 *
	 * @param int $post_id WP post id.
	 * @return bool True when the template was set.
	 */
	public static function ensure_page_template( $post_id ) {
		$post_id = intval( $post_id );
		if ( $post_id <= 0 || ! self::fpt_plugin_active() ) {
			return false;
		}
		update_post_meta( $post_id, '_wp_page_template', self::PAGE_TEMPLATE );
		return true;
	}

	/**
	 * Compatibility probe: list of missing classes/methods of the official plugin.
	 *
	 * @return array Empty array = compatible.
	 */
	public static function compatibility_issues() {
		$issues = array();
		if ( ! self::fpt_plugin_active() ) {
			$issues[] = __( 'Required plugin "Fullwidth Templates for Any Theme & Page Builder" is not active. Install and activate it first: https://ru.wordpress.org/plugins/fullwidth-templates/', 'tilda-bulk-sync-wrapper' );
		}
		if ( ! self::official_plugin_active() ) {
			$issues[] = __( 'Official plugin Tilda Publishing is not active.', 'tilda-bulk-sync-wrapper' );
			return $issues;
		}
		self::ensure_official_classes();
		$required = array(
			array( 'class' => 'Tilda', 'method' => null ),
			array( 'class' => 'Tilda', 'method' => 'get_local_map' ),
			array( 'class' => 'Tilda', 'method' => 'get_local_keys' ),
			array( 'class' => 'Tilda', 'method' => 'get_local_projects' ),
			array( 'class' => 'Tilda', 'method' => 'get_pageslist' ),
			array( 'class' => 'Tilda', 'method' => 'get_projectinfo' ),
			array( 'class' => 'Tilda', 'method' => 'getRemoteFile' ),
			array( 'class' => 'Tilda_Admin', 'method' => 'export_tilda_page' ),
			array( 'class' => 'Tilda_Admin', 'method' => 'update_local_map' ),
		);
		foreach ( $required as $req ) {
			if ( ! class_exists( $req['class'], false ) ) {
				/* translators: %s: class name. */
				$issues[] = sprintf( __( 'Missing class %s in official plugin.', 'tilda-bulk-sync-wrapper' ), $req['class'] );
				continue;
			}
			if ( $req['method'] && ! method_exists( $req['class'], $req['method'] ) ) {
				/* translators: 1: method name, 2: class name. */
				$issues[] = sprintf( __( 'Missing method %1$s in class %2$s of official plugin.', 'tilda-bulk-sync-wrapper' ), $req['method'], $req['class'] );
			}
		}
		return $issues;
	}

	/**
	 * Task 1 source: published WP posts reliably bound to Tilda.
	 *
	 * @param array $project_ids Only posts of these projects (empty = all selected projects).
	 * @return array List of array( post_id, page_id, project_id, title ).
	 */
	public static function get_linked_published_posts( $project_ids = array() ) {
		self::ensure_official_classes();
		$result = array();
		$map    = Tilda::get_local_map( Tilda::MAP_PAGE_POSTS );
		if ( ! is_array( $map ) ) {
			return $result;
		}
		$project_ids = array_map( 'intval', (array) $project_ids );
		foreach ( $map as $page_id => $post_id ) {
			$post_id = intval( $post_id );
			$post    = get_post( $post_id );
			if ( ! $post || 'publish' !== $post->post_status ) {
				continue;
			}
			$meta = get_post_meta( $post_id, '_tilda', true );
			if ( ! is_array( $meta ) || empty( $meta['status'] ) || 'on' !== $meta['status'] ) {
				continue;
			}
			$project_id = isset( $meta['project_id'] ) ? intval( $meta['project_id'] ) : 0;
			if ( ! empty( $project_ids ) && ! in_array( $project_id, $project_ids, true ) ) {
				continue;
			}
			$result[] = array(
				'post_id'    => $post_id,
				'page_id'    => intval( $page_id ),
				'project_id' => $project_id,
				'title'      => $post->post_title,
			);
		}
		return $result;
	}

	/**
	 * All projects known to the official plugin (from its local cache).
	 *
	 * @return array List of array( id, title, enabled ).
	 */
	public static function get_available_projects() {
		self::ensure_official_classes();
		$list     = array();
		$projects = Tilda::get_local_projects();
		if ( ! is_array( $projects ) ) {
			return $list;
		}
		foreach ( $projects as $project_id => $project ) {
			$title = '';
			if ( is_object( $project ) && isset( $project->title ) ) {
				$title = $project->title;
			} elseif ( is_array( $project ) && isset( $project['title'] ) ) {
				$title = $project['title'];
			}
			$enabled = true;
			if ( is_object( $project ) && isset( $project->enabled ) && false === $project->enabled ) {
				$enabled = false;
			} elseif ( is_array( $project ) && isset( $project['enabled'] ) && false === $project['enabled'] ) {
				$enabled = false;
			}
			$list[] = array(
				'id'      => intval( $project_id ),
				'title'   => $title,
				'enabled' => $enabled,
			);
		}
		return $list;
	}

	/**
	 * Project ids to work with: user selection from wrapper settings,
	 * falling back to all enabled projects of the official plugin.
	 *
	 * @return array
	 */
	public static function get_enabled_project_ids() {
		$selected = TBSW_Queue::get_settings();
		$selected = array_map( 'intval', (array) $selected['project_ids'] );
		$ids      = array();
		foreach ( self::get_available_projects() as $project ) {
			if ( ! $project['enabled'] ) {
				continue;
			}
			if ( ! empty( $selected ) && ! in_array( $project['id'], $selected, true ) ) {
				continue;
			}
			$ids[] = $project['id'];
		}
		return $ids;
	}

	/**
	 * Address of a Tilda page: alias (trailing slash trimmed) else filename/url without .html.
	 *
	 * @param object $page Page object from getPagesList.
	 * @return string
	 */
	public static function page_address( $page ) {
		$alias = '';
		if ( ! empty( $page->alias ) ) {
			$alias = trim( $page->alias, '/' );
		}
		if ( '' !== $alias ) {
			return $alias;
		}
		$file = '';
		if ( ! empty( $page->filename ) ) {
			$file = $page->filename;
		} elseif ( ! empty( $page->url ) ) {
			$file = $page->url;
		}
		$file = trim( $file, '/' );
		if ( substr( $file, -5 ) === '.html' ) {
			$file = substr( $file, 0, -5 );
		}
		return $file;
	}

	/**
	 * Slug from Tilda page address: multi-segment paths joined with '-', sanitized.
	 *
	 * @param string $address Page address.
	 * @param int    $page_id Fallback id.
	 * @return string
	 */
	public static function slug_from_address( $address, $page_id ) {
		$slug = sanitize_title( str_replace( '/', '-', $address ) );
		if ( '' === $slug ) {
			$slug = 'tilda-page-' . intval( $page_id );
		}
		return $slug;
	}

	/**
	 * Task 2 plan: published Tilda pages of enabled projects with actions.
	 *
	 * @param array $excluded_page_ids Page ids excluded by user.
	 * @return array { projects: [], pages: [ {page_id, project_id, title, address, slug, action, reason, post_id, collision} ], counters: [], errors: [], index_page_ids: [] }
	 */
	public static function build_import_plan( $excluded_page_ids = array() ) {
		self::ensure_official_classes();
		$excluded = array_map( 'intval', (array) $excluded_page_ids );
		$map      = Tilda::get_local_map( Tilda::MAP_PAGE_POSTS );
		$map      = is_array( $map ) ? $map : array();

		$plan = array(
			'projects'       => array(),
			'pages'          => array(),
			'counters'       => array(
				'create'  => 0,
				'update'  => 0,
				'skip'    => 0,
			),
			'errors'         => array(),
			'index_page_ids' => array(),
		);

		foreach ( self::get_enabled_project_ids() as $project_id ) {
			$pages = Tilda::get_pageslist( $project_id );
			if ( is_wp_error( $pages ) ) {
				$plan['errors'][] = array(
					'page_id' => 0,
					'message' => sprintf(
						/* translators: 1: project id, 2: error message. */
						__( 'Project %1$s: getPagesList failed: %2$s', 'tilda-bulk-sync-wrapper' ),
						$project_id,
						implode( ' | ', $pages->get_error_messages() )
					),
				);
				continue;
			}
			$plan['projects'][] = $project_id;

			$index_page_id = 0;
			$local_project = Tilda::get_local_project( $project_id );
			if ( is_object( $local_project ) && ! empty( $local_project->indexpageid ) ) {
				$index_page_id = intval( $local_project->indexpageid );
			} else {
				$info = Tilda::get_projectinfo( $project_id );
				if ( ! is_wp_error( $info ) && ! empty( $info->indexpageid ) ) {
					$index_page_id = intval( $info->indexpageid );
				}
			}
			if ( $index_page_id > 0 ) {
				$plan['index_page_ids'][ $project_id ] = $index_page_id;
			}

			if ( ! is_array( $pages ) ) {
				continue;
			}
			foreach ( $pages as $page ) {
				$page_id = intval( $page->id );
				$row     = array(
					'page_id'    => $page_id,
					'project_id' => $project_id,
					'title'      => isset( $page->title ) ? $page->title : '',
					'address'    => self::page_address( $page ),
					'slug'       => '',
					'action'     => 'skip',
					'reason'     => '',
					'post_id'    => 0,
					'collision'  => false,
					'is_index'   => ( $page_id === $index_page_id ),
				);
				$row['slug'] = self::slug_from_address( $row['address'], $page_id );

				if ( in_array( $page_id, $excluded, true ) ) {
					$row['reason'] = __( 'excluded in settings or preview', 'tilda-bulk-sync-wrapper' );
					$plan['counters']['skip']++;
					$plan['pages'][] = $row;
					continue;
				}
				$published = isset( $page->published ) ? intval( $page->published ) : 0;
				if ( $published <= 0 ) {
					$row['reason'] = __( 'not published in Tilda', 'tilda-bulk-sync-wrapper' );
					$plan['counters']['skip']++;
					$plan['pages'][] = $row;
					continue;
				}

				$post_id = isset( $map[ $page_id ] ) ? intval( $map[ $page_id ] ) : 0;
				if ( $post_id > 0 && get_post( $post_id ) ) {
					$row['action']  = 'update';
					$row['post_id'] = $post_id;
					$plan['counters']['update']++;
				} else {
					$row['action'] = 'create';
					$plan['counters']['create']++;
					$existing = get_page_by_path( $row['slug'], OBJECT, 'page' );
					if ( $existing ) {
						$existing_meta = get_post_meta( $existing->ID, '_tilda', true );
						$bound_page    = ( is_array( $existing_meta ) && isset( $existing_meta['page_id'] ) ) ? intval( $existing_meta['page_id'] ) : 0;
						if ( $bound_page !== $page_id ) {
							$row['collision'] = true;
						}
					}
				}
				$plan['pages'][] = $row;
			}
		}
		return $plan;
	}

	/**
	 * Create a WP page for a Tilda page and bind it with the official plugin structures.
	 *
	 * @param array  $row             Plan row.
	 * @param bool   $make_front_page Set as site front page after creation.
	 * @return array|WP_Error array( post_id, slug_used, collision_resolved ).
	 */
	public static function create_post_for_page( $row, $make_front_page = false ) {
		self::ensure_official_classes();
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => sanitize_text_field( $row['title'] ),
				'post_name'   => $row['slug'],
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}
		$post      = get_post( $post_id );
		$slug_used = $post ? $post->post_name : $row['slug'];

		update_post_meta(
			$post_id,
			'_tilda',
			array(
				'status'     => 'on',
				'page_id'    => intval( $row['page_id'] ),
				'project_id' => intval( $row['project_id'] ),
				'post_id'    => $post_id,
			)
		);
		Tilda_Admin::update_local_map( Tilda::MAP_PAGE_POSTS, intval( $row['page_id'] ), $post_id );
		self::ensure_page_template( $post_id );

		if ( $make_front_page && ! empty( $row['is_index'] ) ) {
			update_option( 'show_on_front', 'page' );
			update_option( 'page_on_front', $post_id );
		}

		return array(
			'post_id'            => $post_id,
			'slug_used'          => $slug_used,
			'collision_resolved' => ( $slug_used !== $row['slug'] ),
		);
	}

	/**
	 * Run the official one-page sync and download its files.
	 *
	 * @param int   $page_id    Tilda page id.
	 * @param int   $project_id Tilda project id.
	 * @param int   $post_id    WP post id.
	 * @param array $pending    Leftover download list from a previous chunk (resume support).
	 * @return array { status: ok|error|partial, messages: [], warnings: [], pending: [], downloaded: int }
	 */
	public static function sync_page( $page_id, $project_id, $post_id, $pending = array() ) {
		self::ensure_official_classes();
		Tilda::$errors = new WP_Error();

		$out = array(
			'status'     => 'ok',
			'messages'   => array(),
			'warnings'   => array(),
			'pending'    => array(),
			'downloaded' => 0,
		);

		if ( empty( $pending ) ) {
			$ar_download = Tilda_Admin::export_tilda_page( intval( $page_id ), intval( $project_id ), intval( $post_id ) );
			if ( is_wp_error( $ar_download ) ) {
				$out['status']   = 'error';
				$out['messages'] = $ar_download->get_error_messages();
				return $out;
			}
			$pending = is_array( $ar_download ) ? $ar_download : array();
		}

		$download = self::download_files( $pending, $out );
		$out['pending']    = $download['pending'];
		$out['downloaded'] = $download['downloaded'];
		if ( ! empty( $out['pending'] ) ) {
			$out['status'] = 'partial';
		}
		return $out;
	}

	/**
	 * Download files chunk with a time cap, mirroring official ajax_export_file() rules.
	 *
	 * @param array $files List of array( from_url, to_dir ).
	 * @param array $out   Result accumulator (warnings appended).
	 * @return array { pending: [], downloaded: int }
	 */
	private static function download_files( $files, &$out ) {
		$start      = time();
		$pending    = array();
		$downloaded = 0;
		foreach ( $files as $file ) {
			if ( time() - $start > self::DOWNLOAD_CHUNK_SECONDS ) {
				$pending[] = $file;
				continue;
			}
			if ( ! isset( $file['to_dir'], $file['from_url'] ) ) {
				continue;
			}
			if ( file_exists( $file['to_dir'] ) && false === strpos( $file['to_dir'], '/pages/' ) ) {
				$downloaded++;
				continue;
			}
			$content = self::get_remote_file_with_retry( $file['from_url'], $out );
			if ( false === $content ) {
				$pending[] = $file;
				continue;
			}
			$extension = strtolower( pathinfo( $file['from_url'], PATHINFO_EXTENSION ) );
			if (
				in_array( $extension, array( 'jpg', 'jpeg', 'png', 'gif', 'svg', 'ico' ), true )
				&& ( false !== strpos( $content, 'The resource could not be found.' ) || false !== strpos( strtolower( $content ), 'not found.' ) )
			) {
				$out['warnings'][] = sprintf(
					/* translators: %s: file URL. */
					__( 'Remote image not found, skipped: %s', 'tilda-bulk-sync-wrapper' ),
					$file['from_url']
				);
				$downloaded++;
				continue;
			}
			if (
				false !== strpos( $file['to_dir'], 'tilda-blocks-' )
				&& false !== strpos( $file['to_dir'], '.js' )
			) {
				$content = str_replace( '$(', 'jQuery(', $content );
				$content = str_replace( '$.', 'jQuery.', $content );
			}
			if ( file_put_contents( $file['to_dir'], $content ) === false ) {
				$out['warnings'][] = sprintf(
					/* translators: %s: local path. */
					__( 'Cannot save file to %s', 'tilda-bulk-sync-wrapper' ),
					$file['to_dir']
				);
				$pending[] = $file;
				continue;
			}
			$downloaded++;
		}
		return array(
			'pending'    => $pending,
			'downloaded' => $downloaded,
		);
	}

	/**
	 * Remote file fetch with limited retries for transient network errors.
	 *
	 * @param string $url Source URL.
	 * @param array  $out  Result accumulator (warnings appended).
	 * @return string|false
	 */
	private static function get_remote_file_with_retry( $url, &$out ) {
		$delays = array( 0, 2, 4 );
		foreach ( $delays as $attempt => $delay ) {
			if ( $delay > 0 ) {
				sleep( $delay );
			}
			$content = Tilda::getRemoteFile( $url );
			if ( ! is_wp_error( $content ) && is_string( $content ) && '' !== $content ) {
				return $content;
			}
			if ( $attempt === count( $delays ) - 1 ) {
				$message = is_wp_error( $content ) ? implode( ' | ', $content->get_error_messages() ) : __( 'empty response', 'tilda-bulk-sync-wrapper' );
				$out['warnings'][] = sprintf(
					/* translators: 1: file URL, 2: error message. */
					__( 'Download failed after retries: %1$s (%2$s)', 'tilda-bulk-sync-wrapper' ),
					$url,
					$message
				);
				return false;
			}
		}
		return false;
	}
}
