<?php
/**
 * Uninstall handler: removes ONLY wrapper's own options.
 * WordPress content and official Tilda Publishing plugin data are never touched.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'tbsw_task' );
delete_option( 'tbsw_lock' );
delete_option( 'tbsw_log' );
delete_option( 'tbsw_settings' );
delete_transient( 'tbsw_preview' );
