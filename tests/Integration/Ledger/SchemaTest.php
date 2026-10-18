<?php

namespace Levy\EuVat\Tests\Integration\Ledger;

use Levy\EuVat\Ledger\Schema;
use Levy\EuVat\Tests\Support\IntegrationTestCase;

/**
 * Creates the tables for real: the WP test case turns CREATE TABLE into a
 * temporary table, which dbDelta cannot see on a second run.
 */
class SchemaTest extends IntegrationTestCase {

	public function set_up() {
		parent::set_up();
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		Schema::drop();
	}

	public function tear_down() {
		Schema::drop();
		parent::tear_down();
	}

	/**
	 * @return string[]
	 */
	private function columns( string $table ): array {
		global $wpdb;
		return $wpdb->get_col( "SHOW COLUMNS FROM {$table}" ); // phpcs:ignore WordPress.DB
	}

	public function test_install_creates_the_ledger_table(): void {
		Schema::install();

		$this->assertSame(
			array( 'id', 'order_id', 'refund_id', 'entry_type', 'decision_id', 'scheme', 'rule', 'supply_type', 'customer_country', 'place_of_supply', 'customer_vat', 'rate_percent', 'net', 'vat', 'currency', 'eur_rate', 'net_eur', 'vat_eur', 'rate_missing', 'supply_at', 'period', 'relates_to_period', 'created_at' ),
			$this->columns( Schema::ledger_table() )
		);
	}

	public function test_install_creates_fx_and_rate_row_tables(): void {
		global $wpdb;
		Schema::install();

		$this->assertSame( array( 'currency', 'rate_date', 'rate' ), $this->columns( Schema::fx_table() ) );
		$this->assertSame( array( 'woo_rate_id', 'country', 'tax_class', 'imported_rate', 'source', 'source_date' ), $this->columns( $wpdb->prefix . 'levy_eu_vat_rate_rows' ) );
	}

	public function test_upgrade_adds_rate_missing_to_a_version_1_ledger(): void {
		global $wpdb;
		Schema::install();
		$table = Schema::ledger_table();
		$wpdb->query( "ALTER TABLE {$table} DROP COLUMN rate_missing" ); // phpcs:ignore WordPress.DB
		update_option( Schema::DB_VERSION_OPTION, '1' );
		$this->assertNotContains( 'rate_missing', $this->columns( $table ) );

		Schema::upgrade();

		$this->assertContains( 'rate_missing', $this->columns( $table ) );
		$this->assertSame( Schema::DB_VERSION, get_option( Schema::DB_VERSION_OPTION ) );
	}

	public function test_install_records_the_db_version(): void {
		Schema::install();

		$this->assertSame( Schema::DB_VERSION, get_option( Schema::DB_VERSION_OPTION ) );
	}

	public function test_install_is_idempotent_and_keeps_rows(): void {
		global $wpdb;
		Schema::install();
		$wpdb->insert(
			Schema::fx_table(),
			array(
				'currency'  => 'USD',
				'rate_date' => '2026-10-09',
				'rate'      => 1.1206,
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		Schema::install();

		$this->assertSame( '1.12060000', $wpdb->get_var( 'SELECT rate FROM ' . Schema::fx_table() ) ); // phpcs:ignore WordPress.DB
	}

	public function test_ledger_indexes_exist(): void {
		global $wpdb;
		Schema::install();

		$table   = Schema::ledger_table();
		$indexes = array_unique( $wpdb->get_col( "SHOW INDEX FROM {$table}", 2 ) ); // phpcs:ignore WordPress.DB
		sort( $indexes );
		$this->assertSame( array( 'PRIMARY', 'customer_vat', 'order_id', 'period_scheme' ), $indexes );
	}

	public function test_drop_removes_tables_and_version(): void {
		global $wpdb;
		Schema::install();

		Schema::drop();

		$this->assertNull( $wpdb->get_var( "SHOW TABLES LIKE '" . Schema::ledger_table() . "'" ) ); // phpcs:ignore WordPress.DB
		$this->assertFalse( get_option( Schema::DB_VERSION_OPTION ) );
	}
}
