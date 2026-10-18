<?php
/**
 * Runs uninstall.php against seeded data and checks what it removed.
 * Run with `wp eval-file`, never `wp plugin uninstall` (the plugin folder is
 * bind-mounted from the host):
 *
 * npx wp-env run tests-cli --env-cwd=wp-content/plugins/levy-eu-vat wp eval-file tests/eval/uninstall-check.php
 */

use Levy\EuVat\Ledger\Schema;

require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';

const LEVY_EU_VAT_CHECK_USER_META = '_levy_eu_vat_number';

// eval-file runs this file inside a function, so the count lives in $GLOBALS.
$GLOBALS['levy_eu_vat_failures'] = 0;

function levy_eu_vat_check( bool $ok, string $what ): void {
	if ( ! $ok ) {
		++$GLOBALS['levy_eu_vat_failures'];
	}
	WP_CLI::line( ( $ok ? 'ok   ' : 'FAIL ' ) . $what );
}

function levy_eu_vat_table_exists( string $table ): bool {
	global $wpdb;
	return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB
}

/**
 * @param array<string,mixed> $settings
 */
function levy_eu_vat_seed( array $settings ): int {
	Schema::install();
	update_option( 'levy_eu_vat_settings', $settings );
	update_option( 'levy_eu_vat_rate_mapping', array( 'reduced-rate' => array( 'DE' => 7 ) ) );
	set_transient( 'levy_eu_vat_v_check', array( 'status' => 'valid' ), HOUR_IN_SECONDS );
	update_option( 'levy_eu_vat_check_keep_me_not', 'x' );
	update_option( 'woocommerce_levy_check_unrelated', 'x' );
	$user_id = (int) ( get_user_by( 'login', 'levy-uninstall-check' ) ? get_user_by( 'login', 'levy-uninstall-check' )->ID : wp_insert_user(
		array(
			'user_login' => 'levy-uninstall-check',
			'user_pass'  => wp_generate_password(),
			'user_email' => 'levy-uninstall-check@example.com',
		)
	) );
	update_user_meta( $user_id, LEVY_EU_VAT_CHECK_USER_META, 'DE123456789' );
	as_schedule_single_action( time() + DAY_IN_SECONDS, 'levy_eu_vat_retention', array(), 'levy-eu-vat' );
	as_schedule_single_action( time() + DAY_IN_SECONDS, 'levy_eu_vat_check_unrelated', array(), 'other-group' );
	return $user_id;
}

function levy_eu_vat_uninstall(): void {
	if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
		define( 'WP_UNINSTALL_PLUGIN', 'levy-eu-vat/levy-eu-vat.php' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- core's constant, as uninstall_plugin() defines it.
	}
	include dirname( __DIR__, 2 ) . '/uninstall.php';
	wp_cache_flush();
}

$levy_eu_vat_user = levy_eu_vat_seed( array( 'store_vat_number' => 'MT12345678' ) );
levy_eu_vat_uninstall();

WP_CLI::line( 'Default (keep data):' );
levy_eu_vat_check( levy_eu_vat_table_exists( Schema::ledger_table() ), 'ledger table kept' );
levy_eu_vat_check( 'MT12345678' === ( get_option( 'levy_eu_vat_settings' )['store_vat_number'] ?? '' ), 'settings kept' );
levy_eu_vat_check( false !== get_option( 'levy_eu_vat_rate_mapping' ), 'rate mapping kept' );
levy_eu_vat_check( false !== as_next_scheduled_action( 'levy_eu_vat_retention', array(), 'levy-eu-vat' ), 'scheduled action kept' );
levy_eu_vat_check( 'DE123456789' === get_user_meta( $levy_eu_vat_user, LEVY_EU_VAT_CHECK_USER_META, true ), 'saved account number kept' );

levy_eu_vat_seed( array( 'delete_data_on_uninstall' => true ) );
levy_eu_vat_uninstall();

WP_CLI::line( 'Opted in (delete data):' );
foreach ( array( Schema::ledger_table(), Schema::fx_table(), $GLOBALS['wpdb']->prefix . 'levy_eu_vat_rate_rows' ) as $levy_eu_vat_table ) {
	levy_eu_vat_check( ! levy_eu_vat_table_exists( $levy_eu_vat_table ), "$levy_eu_vat_table dropped" );
}
foreach ( array( 'levy_eu_vat_settings', 'levy_eu_vat_rate_mapping', 'levy_eu_vat_db_version', 'levy_eu_vat_check_keep_me_not' ) as $levy_eu_vat_option ) {
	levy_eu_vat_check( false === get_option( $levy_eu_vat_option ), "$levy_eu_vat_option deleted" );
}
levy_eu_vat_check( false === get_transient( 'levy_eu_vat_v_check' ), 'transient deleted' );
levy_eu_vat_check( false === as_next_scheduled_action( 'levy_eu_vat_retention', array(), 'levy-eu-vat' ), 'scheduled action cancelled' );
levy_eu_vat_check( '' === get_user_meta( $levy_eu_vat_user, LEVY_EU_VAT_CHECK_USER_META, true ), 'saved account number deleted' );
levy_eu_vat_check( 'x' === get_option( 'woocommerce_levy_check_unrelated' ), 'unrelated option kept' );
levy_eu_vat_check( false !== as_next_scheduled_action( 'levy_eu_vat_check_unrelated', array(), 'other-group' ), 'unrelated action kept' );

delete_option( 'woocommerce_levy_check_unrelated' );
as_unschedule_all_actions( 'levy_eu_vat_check_unrelated' );
wp_delete_user( $levy_eu_vat_user );
Schema::install();

if ( $GLOBALS['levy_eu_vat_failures'] > 0 ) {
	WP_CLI::error( $GLOBALS['levy_eu_vat_failures'] . ' check(s) failed.' );
}
WP_CLI::success( 'Uninstall keeps data by default and removes it when opted in.' );
