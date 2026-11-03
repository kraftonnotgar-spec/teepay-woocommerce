<?php
/**
 * Uninstall: remove settings, cached data and scheduled events.
 *
 * Order notes and order meta (TeePay references) are kept: they are part of your financial records.
 *
 * @package TeePay\WooCommerce
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'woocommerce_teepay_settings' );
delete_option( 'teepay_wc_account_restricted' );
delete_transient( 'teepay_wc_capabilities_live' );
delete_transient( 'teepay_wc_capabilities_sandbox' );
wp_clear_scheduled_hook( 'teepay_wc_reconcile' );

global $wpdb;
// Short-lived per-order locks, normally already removed.
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'teepay_wc_lock_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
