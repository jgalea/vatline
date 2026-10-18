<?php
/**
 * Runs uninstall.php against seeded data and checks what it removed.
 * Run with `wp eval-file`, never `wp plugin uninstall` (the plugin folder is
 * bind-mounted from the host):
 *
 * npx wp-env run tests-cli --env-cwd=wp-content/plugins/vatline wp eval-file tests/eval/uninstall-check.php
 */

use Vatline\Ledger\Schema;

require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';

const VATLINE_CHECK_USER_META = '_vatline_number';

// eval-file runs this file inside a function, so the count lives in $GLOBALS.
$GLOBALS['vatline_failures'] = 0;

function vatline_check( bool $ok, string $what ): void {
	if ( ! $ok ) {
		++$GLOBALS['vatline_failures'];
	}
	WP_CLI::line( ( $ok ? 'ok   ' : 'FAIL ' ) . $what );
}

function vatline_table_exists( string $table ): bool {
	global $wpdb;
	return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB
}

/**
 * @param array<string,mixed> $settings
 */
function vatline_seed( array $settings ): int {
	Schema::install();
	update_option( 'vatline_settings', $settings );
	update_option( 'vatline_rate_mapping', array( 'reduced-rate' => array( 'DE' => 7 ) ) );
	set_transient( 'vatline_v_check', array( 'status' => 'valid' ), HOUR_IN_SECONDS );
	update_option( 'vatline_check_keep_me_not', 'x' );
	update_option( 'woocommerce_vatline_check_unrelated', 'x' );
	$user_id = (int) ( get_user_by( 'login', 'vatline-uninstall-check' ) ? get_user_by( 'login', 'vatline-uninstall-check' )->ID : wp_insert_user(
		array(
			'user_login' => 'vatline-uninstall-check',
			'user_pass'  => wp_generate_password(),
			'user_email' => 'vatline-uninstall-check@example.com',
		)
	) );
	update_user_meta( $user_id, VATLINE_CHECK_USER_META, 'DE123456789' );
	as_schedule_single_action( time() + DAY_IN_SECONDS, 'vatline_retention', array(), 'vatline' );
	as_schedule_single_action( time() + DAY_IN_SECONDS, 'vatline_check_unrelated', array(), 'other-group' );
	return $user_id;
}

function vatline_uninstall(): void {
	if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
		define( 'WP_UNINSTALL_PLUGIN', 'vatline/vatline.php' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- core's constant, as uninstall_plugin() defines it.
	}
	include dirname( __DIR__, 2 ) . '/uninstall.php';
	wp_cache_flush();
}

$vatline_user = vatline_seed( array( 'store_vat_number' => 'MT12345678' ) );
vatline_uninstall();

WP_CLI::line( 'Default (keep data):' );
vatline_check( vatline_table_exists( Schema::ledger_table() ), 'ledger table kept' );
vatline_check( 'MT12345678' === ( get_option( 'vatline_settings' )['store_vat_number'] ?? '' ), 'settings kept' );
vatline_check( false !== get_option( 'vatline_rate_mapping' ), 'rate mapping kept' );
vatline_check( false !== as_next_scheduled_action( 'vatline_retention', array(), 'vatline' ), 'scheduled action kept' );
vatline_check( 'DE123456789' === get_user_meta( $vatline_user, VATLINE_CHECK_USER_META, true ), 'saved account number kept' );

vatline_seed( array( 'delete_data_on_uninstall' => true ) );
vatline_uninstall();

WP_CLI::line( 'Opted in (delete data):' );
foreach ( array( Schema::ledger_table(), Schema::fx_table(), $GLOBALS['wpdb']->prefix . 'vatline_rate_rows' ) as $vatline_table ) {
	vatline_check( ! vatline_table_exists( $vatline_table ), "$vatline_table dropped" );
}
foreach ( array( 'vatline_settings', 'vatline_rate_mapping', 'vatline_db_version', 'vatline_check_keep_me_not' ) as $vatline_option ) {
	vatline_check( false === get_option( $vatline_option ), "$vatline_option deleted" );
}
vatline_check( false === get_transient( 'vatline_v_check' ), 'transient deleted' );
vatline_check( false === as_next_scheduled_action( 'vatline_retention', array(), 'vatline' ), 'scheduled action cancelled' );
vatline_check( '' === get_user_meta( $vatline_user, VATLINE_CHECK_USER_META, true ), 'saved account number deleted' );
vatline_check( 'x' === get_option( 'woocommerce_vatline_check_unrelated' ), 'unrelated option kept' );
vatline_check( false !== as_next_scheduled_action( 'vatline_check_unrelated', array(), 'other-group' ), 'unrelated action kept' );

delete_option( 'woocommerce_vatline_check_unrelated' );
as_unschedule_all_actions( 'vatline_check_unrelated' );
wp_delete_user( $vatline_user );
Schema::install();

if ( $GLOBALS['vatline_failures'] > 0 ) {
	WP_CLI::error( $GLOBALS['vatline_failures'] . ' check(s) failed.' );
}
WP_CLI::success( 'Uninstall keeps data by default and removes it when opted in.' );
