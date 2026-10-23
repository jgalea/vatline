<?php

global $wpdb;

update_option( 'vatright_settings', array() );
delete_option( 'vatright_e2e_vies_calls' );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '%vatright_v_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
echo '@@1@@';
