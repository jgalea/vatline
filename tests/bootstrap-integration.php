<?php

$levy_eu_vat_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $levy_eu_vat_tests_dir ) {
	$levy_eu_vat_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( $levy_eu_vat_tests_dir . '/includes/functions.php' ) ) {
	echo "Could not find {$levy_eu_vat_tests_dir}/includes/functions.php" . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput
	exit( 1 );
}

require_once $levy_eu_vat_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () {
		$woocommerce = WP_PLUGIN_DIR . '/woocommerce/woocommerce.php';
		if ( file_exists( $woocommerce ) ) {
			require $woocommerce;
		}
		require dirname( __DIR__ ) . '/levy-eu-vat.php';
	}
);

tests_add_filter(
	'pre_option_woocommerce_custom_orders_table_enabled',
	static function () {
		return 'yes';
	}
);

require $levy_eu_vat_tests_dir . '/includes/bootstrap.php';

if ( class_exists( 'WC_Install' ) ) {
	WC_Install::create_tables();
}

require_once __DIR__ . '/Support/HttpGuard.php';
require_once __DIR__ . '/Support/IntegrationTestCase.php';
