<?php

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- a WP-CLI script, its variables are not globals of the plugin.

use Levy\EuVat\Rates\RateMapping;
use Levy\EuVat\Rates\RateSync;
use Levy\EuVat\Rates\Tedb;
use Levy\EuVat\Registry\WpHttpTransport;

$sync = new RateSync( new Tedb( new WpHttpTransport() ) );
$diff = $sync->plan( $sync->latest(), RateMapping::load() );
$sync->apply( $diff, array_keys( $diff->entries() ), false );

$zone = new WC_Shipping_Zone( 0 );
if ( ! $zone->get_shipping_methods() ) {
	$instance = $zone->add_shipping_method( 'flat_rate' );
	update_option(
		'woocommerce_flat_rate_' . $instance . '_settings',
		array(
			'title'      => 'Flat rate',
			'tax_status' => 'taxable',
			'cost'       => '5',
		)
	);
}

if ( ! get_user_by( 'login', 'levy-customer' ) ) {
	wp_insert_user(
		array(
			'user_login'   => 'levy-customer',
			'user_pass'    => 'levy-pass',
			'user_email'   => 'customer@example.com',
			'first_name'   => 'Ada',
			'last_name'    => 'Lovelace',
			'display_name' => 'Ada Lovelace',
			'role'         => 'customer',
		)
	);
}
