<?php

namespace Levy\EuVat\Tests\Integration\Ledger;

use Levy\EuVat\Domain\Rule;
use Levy\EuVat\Domain\Scheme;
use Levy\EuVat\Ledger\Schema;
use Levy\EuVat\Ledger\ThresholdTracker;
use Levy\EuVat\Settings;

require_once __DIR__ . '/LedgerTestCase.php';

class ThresholdTrackerTest extends LedgerTestCase {

	/** @var ThresholdTracker */
	private $tracker;

	/** @var int */
	private $year;

	public function set_up() {
		parent::set_up();
		$this->tracker = new ThresholdTracker();
		$this->year    = (int) wp_date( 'Y' );
	}

	private function row( float $net_eur, string $scheme = Scheme::OSS_DESTINATION, string $supply = 'goods', string $country = 'DE', ?int $year = null ): void {
		global $wpdb;

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Schema::ledger_table(),
			array(
				'order_id'          => 1,
				'entry_type'        => $net_eur < 0 ? 'refund' : 'sale',
				'decision_id'       => 'd',
				'scheme'            => $scheme,
				'rule'              => Rule::B2C_DESTINATION,
				'supply_type'       => $supply,
				'customer_country'  => $country,
				'place_of_supply'   => $country,
				'rate_percent'      => 19,
				'net'               => $net_eur,
				'vat'               => 0,
				'currency'          => 'EUR',
				'eur_rate'          => 1,
				'net_eur'           => $net_eur,
				'vat_eur'           => 0,
				'supply_at'         => '2026-01-01 00:00:00',
				'period'            => ( $year ?? $this->year ) . '-Q2',
				'relates_to_period' => ( $year ?? $this->year ) . '-Q2',
				'created_at'        => '2026-01-01 00:00:00',
			)
		);
		ThresholdTracker::flush( $this->year, $this->year - 1 );
	}

	public function test_threshold_excludes_b2b_and_domestic(): void {
		$this->row( 4000, Scheme::ORIGIN, 'goods', 'DE' );
		$this->row( 3000, Scheme::OSS_DESTINATION, 'digital', 'FR' );
		$this->row( 9000, Scheme::REVERSE_CHARGE_SERVICES, 'digital', 'DE' );
		$this->row( 9000, Scheme::INTRA_EU_GOODS, 'goods', 'DE' );
		$this->row( 8000, Scheme::DOMESTIC, 'goods', 'MT' );
		$this->row( 5000, Scheme::ORIGIN, 'goods', 'MT' );
		$this->row( 2000, Scheme::ORIGIN, 'service', 'DE' );
		$this->row( 2000, Scheme::EXPORT, 'goods', 'US' );

		$status = $this->tracker->status( $this->year );

		$this->assertSame( 7000.0, $status->current_eur );
		$this->assertFalse( $status->exceeded );
	}

	public function test_exactly_the_threshold_is_not_exceeded(): void {
		$this->row( 10000 );
		$this->assertFalse( $this->tracker->status( $this->year )->exceeded );

		$this->row( 0.01 );
		$this->assertTrue( $this->tracker->status( $this->year )->exceeded );
	}

	public function test_threshold_crossing_order_is_destination(): void {
		$this->row( 9500 );

		$this->assertFalse( $this->tracker->crossing( 499.99 ) );
		$this->assertFalse( $this->tracker->crossing( 500.00 ) );
		$this->assertTrue( $this->tracker->crossing( 500.01 ) );
	}

	public function test_previous_year_over_threshold_means_exceeded(): void {
		$this->row( 10500, Scheme::OSS_DESTINATION, 'goods', 'DE', $this->year - 1 );

		$status = $this->tracker->status( $this->year );

		$this->assertTrue( $status->exceeded );
		$this->assertSame( 10500.0, $status->previous_eur );
		$this->assertSame( 0.0, $status->current_eur );
	}

	public function test_previous_year_under_threshold_with_a_quiet_current_year_is_not_exceeded(): void {
		$this->row( 9000, Scheme::OSS_DESTINATION, 'goods', 'DE', $this->year - 1 );
		$this->row( 900 );

		$this->assertFalse( $this->tracker->status( $this->year )->exceeded );
	}

	public function test_refunds_reduce_the_year_of_the_sale(): void {
		$this->row( 9000 );
		$this->row( -2500 );

		$this->assertSame( 6500.0, $this->tracker->status( $this->year )->current_eur );
	}

	public function test_filter_overrides_the_threshold(): void {
		$this->row( 6000 );
		add_filter(
			'levy_eu_vat_threshold_eur',
			static function () {
				return 5000.0;
			}
		);

		$this->assertTrue( $this->tracker->status( $this->year )->exceeded );
	}

	public function test_forced_follows_the_oss_setting(): void {
		$this->assertFalse( $this->tracker->status( $this->year )->forced );

		update_option( Settings::OPTION, array( 'oss_registered' => true ) );

		$this->assertTrue( $this->tracker->status( $this->year )->forced );
	}

	public function test_status_array_matches_the_decision_record_format(): void {
		$this->row( 8123.4 );

		$this->assertSame(
			array(
				'current_eur'  => '8123.40',
				'previous_eur' => '0.00',
				'exceeded'     => false,
				'forced'       => false,
			),
			$this->tracker->status( $this->year )->to_array()
		);
	}

	public function test_ledger_write_invalidates_the_cached_sum(): void {
		$standard = $this->tax_rate( 'DE', '19.0000' );
		$this->assertSame( 0.0, $this->tracker->status( $this->year )->current_eur );

		$order = $this->make_order(
			array(
				'created' => $this->year . '-02-10 10:00:00',
				'items'   => array(
					array(
						'name'    => 'Gadget',
						'net'     => 12000,
						'rate_id' => $standard,
						'percent' => 19,
					),
				),
			)
		);
		$this->seed_decision( $order, Scheme::ORIGIN, Rule::B2C_ORIGIN_BELOW_THRESHOLD );
		$this->writer->record_order( $order );

		$status = $this->tracker->status( $this->year );
		$this->assertSame( 12000.0, $status->current_eur );
		$this->assertTrue( $status->exceeded );
	}

	public function test_void_invalidates_the_cached_sum(): void {
		$standard = $this->tax_rate( 'DE', '19.0000' );
		$order    = $this->make_order(
			array(
				'created' => $this->year . '-02-10 10:00:00',
				'items'   => array(
					array(
						'name'    => 'Gadget',
						'net'     => 12000,
						'rate_id' => $standard,
						'percent' => 19,
					),
				),
			)
		);
		$this->seed_decision( $order, Scheme::ORIGIN, Rule::B2C_ORIGIN_BELOW_THRESHOLD );
		$this->writer->record_order( $order );
		$this->assertTrue( $this->tracker->status( $this->year )->exceeded );

		$this->writer->void_order( $order );

		$this->assertSame( 0.0, $this->tracker->status( $this->year )->current_eur );
	}
}
