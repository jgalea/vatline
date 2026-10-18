<?php

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// VAT records must be kept for years: nothing is removed unless the store opted in. Order meta stays with the orders.
$vatline_settings = get_option( 'vatline_settings', array() );

if ( empty( $vatline_settings['delete_data_on_uninstall'] ) ) {
	return;
}

require_once __DIR__ . '/vendor/autoload.php';

global $wpdb;

\Vatline\Ledger\Schema::drop();

$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( 'vatline_' ) . '%',
		$wpdb->esc_like( '_transient_vatline_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_vatline_' ) . '%'
	)
);

delete_metadata( 'user', 0, '_vatline_number', '', true );
delete_metadata( 'user', 0, '_wc_other/vatline/vat-number', '', true );

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( '', array(), 'vatline' );
}

wp_cache_flush();
