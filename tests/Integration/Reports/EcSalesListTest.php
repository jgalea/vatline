<?php

namespace Vatline\Tests\Integration\Reports;

use Vatline\Domain\Scheme;
use Vatline\Ledger\Schema;
use Vatline\Reports\EcSalesList;
use Vatline\Reports\IncompleteReport;

require_once __DIR__ . '/ReportsTestCase.php';

class EcSalesListTest extends ReportsTestCase {

	public function test_esl_groups_by_customer_vat(): void {
		$this->reverse_charge( 'digital', 100, 'DE123456789' );
		$this->reverse_charge( 'digital', 50, 'DE123456789' );
		$this->reverse_charge( 'goods', 30, 'DE123456789' );
		$this->reverse_charge( 'service', 20, 'EL123456789' );
		$this->reverse_charge( 'digital', 999, 'DE123456789', '2026-06-30 10:00:00' );
		$this->sell( Scheme::OSS_DESTINATION, 'goods', 'DE', 500, 19 );

		$rows = ( new EcSalesList() )->build( 2026, 3 );

		$this->assertSame(
			array(
				array(
					'customer_vat' => 'DE123456789',
					'country'      => 'DE',
					'type'         => 'goods',
					'net_eur'      => 30.0,
				),
				array(
					'customer_vat' => 'DE123456789',
					'country'      => 'DE',
					'type'         => 'services',
					'net_eur'      => 150.0,
				),
				array(
					'customer_vat' => 'EL123456789',
					'country'      => 'EL',
					'type'         => 'services',
					'net_eur'      => 20.0,
				),
			),
			$rows
		);
	}

	public function test_esl_ignores_other_schemes_even_with_a_vat_number(): void {
		$order = $this->sell( Scheme::OSS_DESTINATION, 'goods', 'DE', 500, 19 );
		global $wpdb;
		$wpdb->update( Schema::ledger_table(), array( 'customer_vat' => 'DE123456789' ), array( 'order_id' => $order->get_id() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$this->assertSame( array(), ( new EcSalesList() )->build( 2026, 3 ) );
	}

	public function test_esl_is_incomplete_when_an_order_has_no_eur_amount(): void {
		$this->reverse_charge( 'digital', 100, 'DE123456789' );
		$gbp = $this->reverse_charge( 'digital', 50, 'FR12345678901' );
		global $wpdb;
		$wpdb->update( Schema::ledger_table(), array( 'net_eur' => null ), array( 'order_id' => $gbp->get_id() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		try {
			( new EcSalesList() )->build( 2026, 3 );
			$this->fail( 'Expected IncompleteReport.' );
		} catch ( IncompleteReport $e ) {
			$this->assertSame( array( $gbp->get_id() ), $e->order_ids() );
		}
	}

	public function test_esl_refund_reduces_the_period_of_the_sale(): void {
		$order = $this->reverse_charge( 'digital', 100, 'DE123456789' );
		$this->refund_item( $order, 30, 0, '2026-10-05 10:00:00' );

		$list = new EcSalesList();

		$this->assertSame( 70.0, $list->build( 2026, 3 )[0]['net_eur'] );
		$this->assertSame( array(), $list->build( 2026, 4 ) );
	}

	public function test_esl_drops_customers_that_net_to_zero(): void {
		$order = $this->reverse_charge( 'digital', 100, 'DE123456789' );
		$this->refund_item( $order, 100, 0, '2026-09-20 10:00:00' );

		$this->assertSame( array(), ( new EcSalesList() )->build( 2026, 3 ) );
	}

	public function test_esl_csv_formats_amounts(): void {
		$this->reverse_charge( 'digital', 12.5, 'DE123456789' );

		list( $header, $lines ) = EcSalesList::csv( ( new EcSalesList() )->build( 2026, 3 ) );

		$this->assertSame( 'VAT number', $header[0] );
		$this->assertSame( array( array( 'DE123456789', 'DE', 'services', '12.50' ) ), $lines );
	}
}
