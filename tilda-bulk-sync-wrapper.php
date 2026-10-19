<?php
/**
 * Plugin Name: Tilda Bulk Sync Wrapper
 * Plugin URI: https://qrbarcodehub.com/
 * Description: A wrapper around the official Tilda Publishing plugin: bulk synchronization of all published Tilda-linked landings and bulk import of all published Tilda project pages into WordPress (including pages that do not have posts yet). Does not modify the official plugin's code.
 * Version: 1.1.0
 * Requires at least: 5.6
 * Requires PHP: 7.4
 * Requires Plugins: tilda-publishing, fullwidth-templates
 * Author: qrbarcodehub.com
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: tilda-bulk-sync-wrapper
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TBSW_VERSION', '1.1.0' );
define( 'TBSW_MIN_WP', '5.6' );
define( 'TBSW_MIN_PHP', '7.4' );
define( 'TBSW_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'TBSW_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'TBSW_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'TBSW_OFFICIAL_BASENAME', 'tilda-publishing/tilda-wordpress-plugin.php' );
define( 'TBSW_FPT_BASENAME', 'fullwidth-templates/fullwidth-page-template.php' );

require_once TBSW_PLUGIN_DIR . 'includes/class-tbsw-core.php';
require_once TBSW_PLUGIN_DIR . 'includes/class-tbsw-queue.php';

if ( is_admin() ) {
	require_once TBSW_PLUGIN_DIR . 'includes/class-tbsw-admin.php';
	add_action( 'plugins_loaded', array( 'TBSW_Admin', 'init' ) );
}

/**
 * Activation: environment checks only, no data changes.
 */
function tbsw_activate() {
	if ( version_compare( PHP_VERSION, TBSW_MIN_PHP, '<' ) ) {
		deactivate_plugins( TBSW_PLUGIN_BASENAME );
		wp_die(
			esc_html(
				sprintf(
					/* translators: %s: PHP version required. */
					__( 'Tilda Bulk Sync Wrapper requires PHP %s or newer.', 'tilda-bulk-sync-wrapper' ),
					TBSW_MIN_PHP
				)
			),
			'',
			array( 'back_link' => true )
		);
	}
	global $wp_version;
	if ( version_compare( $wp_version, TBSW_MIN_WP, '<' ) ) {
		deactivate_plugins( TBSW_PLUGIN_BASENAME );
		wp_die(
			esc_html(
				sprintf(
					/* translators: %s: WordPress version required. */
					__( 'Tilda Bulk Sync Wrapper requires WordPress %s or newer.', 'tilda-bulk-sync-wrapper' ),
					TBSW_MIN_WP
				)
			),
			'',
			array( 'back_link' => true )
		);
	}
	if ( ! function_exists( 'is_plugin_active' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	if ( ! is_plugin_active( TBSW_OFFICIAL_BASENAME ) ) {
		deactivate_plugins( TBSW_PLUGIN_BASENAME );
		wp_die(
			esc_html__( 'Tilda Bulk Sync Wrapper requires the official "Tilda Publishing" plugin (https://wordpress.org/plugins/tilda-publishing/). Install and activate it, then activate this plugin again.', 'tilda-bulk-sync-wrapper' ),
			'',
			array( 'back_link' => true )
		);
	}
	if ( ! is_plugin_active( TBSW_FPT_BASENAME ) ) {
		deactivate_plugins( TBSW_PLUGIN_BASENAME );
		wp_die(
			esc_html__( 'Tilda Bulk Sync Wrapper requires the plugin "Fullwidth Templates for Any Theme & Page Builder" (https://ru.wordpress.org/plugins/fullwidth-templates/). Install and activate it, then activate this plugin again.', 'tilda-bulk-sync-wrapper' ),
			'',
			array( 'back_link' => true )
		);
	}
}
register_activation_hook( __FILE__, 'tbsw_activate' );

/**
 * Deactivation: release lock and mark running task cancelled.
 * Never touches content or official plugin settings.
 */
function tbsw_deactivate() {
	TBSW_Queue::cancel_task( 'plugin deactivated' );
	TBSW_Queue::release_lock();
}
register_deactivation_hook( __FILE__, 'tbsw_deactivate' );
