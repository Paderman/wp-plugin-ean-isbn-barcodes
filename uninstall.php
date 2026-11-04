<?php
/**
 * Remove all plugin data on uninstall.
 *
 * @package EANBC
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$eanbc_cleanup = static function () use ( $wpdb ) {
	delete_option( 'eanbc_settings' );
	delete_option( 'eanbc_cache_version' );
	delete_option( 'eanbc_show_welcome' );
	delete_option( 'eanbc_flush_rewrite' );
	// Drop the Digital Link resolver rule; WordPress rebuilds its rules on the next request.
	delete_option( 'rewrite_rules' );
	// Cached API answers (transients) – there is no API to delete transients by prefix.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time cleanup on uninstall.
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_eanbc\_%' OR option_name LIKE '\_transient\_timeout\_eanbc\_%'" );
};

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids' ) ) as $eanbc_site ) {
		switch_to_blog( $eanbc_site );
		$eanbc_cleanup();
		restore_current_blog();
	}
} else {
	$eanbc_cleanup();
}
