<?php

global $wpdb;

update_option( 'vatline_settings', array() );
delete_option( 'vatline_e2e_vies_calls' );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '%vatline_v_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
echo '@@1@@';
