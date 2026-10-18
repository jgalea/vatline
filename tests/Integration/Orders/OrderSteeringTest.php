<?php

namespace Levy\EuVat\Tests\Integration\Orders;

use Levy\EuVat\Checkout\InputGatherer;
use Levy\EuVat\Checkout\TaxLocation;
use Levy\EuVat\Orders\DecisionStore;

require_once __DIR__ . '/OrderTestCase.php';

class OrderSteeringTest extends OrderTestCase {

	public function test_order_exempt_filter_follows_stored_decision() {
		$order = $this->order( 'DE', 'DE', '10115', array( 'digital' ) );
		DecisionStore::apply( $order, $this->decide( 'DE', 'DE', '10115', array( 'digital' ), false, 'DE114103379', 'valid' ), 'checkout' );
		$order->update_meta_data( 'is_vat_exempt', 'no' );
		$order->calculate_totals();
		$this->assertSame( 0.0, (float) $order->get_total_tax(), 'Exempt decision wins over a stale meta value' );

		DecisionStore::apply( $order, $this->decide( 'DE', 'DE', '10115', array( 'digital' ), false, 'DE114103379', 'invalid' ), 'admin' );
		$order->update_meta_data( 'is_vat_exempt', 'yes' );
		$order->calculate_totals();
		$this->assertSame( 18.0, (float) $order->get_total_tax(), 'Charged decision wins over a stale exemption' );
	}

	public function test_tax_location_follows_uniform_decision() {
		$order = $this->order( 'DE', 'DE', '10115', array( 'goods' ) );
		DecisionStore::apply( $order, $this->decide( 'DE', 'DE', '10115', array( 'goods' ) ), 'checkout' );

		$order->calculate_totals();

		$this->assertSame( array( $this->rates['MT'] => 19.8 ), $this->order_taxes( $order ), 'Below the threshold: Maltese VAT on goods and shipping to Germany' );
	}

	public function test_order_line_rates_follow_mixed_decision() {
		$order = $this->order( 'FR', 'DE', '10115', array( 'goods', 'digital' ) );
		DecisionStore::apply( $order, $this->decide( 'FR', 'DE', '10115', array( 'goods', 'digital' ), true, '', '', 'FR' ), 'checkout' );

		$order->calculate_totals();

		$this->assertSame(
			array(
				$this->rates['DE'] => 20.9,
				$this->rates['FR'] => 20.0,
			),
			$this->order_taxes( $order )
		);
	}

	public function test_order_rate_missing_falls_back_and_flags() {
		$order = $this->order( 'GB', 'GB', 'BT1 1AA', array( 'goods' ) );
		DecisionStore::apply( $order, $this->decide( 'GB', 'GB', 'BT1 1AA', array( 'goods' ), true ), 'checkout' );
		$order->save();

		$order->calculate_totals();
		$order = wc_get_order( $order->get_id() );

		$this->assertSame( array( $this->rates['MT'] => 19.8 ), $this->order_taxes( $order ) );
		$this->assertContains( TaxLocation::MISSING_FLAG, DecisionStore::get( $order )->flags() );
	}

	/**
	 * KG-8: in a mixed decision shipping follows the goods. Italy has no rate
	 * row, so the goods and their shipping fall back to the store's rate.
	 */
	public function test_mixed_order_shipping_without_destination_rate_falls_back_and_flags() {
		$order = $this->order( 'FR', 'IT', '00100', array( 'goods', 'digital' ) );
		DecisionStore::apply( $order, $this->decide( 'FR', 'IT', '00100', array( 'goods', 'digital' ), true, '', '', 'FR' ), 'checkout' );
		$order->save();
		$this->assertNull( DecisionStore::get( $order )->tax_country() );

		$order->calculate_totals();
		$order = wc_get_order( $order->get_id() );

		$this->assertSame(
			array(
				$this->rates['MT'] => 19.8,
				$this->rates['FR'] => 20.0,
			),
			$this->order_taxes( $order )
		);
		$this->assertEqualsWithDelta( 1.8, (float) $order->get_shipping_tax(), 0.001 );
		$this->assertContains( TaxLocation::MISSING_FLAG, DecisionStore::get( $order )->flags() );
	}

	public function test_export_order_untaxed_even_when_a_rate_matches() {
		\WC_Tax::_insert_tax_rate(
			array(
				'tax_rate_country'  => 'US',
				'tax_rate'          => '7',
				'tax_rate_name'     => 'Sales tax',
				'tax_rate_priority' => 1,
				'tax_rate_shipping' => 1,
				'tax_rate_class'    => '',
			)
		);
		$order = $this->order( 'US', 'US', '10001', array( 'goods' ) );
		DecisionStore::apply( $order, $this->decide( 'US', 'US', '10001', array( 'goods' ) ), 'checkout' );

		$order->calculate_totals();

		$this->assertSame( 0.0, (float) $order->get_total_tax() );
	}

	public function test_orders_without_decision_are_left_to_woo() {
		$order = $this->order( 'DE', 'DE', '10115', array( 'goods' ) );

		$order->calculate_totals();

		$this->assertSame( array( $this->rates['DE'] => 20.9 ), $this->order_taxes( $order ) );
	}

	public function test_line_key_is_hidden_from_the_order_items_box() {
		$order   = $this->order( 'DE', 'DE', '10115', array( 'goods' ) );
		$item    = current( $order->get_items() );
		$item_id = $item->get_id();
		$this->assertNotSame( '', (string) $item->get_meta( InputGatherer::LINE_KEY_META ), 'Control: the item carries the key' );

		ob_start();
		include WP_PLUGIN_DIR . '/woocommerce/includes/admin/meta-boxes/views/html-order-item-meta.php';
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( InputGatherer::LINE_KEY_META, $html );
	}
}
