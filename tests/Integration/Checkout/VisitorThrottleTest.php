<?php

namespace Levy\EuVat\Tests\Integration\Checkout;

use Levy\EuVat\Domain\Rule;
use Levy\EuVat\Orders\Revalidation;
use WP_REST_Response;

require_once __DIR__ . '/CheckoutTestCase.php';

/**
 * Anyone can call the Store API cart update, so a VAT number typed there may
 * only reach the registry within the lookup throttle; over it, checkout still
 * goes through with VAT charged and the number left for revalidation.
 */
class VisitorThrottleTest extends CheckoutTestCase {

	public function set_up() {
		parent::set_up();
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
		$this->vies_answer( self::DOWN_DE, '{"valid":true,"requestDate":"2026-10-17T10:00:00Z"}' );
		$this->vies_answer( self::INVALID_DE, '{"valid":true,"requestDate":"2026-10-17T10:00:00Z"}' );
	}

	public function tear_down() {
		unset( $_SERVER['REMOTE_ADDR'] );
		parent::tear_down();
	}

	private function client_limit( int $limit ): void {
		add_filter(
			'levy_eu_vat_client_lookup_limit',
			static function () use ( $limit ) {
				return array( $limit, 10 * MINUTE_IN_SECONDS );
			}
		);
	}

	private function global_limit( int $limit ): void {
		add_filter(
			'levy_eu_vat_global_lookup_limit',
			static function () use ( $limit ) {
				return array( $limit, HOUR_IN_SECONDS );
			}
		);
	}

	private function type_number( string $number ): WP_REST_Response {
		$response = $this->store_api(
			'POST',
			'cart/extensions',
			array(
				'namespace' => 'levy-eu-vat',
				'data'      => array(
					'vat_number' => $number,
					'country'    => 'DE',
					'postcode'   => '10115',
				),
			)
		);
		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		return $response;
	}

	public function test_cart_updates_stop_reaching_the_registry_at_the_client_cap() {
		$this->client_limit( 2 );
		$this->add_to_cart( $this->product( 'digital' ) );

		$this->type_number( self::VALID_DE );
		$this->type_number( self::DOWN_DE );
		$this->type_number( self::INVALID_DE );

		$this->assertSame( 2, $this->vies_calls );
	}

	public function test_cart_updates_stop_reaching_the_registry_at_the_global_cap() {
		$this->global_limit( 2 );
		$this->add_to_cart( $this->product( 'digital' ) );

		foreach ( array( self::VALID_DE, self::DOWN_DE, self::INVALID_DE ) as $i => $number ) {
			$_SERVER['REMOTE_ADDR'] = '203.0.113.' . ( 20 + $i );
			$this->type_number( $number );
		}

		$this->assertSame( 2, $this->vies_calls );
	}

	public function test_throttled_update_tells_the_shopper_vat_is_charged() {
		$this->client_limit( 0 );
		$this->add_to_cart( $this->product( 'digital' ) );

		$data      = json_decode( (string) wp_json_encode( $this->type_number( self::VALID_DE )->get_data() ), true );
		$extension = $data['extensions']['levy-eu-vat'];

		$this->assertSame( 0, $this->vies_calls );
		$this->assertSame( 'pending', $extension['status'] );
		$this->assertSame( 'Your VAT number has not been checked yet. VAT is charged until it is.', $extension['message'] );
	}

	public function test_cached_verdicts_are_not_counted() {
		$this->client_limit( 2 );
		$this->add_to_cart( $this->product( 'digital' ) );

		$this->type_number( self::VALID_DE );
		$this->type_number( self::VALID_DE );
		$this->type_number( self::VALID_DE );
		$this->type_number( self::DOWN_DE );

		$this->assertSame( 2, $this->vies_calls, 'Repeat updates for the cached number left room for the second number.' );
	}

	/**
	 * A shopper throttled while typing still gets one real check when placing
	 * the order: checkout has its own per-client allowance.
	 */
	public function test_checkout_gets_its_own_lookup_after_cart_updates_were_throttled() {
		$this->client_limit( 1 );
		$this->add_to_cart( $this->product( 'digital' ) );
		$this->type_number( self::DOWN_DE );
		$this->type_number( self::VALID_DE );
		$this->assertSame( 1, $this->vies_calls );

		$order = $this->order_from( $this->block_checkout( 'DE', '10115' ) );

		$this->assertSame( 2, $this->vies_calls );
		$this->assertTrue( $this->stored_decision( $order )->is_vat_exempt() );
	}

	/**
	 * @return array<string,array{0: string}>
	 */
	public function outage_policies(): array {
		return array(
			'charge'         => array( 'charge' ),
			'exempt_pending' => array( 'exempt_pending' ),
			'reject'         => array( 'reject' ),
		);
	}

	/**
	 * A throttle is something a visitor can bring about, so it never exempts
	 * (exempt_pending) or refuses the order (reject) the way an outage would.
	 *
	 * @dataProvider outage_policies
	 */
	public function test_throttled_checkout_charges_vat_and_flags_the_order_pending( string $policy ) {
		$this->settings( array( 'outage_policy' => $policy ) );
		$this->client_limit( 0 );
		$this->add_to_cart( $this->product( 'digital' ) );
		WC()->customer->update_meta_data( '_levy_eu_vat_number', self::VALID_DE );

		$order = $this->order_from( $this->block_checkout( 'DE', '10115' ) );

		$this->assertSame( 0, $this->vies_calls );
		$decision = $this->stored_decision( $order );
		$this->assertSame( Rule::PENDING_CHARGED, $decision->rule() );
		$this->assertContains( Revalidation::PENDING_FLAG, $decision->flags() );
		$this->assertSame( 18.0, (float) $order->get_total_tax() );
		$this->assertSame( 'no', $order->get_meta( 'is_vat_exempt' ) );
		$this->assertTrue( as_has_scheduled_action( Revalidation::HOOK, array( $order->get_id(), 1 ), Revalidation::GROUP ) );
	}

	public function test_revalidation_is_not_throttled() {
		$this->client_limit( 0 );
		$this->global_limit( 0 );
		$this->add_to_cart( $this->product( 'digital' ) );
		WC()->customer->update_meta_data( '_levy_eu_vat_number', self::VALID_DE );
		$order = $this->order_from( $this->block_checkout( 'DE', '10115' ) );
		$this->assertSame( 0, $this->vies_calls );

		do_action( 'levy_eu_vat_revalidate', $order->get_id(), 1 );

		$this->assertSame( 1, $this->vies_calls );
		$this->assertNotContains( Revalidation::PENDING_FLAG, $this->stored_decision( $order )->flags() );
	}
}
