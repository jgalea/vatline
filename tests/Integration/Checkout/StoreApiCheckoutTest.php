<?php

namespace Vatright\Tests\Integration\Checkout;

use Vatright\Checkout\TaxLocation;
use Vatright\Domain\Rule;

require_once __DIR__ . '/CheckoutTestCase.php';

class StoreApiCheckoutTest extends CheckoutTestCase {

	/**
	 * Woo's hook table lists woocommerce_customer_taxable_address as "Unknown"
	 * for blocks. A German consumer below the threshold must pay Maltese VAT
	 * through the Store API. Shipping tax depends on the taxable address alone
	 * (per-line rates never touch it), so it proves the filter itself; the
	 * control shows the same cart pays German VAT once the store is
	 * OSS-registered.
	 */
	public function test_store_api_respects_taxable_address_filter() {
		$this->add_to_cart( $this->product( 'goods' ) );

		$totals = $this->store_api_totals( 'DE', '10115' );
		$this->assertSame( '1800', $totals->total_items_tax );
		$this->assertSame( '180', $totals->total_shipping_tax );
		$this->assertSame( Rule::B2C_ORIGIN_BELOW_THRESHOLD, $this->steering()->current()->rule() );

		$this->settings( array( 'oss_registered' => true ) );
		$this->steering()->reset();
		$totals = $this->store_api_totals( 'DE', '10115' );
		$this->assertSame( '1900', $totals->total_items_tax );
		$this->assertSame( '190', $totals->total_shipping_tax );
	}

	/**
	 * @return object Store API cart totals.
	 */
	private function store_api_totals( string $country, string $postcode ) {
		$this->store_api(
			'POST',
			'cart/update-customer',
			array(
				'billing_address'  => $this->address( $country, $postcode ),
				'shipping_address' => $this->address( $country, $postcode, false ),
			)
		);
		$response = $this->store_api(
			'POST',
			'cart/select-shipping-rate',
			array(
				'package_id' => 0,
				'rate_id'    => 'flat_rate:' . $this->flat_instance,
			)
		);
		$this->assertSame( 200, $response->get_status() );
		return $response->get_data()['totals'];
	}

	public function test_valid_number_exempts_cart_and_order_meta() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'customer' ) ) );
		WC()->customer = new \WC_Customer( get_current_user_id(), true );
		$this->add_to_cart( $this->product( 'digital' ) );
		WC()->customer->update_meta_data( '_vatright_number', self::VALID_DE );

		$order = $this->order_from( $this->block_checkout( 'DE', '10115' ) );

		$this->assertSame( 0.0, (float) $order->get_total_tax() );
		$this->assertSame( 'yes', $order->get_meta( 'is_vat_exempt' ) );
		$decision = $this->stored_decision( $order );
		$this->assertSame( Rule::RC_SERVICES_ART196, $decision->rule() );
		$this->assertSame( self::VALID_DE, $order->get_meta( '_vatright_number' ) );
	}

	public function test_guest_store_api_checkout_exemption() {
		wp_set_current_user( 0 );
		$this->add_to_cart( $this->product( 'goods' ) );
		WC()->customer->update_meta_data( '_vatright_number', self::VALID_DE );

		$order = $this->order_from( $this->block_checkout( 'DE', '10115' ) );

		$this->assertSame( 0, $order->get_customer_id() );
		$this->assertSame( 0.0, (float) $order->get_total_tax() );
		$this->assertSame( 'yes', $order->get_meta( 'is_vat_exempt' ) );
		$this->assertSame( Rule::RC_GOODS_ART138, $this->stored_decision( $order )->rule() );
	}

	public function test_cache_miss_at_checkout_validates_once() {
		$this->add_to_cart( $this->product( 'digital' ) );
		WC()->customer->update_meta_data( '_vatright_number', self::VALID_DE );

		$this->store_api( 'POST', 'cart/update-customer', array( 'billing_address' => $this->address( 'DE', '10115' ) ) );
		$this->assertSame( 0, $this->vies_calls, 'Cart recalculation must not call the registry.' );
		$this->assertSame( Rule::PENDING_CHARGED, $this->steering()->current()->rule() );

		$order = $this->order_from( $this->block_checkout( 'DE', '10115' ) );

		$this->assertSame( 1, $this->vies_calls );
		$this->assertTrue( $this->stored_decision( $order )->is_vat_exempt() );
	}

	public function test_vies_down_charge_policy_places_order_flagged() {
		$this->vies_answer( self::DOWN_DE, '{"actionSucceed":false,"errorWrappers":[{"error":"MS_UNAVAILABLE"}]}' );
		$this->add_to_cart( $this->product( 'digital' ) );
		WC()->customer->update_meta_data( '_vatright_number', self::DOWN_DE );

		$order = $this->order_from( $this->block_checkout( 'DE', '10115' ) );

		$decision = $this->stored_decision( $order );
		$this->assertSame( Rule::PENDING_CHARGED, $decision->rule() );
		$this->assertContains( 'validation_pending', $decision->flags() );
		$this->assertSame( 18.0, (float) $order->get_total_tax() );
		$this->assertSame( 'no', $order->get_meta( 'is_vat_exempt' ) );
	}

	public function test_reject_policy_blocks_block_checkout_with_route_error() {
		$this->settings( array( 'outage_policy' => 'reject' ) );
		$this->vies_answer( self::DOWN_DE, '{"actionSucceed":false,"errorWrappers":[{"error":"MS_UNAVAILABLE"}]}' );
		$this->add_to_cart( $this->product( 'digital' ) );
		WC()->customer->update_meta_data( '_vatright_number', self::DOWN_DE );

		$response = $this->block_checkout( 'DE', '10115' );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'vatright_registry_unavailable', $response->get_data()['code'] );
		$this->assertSame( array(), wc_get_orders( array( 'status' => array( 'processing', 'completed', 'on-hold', 'pending' ) ) ) );
	}

	public function test_invalid_number_blocks_block_checkout() {
		$this->vies_answer( self::INVALID_DE, '{"countryCode":"DE","vatNumber":"123475223","valid":false}' );
		$this->add_to_cart( $this->product( 'digital' ) );
		WC()->customer->update_meta_data( '_vatright_number', self::INVALID_DE );

		$response = $this->block_checkout( 'DE', '10115' );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'vatright_invalid_vat_number', $response->get_data()['code'] );
	}

	/**
	 * The guard throws on the unanswered request, as a broken transport would.
	 */
	public function test_registry_exception_never_breaks_checkout() {
		$this->add_to_cart( $this->product( 'digital' ) );
		WC()->customer->update_meta_data( '_vatright_number', self::DOWN_DE );

		$order = $this->order_from( $this->block_checkout( 'DE', '10115' ) );

		$this->assertSame( 1, $this->vies_calls );
		$decision = $this->stored_decision( $order );
		$this->assertSame( Rule::PENDING_CHARGED, $decision->rule() );
		$this->assertSame( 18.0, (float) $order->get_total_tax() );
	}

	/**
	 * The Store API recalculates the draft order through WC_Order, not the
	 * cart, so per-line rates have to survive that second calculation.
	 */
	public function test_mixed_cart_block_order_keeps_line_rates() {
		$this->settings( array( 'oss_registered' => true ) );
		$this->add_to_cart( $this->product( 'goods' ), $this->product( 'digital' ) );
		add_filter(
			'woocommerce_geolocate_ip',
			static function () {
				return 'FR';
			}
		);
		$_SERVER['REMOTE_ADDR'] = '203.0.113.5';
		WC()->session->set( 'vatright_self_certified', 'FR' );

		$order = $this->order_from( $this->block_checkout( 'FR', '75001', array(), 'DE', '10115' ) );
		unset( $_SERVER['REMOTE_ADDR'] );

		$taxes = $this->order_taxes( $order );
		$this->assertEqualsWithDelta( 19.0 + 1.9, $taxes[ $this->rates['DE'] ] ?? 0.0, 0.001, 'Book and shipping at the German rate' );
		$this->assertEqualsWithDelta( 20.0, $taxes[ $this->rates['FR'] ] ?? 0.0, 0.001, 'E-book at the French rate' );
	}

	public function test_northern_ireland_block_order_charges_origin_and_flags() {
		$this->settings( array( 'oss_registered' => true ) );
		$this->add_to_cart( $this->product( 'goods' ) );

		$order = $this->order_from( $this->block_checkout( 'GB', 'BT1 1AA' ) );

		$this->assertEqualsWithDelta( 18.0 + 1.8, (float) $order->get_total_tax(), 0.001 );
		$this->assertContains( TaxLocation::MISSING_FLAG, $this->stored_decision( $order )->flags() );
	}
}
