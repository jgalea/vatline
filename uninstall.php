<?php

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// VAT records must be kept for years: nothing is removed unless the store opted in. Order meta stays with the orders.
$levy_eu_vat_settings = get_option( 'levy_eu_vat_settings', array() );

if ( empty( $levy_eu_vat_settings['delete_data_on_uninstall'] ) ) {
	return;
}

require_once __DIR__ . '/vendor/autoload.php';

global $wpdb;

\Levy\EuVat\Ledger\Schema::drop();

$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( 'levy_eu_vat_' ) . '%',
		$wpdb->esc_like( '_transient_levy_eu_vat_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_levy_eu_vat_' ) . '%'
	)
);

delete_metadata( 'user', 0, '_levy_eu_vat_number', '', true );
delete_metadata( 'user', 0, '_wc_other/levy-eu-vat/vat-number', '', true );

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( '', array(), 'levy-eu-vat' );
}

wp_cache_flush();
