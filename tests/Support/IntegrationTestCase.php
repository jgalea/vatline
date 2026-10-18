<?php

namespace Levy\EuVat\Tests\Support;

use Levy\EuVat\Ledger\Schema;
use WP_UnitTestCase;

abstract class IntegrationTestCase extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		HttpGuard::install();
	}

	public function tear_down() {
		HttpGuard::uninstall();
		parent::tear_down();
	}

	/**
	 * dbDelta on a table that already exists (the test case keeps the temporary
	 * tables for the whole run) issues ALTER TABLE, which commits the open
	 * transaction and leaks every row the test inserted so far.
	 */
	protected function install_schema(): void {
		global $wpdb;

		$suppress = $wpdb->suppress_errors( true );
		$exists   = false !== $wpdb->query( 'SELECT 1 FROM ' . Schema::ledger_table() . ' LIMIT 0' ); // phpcs:ignore WordPress.DB
		$wpdb->suppress_errors( $suppress );

		if ( ! $exists ) {
			Schema::install();
		}
	}
}
