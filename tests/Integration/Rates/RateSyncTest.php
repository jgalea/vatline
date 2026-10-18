<?php

namespace Levy\EuVat\Tests\Integration\Rates;

use Levy\EuVat\Rates\RateDiff;
use Levy\EuVat\Rates\RateMapping;
use Levy\EuVat\Rates\RateSet;
use Levy\EuVat\Rates\RatesScreen;
use Levy\EuVat\Rates\RateSync;
use Levy\EuVat\Rates\Tedb;
use Levy\EuVat\Tests\Support\FixtureTransport;
use Levy\EuVat\Tests\Support\IntegrationTestCase;
use WC_Tax;

require_once dirname( __DIR__, 2 ) . '/Support/FixtureTransport.php';

class RateSyncTest extends IntegrationTestCase {

	/** @var RateSync */
	private $sync;

	/** @var string */
	private $fixture_dir;

	public function set_up() {
		parent::set_up();
		global $wpdb;
		$wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}levy_eu_vat_rate_rows ( woo_rate_id BIGINT UNSIGNED NOT NULL, country CHAR(2) NOT NULL, tax_class VARCHAR(200) NOT NULL, imported_rate DECIMAL(7,4) NOT NULL, source VARCHAR(20) NOT NULL, source_date VARCHAR(10) NOT NULL, PRIMARY KEY (woo_rate_id) )" ); // phpcs:ignore WordPress.DB
		$wpdb->query( "DELETE FROM {$wpdb->prefix}levy_eu_vat_rate_rows" ); // phpcs:ignore WordPress.DB
		$wpdb->query( "DELETE FROM {$wpdb->prefix}woocommerce_tax_rates" ); // phpcs:ignore WordPress.DB
		delete_option( RateSync::OPTION_LATEST );
		delete_option( RateSync::OPTION_PENDING );
		delete_option( RateMapping::OPTION );

		$this->fixture_dir = sys_get_temp_dir() . '/levy-sync-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->fixture_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
		$this->sync = new RateSync( new Tedb( new FixtureTransport( $this->fixture_dir ) ) );
	}

	public function tear_down() {
		foreach ( glob( $this->fixture_dir . '/*' ) ?: array() as $file ) { // phpcs:ignore Universal.Operators.DisallowShortTernary.Found
			unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		}
		rmdir( $this->fixture_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		parent::tear_down();
	}

	private function rate_set( float $de_standard = 19.0 ): RateSet {
		return new RateSet(
			array(
				'DE' => array(
					'standard' => $de_standard,
					'reduced'  => array(),
				),
				'FR' => array(
					'standard' => 20.0,
					'reduced'  => array(),
				),
			),
			'tedb',
			'2026-10-17'
		);
	}

	private function mapping(): RateMapping {
		$map                                      = RateMapping::defaults();
		$map['reduced-rate']['per_country']['DE'] = 7.0;
		return new RateMapping( $map );
	}

	/**
	 * @return array<string,object>
	 */
	private function woo_rates( string $tax_class ): array {
		$rates = array();
		foreach ( (array) WC_Tax::get_rates_for_tax_class( $tax_class ) as $rate ) {
			$rates[ $rate->tax_rate_country ] = $rate;
		}
		return $rates;
	}

	private function accept_all( RateDiff $diff ): array {
		return array_keys( $diff->entries() );
	}

	public function test_apply_creates_rows_per_country_and_class(): void {
		global $wpdb;
		$diff = $this->sync->plan( $this->rate_set(), $this->mapping() );

		$applied = $this->sync->apply( $diff, $this->accept_all( $diff ), false );

		$this->assertSame( 4, $applied, 'DE and FR standard, DE reduced, and the Northern Ireland row' );
		$standard = $this->woo_rates( '' );
		$this->assertSame( '19.0000', $standard['DE']->tax_rate );
		$this->assertSame( '20.0000', $standard['FR']->tax_rate );
		$this->assertSame( 'VAT', $standard['DE']->tax_rate_name );
		$this->assertSame( '1', $standard['DE']->tax_rate_shipping );
		$this->assertSame( '1', $standard['DE']->tax_rate_priority );
		$this->assertSame( '0', $standard['DE']->tax_rate_compound );
		$reduced = $this->woo_rates( 'reduced-rate' );
		$this->assertCount( 1, $reduced );
		$this->assertSame( '7.0000', $reduced['DE']->tax_rate );
		$this->assertSame( '0', $reduced['DE']->tax_rate_shipping );

		$rows = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}levy_eu_vat_rate_rows ORDER BY country, tax_class" ); // phpcs:ignore WordPress.DB
		$this->assertCount( 4, $rows );
		$this->assertSame( (string) $standard['DE']->tax_rate_id, $rows[0]->woo_rate_id );
		$this->assertSame( '19.0000', $rows[0]->imported_rate );
		$this->assertSame( 'tedb', $rows[0]->source );
	}

	public function test_second_plan_after_apply_is_all_unchanged(): void {
		$diff = $this->sync->plan( $this->rate_set(), $this->mapping() );
		$this->sync->apply( $diff, $this->accept_all( $diff ), false );

		$again = $this->sync->plan( $this->rate_set(), $this->mapping() );

		$this->assertSame( 0, $again->pending_count() );
		$this->assertSame( 0, $this->sync->apply( $again, $this->accept_all( $again ), true ) );
		$this->assertCount( 3, $this->woo_rates( '' ) );
	}

	public function test_untouched_imported_rate_is_updated_when_tedb_changes(): void {
		$diff = $this->sync->plan( $this->rate_set(), $this->mapping() );
		$this->sync->apply( $diff, $this->accept_all( $diff ), false );

		$next = $this->sync->plan( $this->rate_set( 20.0 ), $this->mapping() );

		$this->assertSame( array( 'DE|' ), array_keys( $next->with_status( RateDiff::CHANGE ) ) );
		$this->assertSame( 1, $this->sync->apply( $next, $this->accept_all( $next ), false ) );
		$this->assertSame( '20.0000', $this->woo_rates( '' )['DE']->tax_rate );
		$this->assertCount( 3, $this->woo_rates( '' ) );
	}

	public function test_apply_never_overwrites_edited_without_flag(): void {
		$diff = $this->sync->plan( $this->rate_set(), $this->mapping() );
		$this->sync->apply( $diff, $this->accept_all( $diff ), false );
		$de_id = (int) $this->woo_rates( '' )['DE']->tax_rate_id;
		WC_Tax::_update_tax_rate( $de_id, array( 'tax_rate' => '21' ) );

		$next = $this->sync->plan( $this->rate_set( 20.0 ), $this->mapping() );
		$this->assertSame( array( 'DE|' ), array_keys( $next->with_status( RateDiff::EDITED ) ) );

		$this->assertSame( 0, $this->sync->apply( $next, $this->accept_all( $next ), false ) );
		$this->assertSame( '21.0000', $this->woo_rates( '' )['DE']->tax_rate );

		$this->assertSame( 1, $this->sync->apply( $next, $this->accept_all( $next ), true ) );
		$this->assertSame( '20.0000', $this->woo_rates( '' )['DE']->tax_rate );
	}

	public function test_only_accepted_keys_are_applied(): void {
		$diff = $this->sync->plan( $this->rate_set(), $this->mapping() );

		$this->assertSame( 1, $this->sync->apply( $diff, array( 'FR|' ), false ) );
		$this->assertSame( array( 'FR' ), array_keys( $this->woo_rates( '' ) ) );
	}

	/**
	 * KG-10: B2C goods to Northern Ireland above the threshold are taxed in
	 * GB. The sync manages a UK 20% standard row limited to BT postcodes, so
	 * they get the UK rate instead of the store's rate as a fallback.
	 */
	public function test_northern_ireland_row_is_managed_for_bt_postcodes_only(): void {
		$diff = $this->sync->plan( $this->rate_set(), $this->mapping() );
		$gb   = $diff->entries()['GB|'];
		$this->assertSame( RateDiff::ADD, $gb['status'] );
		$this->assertSame( 'BT*', $gb['postcode'] );
		$this->assertSame( 20.0, $gb['rate'] );
		$this->assertArrayNotHasKey( 'GB|reduced-rate', $diff->entries() );

		$this->sync->apply( $diff, array( 'GB|' ), false );

		$belfast = WC_Tax::find_rates(
			array(
				'country'   => 'GB',
				'postcode'  => 'BT1 1AA',
				'tax_class' => '',
			)
		);
		$london  = WC_Tax::find_rates(
			array(
				'country'   => 'GB',
				'postcode'  => 'SW1A 1AA',
				'tax_class' => '',
			)
		);
		$this->assertSame( array( 20.0 ), array_map( 'floatval', array_column( $belfast, 'rate' ) ) );
		$this->assertSame( 'yes', current( $belfast )['shipping'] );
		$this->assertSame( array(), $london, 'Great Britain itself stays outside EU VAT' );

		$again = $this->sync->plan( $this->rate_set(), $this->mapping() );
		$this->assertSame( RateDiff::UNCHANGED, $again->entries()['GB|']['status'] );
	}

	public function test_a_nationwide_gb_row_is_not_taken_for_the_northern_ireland_row(): void {
		WC_Tax::_insert_tax_rate(
			array(
				'tax_rate_country' => 'GB',
				'tax_rate'         => '20',
				'tax_rate_name'    => 'VAT',
				'tax_rate_class'   => '',
			)
		);

		$diff = $this->sync->plan( $this->rate_set(), $this->mapping() );

		$this->assertSame( RateDiff::ADD, $diff->entries()['GB|']['status'] );
	}

	public function test_existing_unmanaged_nationwide_rate_is_reused_not_duplicated(): void {
		WC_Tax::_insert_tax_rate(
			array(
				'tax_rate_country' => 'DE',
				'tax_rate_state'   => '',
				'tax_rate'         => '19',
				'tax_rate_name'    => 'MwSt',
				'tax_rate_class'   => '',
			)
		);

		$diff = $this->sync->plan( $this->rate_set(), $this->mapping() );

		$this->assertSame( RateDiff::UNCHANGED, $diff->entries()['DE|']['status'] );
		$this->sync->apply( $diff, $this->accept_all( $diff ), false );
		$this->assertCount( 3, $this->woo_rates( '' ) );
	}

	public function test_regional_rates_do_not_count_as_the_nationwide_rate(): void {
		WC_Tax::_insert_tax_rate(
			array(
				'tax_rate_country' => 'DE',
				'tax_rate_state'   => 'BY',
				'tax_rate'         => '5',
				'tax_rate_name'    => 'Regional',
				'tax_rate_class'   => '',
			)
		);

		$diff = $this->sync->plan( $this->rate_set(), $this->mapping() );

		$this->assertSame( RateDiff::ADD, $diff->entries()['DE|']['status'] );
	}

	private function answer_with_fixture(): void {
		file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			$this->fixture_dir . '/tedb.json',
			wp_json_encode(
				array(
					'request'  => array(
						'method' => 'POST',
						'url'    => Tedb::ENDPOINT,
					),
					'response' => array(
						'status' => 200,
						'body'   => file_get_contents( dirname( __DIR__, 2 ) . '/fixtures/tedb/response.xml' ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
					),
				)
			)
		);
	}

	public function test_weekly_check_stores_pending_count_and_never_applies(): void {
		$this->answer_with_fixture();

		$this->sync->check();

		$this->assertSame( 28, (int) get_option( RateSync::OPTION_PENDING ), 'The 27 member states and Northern Ireland' );
		$this->assertSame( 19.0, $this->sync->latest()->standard( 'DE' ) );
		$this->assertSame( array(), $this->woo_rates( '' ) );
	}

	public function test_failed_fetch_falls_back_to_the_shipped_snapshot(): void {
		$this->sync->check();

		$this->assertFalse( get_option( RateSync::OPTION_LATEST ) );
		$this->assertSame( 28, (int) get_option( RateSync::OPTION_PENDING ), 'The 27 member states and Northern Ireland' );
		$this->assertSame( 'tedb', $this->sync->latest()->source() );
	}

	public function test_pending_notice_shows_only_to_shop_managers(): void {
		update_option( RateSync::OPTION_PENDING, 3 );
		$screen = new RatesScreen( $this->sync );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		ob_start();
		$screen->pending_notice();
		$this->assertSame( '', ob_get_clean() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		$screen->pending_notice();
		$this->assertStringContainsString( '3 VAT rate changes available.', (string) ob_get_clean() );
	}
}
