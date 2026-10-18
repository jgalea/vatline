<?php

global $wpdb;

update_option( 'levy_eu_vat_settings', array() );
delete_option( 'levy_e2e_vies_calls' );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '%levy_eu_vat_v_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
echo '@@1@@';
