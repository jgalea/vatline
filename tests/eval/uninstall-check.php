<?php
/**
 * Runs uninstall.php against seeded data and checks what it removed.
 * Run with `wp eval-file`, never `wp plugin uninstall` (the plugin folder is
 * bind-mounted from the host):
 *
 * npx wp-env run tests-cli --env-cwd=wp-content/plugins/vatright wp eval-file tests/eval/uninstall-check.php
 */

use Vatright\Ledger\Schema;

require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';

const VATRIGHT_CHECK_USER_META = '_vatright_number';

// eval-file runs this file inside a function, so the count lives in $GLOBALS.
$GLOBALS['vatright_failures'] = 0;

function vatright_check( bool $ok, string $what ): void {
	if ( ! $ok ) {
		++$GLOBALS['vatright_failures'];
	}
	WP_CLI::line( ( $ok ? 'ok   ' : 'FAIL ' ) . $what );
}

function vatright_table_exists( string $table ): bool {
	global $wpdb;
	return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB
}

/**
 * @param array<string,mixed> $settings
 */
function vatright_seed( array $settings ): int {
	Schema::install();
	update_option( 'vatright_settings', $settings );
	update_option( 'vatright_rate_mapping', array( 'reduced-rate' => array( 'DE' => 7 ) ) );
	set_transient( 'vatright_v_check', array( 'status' => 'valid' ), HOUR_IN_SECONDS );
	update_option( 'vatright_check_keep_me_not', 'x' );
	update_option( 'woocommerce_vatright_check_unrelated', 'x' );
	$user_id = (int) ( get_user_by( 'login', 'vatright-uninstall-check' ) ? get_user_by( 'login', 'vatright-uninstall-check' )->ID : wp_insert_user(
		array(
			'user_login' => 'vatright-uninstall-check',
			'user_pass'  => wp_generate_password(),
			'user_email' => 'vatright-uninstall-check@example.com',
		)
	) );
	update_user_meta( $user_id, VATRIGHT_CHECK_USER_META, 'DE123456789' );
	as_schedule_single_action( time() + DAY_IN_SECONDS, 'vatright_retention', array(), 'vatright' );
	as_schedule_single_action( time() + DAY_IN_SECONDS, 'vatright_check_unrelated', array(), 'other-group' );
	return $user_id;
}

function vatright_uninstall(): void {
	if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
		define( 'WP_UNINSTALL_PLUGIN', 'vatright/vatright.php' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- core's constant, as uninstall_plugin() defines it.
	}
	include dirname( __DIR__, 2 ) . '/uninstall.php';
	wp_cache_flush();
}

$vatright_user = vatright_seed( array( 'store_vat_number' => 'MT12345678' ) );
vatright_uninstall();

WP_CLI::line( 'Default (keep data):' );
vatright_check( vatright_table_exists( Schema::ledger_table() ), 'ledger table kept' );
vatright_check( 'MT12345678' === ( get_option( 'vatright_settings' )['store_vat_number'] ?? '' ), 'settings kept' );
vatright_check( false !== get_option( 'vatright_rate_mapping' ), 'rate mapping kept' );
vatright_check( false !== as_next_scheduled_action( 'vatright_retention', array(), 'vatright' ), 'scheduled action kept' );
vatright_check( 'DE123456789' === get_user_meta( $vatright_user, VATRIGHT_CHECK_USER_META, true ), 'saved account number kept' );

vatright_seed( array( 'delete_data_on_uninstall' => true ) );
vatright_uninstall();

WP_CLI::line( 'Opted in (delete data):' );
foreach ( array( Schema::ledger_table(), Schema::fx_table(), $GLOBALS['wpdb']->prefix . 'vatright_rate_rows' ) as $vatright_table ) {
	vatright_check( ! vatright_table_exists( $vatright_table ), "$vatright_table dropped" );
}
foreach ( array( 'vatright_settings', 'vatright_rate_mapping', 'vatright_db_version', 'vatright_check_keep_me_not' ) as $vatright_option ) {
	vatright_check( false === get_option( $vatright_option ), "$vatright_option deleted" );
}
vatright_check( false === get_transient( 'vatright_v_check' ), 'transient deleted' );
vatright_check( false === as_next_scheduled_action( 'vatright_retention', array(), 'vatright' ), 'scheduled action cancelled' );
vatright_check( '' === get_user_meta( $vatright_user, VATRIGHT_CHECK_USER_META, true ), 'saved account number deleted' );
vatright_check( 'x' === get_option( 'woocommerce_vatright_check_unrelated' ), 'unrelated option kept' );
vatright_check( false !== as_next_scheduled_action( 'vatright_check_unrelated', array(), 'other-group' ), 'unrelated action kept' );

delete_option( 'woocommerce_vatright_check_unrelated' );
as_unschedule_all_actions( 'vatright_check_unrelated' );
wp_delete_user( $vatright_user );
Schema::install();

if ( $GLOBALS['vatright_failures'] > 0 ) {
	WP_CLI::error( $GLOBALS['vatright_failures'] . ' check(s) failed.' );
}
WP_CLI::success( 'Uninstall keeps data by default and removes it when opted in.' );
