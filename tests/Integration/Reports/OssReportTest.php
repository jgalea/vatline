<?php

namespace Vatright\Tests\Integration\Reports;

use Vatright\Domain\Scheme;
use Vatright\Ledger\Fx;
use Vatright\Reports\OssReport;
use RuntimeException;

require_once __DIR__ . '/ReportsTestCase.php';

class OssReportTest extends ReportsTestCase {

	/** @var OssReport */
	private $report;

	public function set_up() {
		parent::set_up();
		$this->report = new OssReport( new Fx( $this->transport ) );
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 * @return array<string,array<string,mixed>>
	 */
	private function by_key( array $rows ): array {
		$keyed = array();
		foreach ( $rows as $row ) {
			$keyed[ $row['member_state'] . '|' . $row['rate_percent'] . '|' . $row['supply_type'] ] = $row;
		}
		return $keyed;
	}

	public function test_oss_groups_by_country_rate_and_type(): void {
		$this->sell( Scheme::OSS_DESTINATION, 'goods', 'DE', 100, 19 );
		$this->sell( Scheme::OSS_DESTINATION, 'goods', 'DE', 50, 19 );
		$this->sell( Scheme::OSS_DESTINATION, 'goods', 'DE', 10, 7 );
		$this->sell( Scheme::OSS_DESTINATION, 'digital', 'FR', 40, 20 );
		$this->sell( Scheme::OSS_DESTINATION, 'service', 'FR', 10, 20 );
		$this->sell( Scheme::ORIGIN, 'goods', 'DE', 999, 19 );
		$this->sell( Scheme::OSS_DESTINATION, 'goods', 'DE', 777, 19, array( 'created' => '2026-06-30 10:00:00' ) );

		$report = $this->report->build( 2026, 3 );
		$rows   = $this->by_key( $report['rows'] );

		$this->assertSame( '2026-Q3', $report['period'] );
		$this->assertCount( 3, $rows );
		$this->assertSame( 150.0, $rows['DE|19|goods']['taxable'] );
		$this->assertSame( 28.5, $rows['DE|19|goods']['vat'] );
		$this->assertSame( 10.0, $rows['DE|7|goods']['taxable'] );
		$this->assertSame( 0.7, $rows['DE|7|goods']['vat'] );
		$this->assertSame( 50.0, $rows['FR|20|services']['taxable'] );
		$this->assertSame( 10.0, $rows['FR|20|services']['vat'] );
		$this->assertSame( array(), $report['corrections'] );
		$this->assertSame( array(), $report['rates'] );
	}

	public function test_oss_refund_in_later_quarter_appears_as_correction(): void {
		$order = $this->sell( Scheme::OSS_DESTINATION, 'goods', 'DE', 100, 19 );
		$this->refund_item( $order, 40, 7.6, '2026-10-05 10:00:00' );

		$third  = $this->report->build( 2026, 3 );
		$fourth = $this->report->build( 2026, 4 );

		$this->assertSame( 60.0, $third['rows'][0]['taxable'] );
		$this->assertSame( 11.4, $third['rows'][0]['vat'] );
		$this->assertSame( array(), $third['corrections'] );

		$this->assertSame( array(), $fourth['rows'] );
		$this->assertCount( 1, $fourth['corrections'] );
		$this->assertSame( '2026-Q3', $fourth['corrections'][0]['relates_to_period'] );
		$this->assertSame( 'DE', $fourth['corrections'][0]['member_state'] );
		$this->assertSame( -40.0, $fourth['corrections'][0]['taxable'] );
		$this->assertSame( -7.6, $fourth['corrections'][0]['vat'] );
	}

	public function test_oss_converts_usd_at_period_end_rate(): void {
		$this->sell(
			Scheme::OSS_DESTINATION,
			'goods',
			'DE',
			113.55,
			19,
			array(
				'currency' => 'USD',
				'created'  => '2026-09-25 10:00:00',
			)
		);

		$report = $this->report->build( 2026, 3 );

		$this->assertSame( 100.0, $report['rows'][0]['taxable'] );
		$this->assertSame( 19.0, $report['rows'][0]['vat'] );
		$this->assertCount( 1, $report['rates'] );
		$this->assertSame( 'USD', $report['rates'][0]['currency'] );
		$this->assertSame( '2026-09-30', $report['rates'][0]['rate_date'] );
		$this->assertSame( 1.1355, $report['rates'][0]['rate'] );
		$this->assertSame( '2026-Q3', $report['rates'][0]['period'] );
	}

	public function test_oss_return_currency_other_than_eur(): void {
		$this->sell( Scheme::OSS_DESTINATION, 'goods', 'DE', 100, 19 );

		$report = $this->report->build( 2026, 3, 'usd' );

		$this->assertSame( 'USD', $report['currency'] );
		$this->assertSame( 113.55, $report['rows'][0]['taxable'] );
	}

	public function test_oss_missing_rate_throws(): void {
		$this->sell( Scheme::OSS_DESTINATION, 'goods', 'DE', 100, 19, array( 'currency' => 'GBP' ) );

		$this->expectException( RuntimeException::class );
		$this->report->build( 2026, 3 );
	}

	public function test_oss_csv_lists_rates_and_corrections(): void {
		$this->sell(
			Scheme::OSS_DESTINATION,
			'goods',
			'DE',
			113.55,
			19,
			array(
				'currency' => 'USD',
				'created'  => '2026-09-25 10:00:00',
			)
		);
		$late = $this->sell( Scheme::OSS_DESTINATION, 'goods', 'FR', 100, 20 );
		$this->refund_item( $late, 10, 2, '2026-10-05 10:00:00' );

		list( $header, $lines ) = OssReport::csv( $this->report->build( 2026, 3 ) );
		$this->assertSame( 'Taxable amount (EUR)', $header[3] );
		$flat = wp_json_encode( $lines );
		$this->assertStringContainsString( '"USD","2026-Q3","2026-09-30",1.1355', (string) $flat );
		$this->assertStringContainsString( 'ECB reference rate of the last day of the period', (string) $flat );

		list( , $lines ) = OssReport::csv( $this->report->build( 2026, 4 ) );
		$this->assertStringContainsString( '"2026-Q3"', (string) wp_json_encode( $lines ) );
		$this->assertContains( '-10.00', array_merge( ...$lines ) );
	}

	/**
	 * KG-9: VAT charged at the store's rate for want of a destination rate is
	 * not destination VAT. It stays out of the return's rows and corrections
	 * and is listed per order for the merchant to correct.
	 */
	public function test_sales_charged_at_store_rate_are_listed_for_correction_not_reported(): void {
		$this->sell( Scheme::OSS_DESTINATION, 'goods', 'DE', 100, 19 );
		$fallback = $this->sell_at_store_rate( 'IT', 50 );
		$this->refund_item( $fallback, 10, 1.8, '2026-10-05 10:00:00' );

		$third  = $this->report->build( 2026, 3 );
		$fourth = $this->report->build( 2026, 4 );

		$this->assertSame( array( 'DE|19|goods' ), array_keys( $this->by_key( $third['rows'] ) ) );
		$this->assertSame(
			array(
				array(
					'order_id'     => $fallback->get_id(),
					'member_state' => 'IT',
					'rate_percent' => 18.0,
					'supply_type'  => 'goods',
					'taxable'      => 40.0,
					'vat'          => 7.2,
				),
			),
			$third['needs_correction']
		);
		$this->assertSame( array(), $fourth['corrections'], 'A refund of an unreported sale is no correction to the return' );
		$this->assertSame( array(), $fourth['needs_correction'] );

		list( , $lines ) = OssReport::csv( $third );
		$this->assertContains( OssReport::needs_correction_title(), array_column( $lines, 0 ) );
		$this->assertContains( array( 'IT', $fallback->get_id(), 18.0, 'goods', '40.00', '7.20' ), $lines );
	}

	public function test_a_euro_only_quarter_has_no_exchange_rate_block(): void {
		$this->sell( Scheme::OSS_DESTINATION, 'goods', 'DE', 100, 19 );

		list( , $lines ) = OssReport::csv( $this->report->build( 2026, 3 ) );

		$this->assertNotSame( array(), $lines );
		$this->assertNotSame( array(), end( $lines ), 'No trailing blank line' );
		$this->assertStringNotContainsString( 'Exchange rates used', (string) wp_json_encode( $lines ) );
	}
}
