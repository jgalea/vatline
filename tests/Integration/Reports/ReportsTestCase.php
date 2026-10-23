<?php

namespace Vatright\Tests\Integration\Reports;

use Vatright\Checkout\TaxLocation;
use Vatright\Domain\Rule;
use Vatright\Domain\Scheme;
use Vatright\Ledger\LedgerWriter;
use Vatright\Tests\Integration\Ledger\LedgerTestCase;
use WC_Order;

require_once dirname( __DIR__ ) . '/Ledger/LedgerTestCase.php';

abstract class ReportsTestCase extends LedgerTestCase {

	/**
	 * Records one order in the ledger. $supply is the supply type of its single line.
	 *
	 * @param array<string,mixed> $order_args make_order arguments besides the item
	 */
	protected function sell( string $scheme, string $supply, string $place, float $net, float $percent, array $order_args = array(), ?string $vat_number = null, ?string $rule = null ): WC_Order {
		$rate_id = $this->tax_rate( $place, (string) $percent );
		$order   = $this->make_order(
			array_merge(
				array(
					'items' => array(
						array(
							'name'    => 'Item',
							'net'     => $net,
							'rate_id' => $rate_id,
							'percent' => $percent,
						),
					),
				),
				$order_args
			)
		);
		$this->seed_decision( $order, $scheme, $rule ?? Rule::B2C_DESTINATION, array( 'Item' => array( $supply, $place, $scheme ) ), $vat_number );
		$this->writer->record_order( $order );
		return $order;
	}

	/**
	 * An OSS sale to $place charged at the store's 18% because the tax tables
	 * had no rate for $place: the decision carries rate_missing.
	 */
	protected function sell_at_store_rate( string $place, float $net ): WC_Order {
		$order    = $this->make_order(
			array(
				'items' => array(
					array(
						'name'    => 'Item',
						'net'     => $net,
						'rate_id' => $this->tax_rate( 'MT', '18' ),
						'percent' => 18.0,
					),
				),
			)
		);
		$decision = $this->seed_decision( $order, Scheme::OSS_DESTINATION, Rule::B2C_DESTINATION, array( 'Item' => array( 'goods', $place, Scheme::OSS_DESTINATION ) ) );
		$order->update_meta_data( LedgerWriter::DECISION_META, wp_json_encode( $decision->with_flag( TaxLocation::MISSING_FLAG )->to_array() ) );
		$order->save_meta_data();
		$this->writer->record_order( $order );
		return $order;
	}

	protected function refund_item( WC_Order $order, float $net, float $tax, string $when ): void {
		$item_id = array_key_first( $order->get_items() );
		$rate_id = array_key_first( $order->get_items()[ $item_id ]->get_taxes()['total'] );
		$refund  = wc_create_refund(
			array(
				'order_id'   => $order->get_id(),
				'amount'     => (string) ( $net + $tax ),
				'line_items' => array(
					$item_id => array(
						'qty'          => 1,
						'refund_total' => $net,
						'refund_tax'   => array( $rate_id => $tax ),
					),
				),
			)
		);
		$this->assertNotWPError( $refund );
		$refund->set_date_created( strtotime( $when . ' UTC' ) );
		$refund->save();
		$this->writer->record_refund( $order->get_id(), $refund->get_id() );
	}

	protected function reverse_charge( string $supply, float $net, string $vat_number, string $created = '2026-09-15 10:00:00' ): WC_Order {
		$scheme = 'goods' === $supply ? Scheme::INTRA_EU_GOODS : Scheme::REVERSE_CHARGE_SERVICES;
		$rule   = 'goods' === $supply ? Rule::RC_GOODS_ART138 : Rule::RC_SERVICES_ART196;
		$order  = $this->make_order(
			array(
				'created' => $created,
				'items'   => array(
					array(
						'name' => 'Item',
						'net'  => $net,
					),
				),
			)
		);
		$this->seed_decision( $order, $scheme, $rule, array( 'Item' => array( $supply, substr( $vat_number, 0, 2 ), $scheme ) ), $vat_number );
		$this->writer->record_order( $order );
		return $order;
	}
}
