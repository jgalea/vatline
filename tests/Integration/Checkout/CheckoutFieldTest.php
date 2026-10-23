<?php

namespace Vatright\Tests\Integration\Checkout;

use Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFields;
use Automattic\WooCommerce\Blocks\Package;
use Vatright\Admin\ProductSupplyField;
use Vatright\Checkout\CartSteering;
use Vatright\Checkout\InputGatherer;
use Vatright\Checkout\StoreApiExtension;
use Vatright\Checkout\VatField;
use Vatright\Domain\Decision;
use WC_Customer;
use WP_Error;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- the tests fire WooCommerce's own hooks.

require_once __DIR__ . '/CheckoutTestCase.php';

class CheckoutFieldTest extends CheckoutTestCase {

	/**
	 * @return array<string,mixed>
	 */
	private function extension( \WP_REST_Response $response ): array {
		$data = json_decode( (string) wp_json_encode( $response->get_data() ), true );
		return $data['extensions']['vatright'];
	}

	public function test_block_field_is_registered_once_in_the_contact_location() {
		$fields = Package::container()->get( CheckoutFields::class );

		$this->assertArrayHasKey( VatField::FIELD_ID, $fields->get_fields_for_location( 'contact' ) );
		$this->assertArrayNotHasKey( VatField::FIELD_ID, $fields->get_fields_for_location( 'address' ) );
		$this->assertArrayNotHasKey( VatField::FIELD_ID, $fields->get_fields_for_location( 'order' ) );
	}

	public function test_order_confirmation_and_emails_leave_the_number_to_the_totals_table() {
		$fields = Package::container()->get( CheckoutFields::class );
		$shown  = $fields->filter_fields_for_order_confirmation( array( VatField::FIELD_ID => array( 'value' => self::VALID_DE ) + $fields->get_fields_for_location( 'contact' )[ VatField::FIELD_ID ] ) );

		$this->assertSame( array(), $shown );
	}

	/**
	 * @dataProvider messy_numbers
	 */
	public function test_normalises_messy_input( string $typed, string $stored ) {
		$this->assertSame( $stored, VatField::normalise( $typed ) );
	}

	/**
	 * @return array<string,array{string,string}>
	 */
	public function messy_numbers(): array {
		return array(
			'spaces and dots' => array( ' de 114.103.379 ', 'DE114103379' ),
			'lower case'      => array( 'de114103379', 'DE114103379' ),
			'no prefix'       => array( '114103379', '114103379' ),
			'empty'           => array( '   ', '' ),
		);
	}

	public function test_cleared_field_clears_number() {
		$customer = new WC_Customer();
		$customer->update_meta_data( InputGatherer::NUMBER_META, self::VALID_DE );

		do_action( 'woocommerce_set_additional_field_value', VatField::FIELD_ID, '', 'other', $customer );

		$this->assertSame( '', $customer->get_meta( InputGatherer::NUMBER_META ) );
	}

	public function test_block_value_is_mirrored_normalised_on_customer_and_order() {
		$customer = new WC_Customer();

		do_action( 'woocommerce_set_additional_field_value', VatField::FIELD_ID, 'de 114 103 379', 'other', $customer );
		do_action( 'woocommerce_set_additional_field_value', 'other-plugin/field', 'ignored', 'other', $customer );

		$this->assertSame( self::VALID_DE, $customer->get_meta( InputGatherer::NUMBER_META ) );
	}

	public function test_block_checkout_request_stores_the_number_and_exempts() {
		$this->add_to_cart( $this->product( 'goods' ) );

		$order = $this->order_from( $this->block_checkout( 'DE', '10115', array( 'additional_fields' => array( VatField::FIELD_ID => 'de 114.103.379' ) ) ) );

		$this->assertSame( self::VALID_DE, $order->get_meta( InputGatherer::NUMBER_META ) );
		$this->assertSame( 'yes', $order->get_meta( 'is_vat_exempt' ) );
	}

	public function test_block_checkout_request_with_empty_field_charges_vat() {
		WC()->customer->update_meta_data( InputGatherer::NUMBER_META, self::VALID_DE );
		$this->add_to_cart( $this->product( 'goods' ) );

		$order = $this->order_from( $this->block_checkout( 'DE', '10115', array( 'additional_fields' => array( VatField::FIELD_ID => '' ) ) ) );

		$this->assertSame( 'no', $order->get_meta( 'is_vat_exempt' ) );
		$this->assertSame( '', (string) $order->get_meta( InputGatherer::NUMBER_META ) );
	}

	public function test_validate_callback_never_calls_the_network() {
		$this->customer( 'DE', '10115' );
		$field = Package::container()->get( CheckoutFields::class )->get_fields_for_location( 'contact' )[ VatField::FIELD_ID ];
		$check = $field['validate_callback'];

		$this->assertTrue( $check( '' ) );
		$this->assertTrue( $check( self::VALID_DE ) );
		$this->assertInstanceOf( WP_Error::class, $check( 'DE123' ) );
		$this->assertInstanceOf( WP_Error::class, $check( 'not a number' ) );
		$this->assertSame( 0, $this->vies_calls );
	}

	public function test_validate_callback_rejects_a_cached_invalid_number() {
		$this->vies_answer( self::INVALID_DE, '{"countryCode":"DE","vatNumber":"123475223","valid":false}' );
		$this->customer( 'DE', '10115' );
		$field = Package::container()->get( CheckoutFields::class )->get_fields_for_location( 'contact' )[ VatField::FIELD_ID ];

		$this->assertTrue( $field['validate_callback']( self::INVALID_DE ), 'Unchecked numbers pass.' );

		do_action( 'woocommerce_checkout_update_order_review', 'billing_country=DE&billing_vatright_number=' . self::INVALID_DE );

		$this->assertInstanceOf( WP_Error::class, $field['validate_callback']( self::INVALID_DE ) );
		$this->assertSame( 1, $this->vies_calls );
	}

	public function test_classic_posted_number_is_stored_and_a_cleared_one_clears_it() {
		$this->customer( 'DE', '10115' );
		$_POST = array( VatField::CLASSIC_FIELD => ' de 114.103.379' );
		apply_filters( 'woocommerce_checkout_posted_data', array( 'billing_country' => 'DE' ) );
		$this->assertSame( self::VALID_DE, WC()->customer->get_meta( InputGatherer::NUMBER_META ) );

		$_POST = array( VatField::CLASSIC_FIELD => '' );
		apply_filters( 'woocommerce_checkout_posted_data', array( 'billing_country' => 'DE' ) );
		$this->assertSame( '', WC()->customer->get_meta( InputGatherer::NUMBER_META ) );

		$_POST = array();
	}

	public function test_classic_checkout_without_the_field_keeps_the_stored_number() {
		$this->customer( 'DE', '10115', self::VALID_DE );

		apply_filters( 'woocommerce_checkout_posted_data', array( 'billing_country' => 'DE' ) );

		$this->assertSame( self::VALID_DE, WC()->customer->get_meta( InputGatherer::NUMBER_META ) );
	}

	public function test_order_review_update_validates_once_and_the_cart_uses_the_verdict() {
		$this->add_to_cart( $this->product( 'goods' ) );
		$this->customer( 'DE', '10115' );
		$post_data = 'billing_country=DE&billing_vatright_number=' . rawurlencode( self::VALID_DE );

		do_action( 'woocommerce_checkout_update_order_review', $post_data );
		do_action( 'woocommerce_checkout_update_order_review', $post_data );

		$this->assertSame( 1, $this->vies_calls );
		$decision = $this->calculate();
		$this->assertTrue( $decision->is_vat_exempt() );
		$this->assertSame( 1, $this->vies_calls );
	}

	public function test_order_review_update_reads_the_posted_billing_country_for_a_bare_number() {
		$this->customer( 'MT', '', '' );
		$this->vies_answer( self::VALID_DE, '{"valid":true,"requestDate":"2026-10-17T10:00:00Z"}' );

		do_action( 'woocommerce_checkout_update_order_review', 'billing_country=DE&billing_vatright_number=114103379' );

		$this->assertSame( 1, $this->vies_calls, 'The bare number was parsed with the posted country, not the stale MT one.' );
	}

	public function test_self_certification_checkbox_is_stored_for_the_billing_country() {
		$this->customer( 'DE', '10115' );

		do_action( 'woocommerce_checkout_update_order_review', 'billing_country=DE&' . VatField::SELF_CERTIFY_MARKER . '=1&' . VatField::SELF_CERTIFY_FIELD . '=1' );
		$this->assertSame( 'DE', CartSteering::session()->get( InputGatherer::SELF_CERTIFIED_SESSION ) );

		do_action( 'woocommerce_checkout_update_order_review', 'billing_country=DE&' . VatField::SELF_CERTIFY_MARKER . '=1' );
		$this->assertNull( CartSteering::session()->get( InputGatherer::SELF_CERTIFIED_SESSION ) );
	}

	public function test_extension_update_sets_number_primes_registry_and_reports_status() {
		$this->add_to_cart( $this->product( 'digital' ) );
		$this->store_api( 'POST', 'cart/update-customer', array( 'billing_address' => $this->address( 'DE', '10115' ) ) );

		$response = $this->store_api(
			'POST',
			'cart/extensions',
			array(
				'namespace' => 'vatright',
				'data'      => array( 'vat_number' => ' de 114.103.379' ),
			)
		);

		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$extension = $this->extension( $response );
		$this->assertSame( 'verified', $extension['status'] );
		$this->assertSame( 'VAT number verified. Reverse charge applies.', $extension['message'] );
		$this->assertSame( 1, $this->vies_calls );
		$this->assertSame( self::VALID_DE, WC()->customer->get_meta( InputGatherer::NUMBER_META ) );
	}

	public function test_extension_update_uses_the_address_the_shopper_is_filling_in() {
		$this->add_to_cart( $this->product( 'digital' ) );

		$response = $this->store_api(
			'POST',
			'cart/extensions',
			array(
				'namespace' => 'vatright',
				'data'      => array(
					'vat_number' => self::VALID_DE,
					'country'    => 'DE',
					'postcode'   => '10115',
				),
			)
		);

		$this->assertSame( 'DE', WC()->customer->get_billing_country() );
		$this->assertSame( 'verified', $this->extension( $response )['status'] );
	}

	public function test_extension_update_clears_the_number() {
		$this->add_to_cart( $this->product( 'goods' ) );
		$this->customer( 'DE', '10115', self::VALID_DE );

		$response = $this->store_api(
			'POST',
			'cart/extensions',
			array(
				'namespace' => 'vatright',
				'data'      => array( 'vat_number' => '' ),
			)
		);

		$this->assertSame( 'none', $this->extension( $response )['status'] );
		$this->assertSame( '', WC()->customer->get_meta( InputGatherer::NUMBER_META ) );
	}

	public function test_extension_update_stores_self_certification() {
		$this->add_to_cart( $this->product( 'digital' ) );
		$this->customer( 'DE', '10115' );

		$this->store_api(
			'POST',
			'cart/extensions',
			array(
				'namespace' => 'vatright',
				'data'      => array( 'self_certify' => true ),
			)
		);
		$this->assertSame( 'DE', CartSteering::session()->get( InputGatherer::SELF_CERTIFIED_SESSION ) );

		$this->store_api(
			'POST',
			'cart/extensions',
			array(
				'namespace' => 'vatright',
				'data'      => array( 'self_certify' => false ),
			)
		);
		$this->assertNull( CartSteering::session()->get( InputGatherer::SELF_CERTIFIED_SESSION ) );
	}

	public function test_status_for_each_outcome() {
		$this->add_to_cart( $this->product( 'goods' ) );
		$this->vies_answer( self::INVALID_DE, '{"countryCode":"DE","vatNumber":"123475223","valid":false}' );
		$this->vies_answer( self::DOWN_DE, '{"actionSucceed":false,"errorWrappers":[{"error":"MS_UNAVAILABLE"}]}' );

		$cases = array(
			'none'         => array( '', 'none', '' ),
			'verified'     => array( self::VALID_DE, 'verified', 'VAT number verified. Reverse charge applies.' ),
			'invalid'      => array( self::INVALID_DE, 'invalid', 'This VAT number is not valid. Correct it, or leave the field empty to pay with VAT.' ),
			'pending'      => array( self::DOWN_DE, 'pending', 'Your VAT number could not be checked just now, so VAT is charged on this order.' ),
			'not checked'  => array( 'DE115235681', 'pending', 'Your VAT number has not been checked yet. VAT is charged until it is.' ),
			'bad format'   => array( 'DE123', 'invalid', 'This VAT number is not valid. Correct it, or leave the field empty to pay with VAT.' ),
			'wrong prefix' => array( 'FR40303265045', 'mismatch', 'This VAT number does not match your billing country, so VAT is charged.' ),
		);
		foreach ( $cases as $label => list( $number, $status, $message ) ) {
			$this->customer( 'DE', '10115', $number );
			if ( in_array( $label, array( 'verified', 'invalid', 'pending' ), true ) ) {
				do_action( 'woocommerce_checkout_update_order_review', 'billing_country=DE&billing_vatright_number=' . $number );
			}
			$result = StoreApiExtension::status( $this->calculate() );

			$this->assertSame( $status, $result['status'], $label );
			$this->assertSame( $message, $result['message'], $label );
		}
	}

	public function test_field_error_and_status_word_an_invalid_number_the_same_way() {
		$this->vies_answer( self::INVALID_DE, '{"countryCode":"DE","vatNumber":"123475223","valid":false}' );
		$this->add_to_cart( $this->product( 'goods' ) );
		$this->customer( 'DE', '10115', self::INVALID_DE );
		do_action( 'woocommerce_checkout_update_order_review', 'billing_country=DE&billing_vatright_number=' . self::INVALID_DE );
		$field = Package::container()->get( CheckoutFields::class )->get_fields_for_location( 'contact' )[ VatField::FIELD_ID ];

		$error = call_user_func( $field['validate_callback'], self::INVALID_DE );

		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 'vatright_invalid', $error->get_error_code() );
		$this->assertSame( StoreApiExtension::status( $this->calculate() )['message'], $error->get_error_message() );
	}

	public function test_domestic_business_is_told_vat_is_charged() {
		$this->vies_answer( 'MT12345634', '{"valid":true,"requestDate":"2026-10-17T10:00:00Z"}' );
		$this->add_to_cart( $this->product( 'goods' ) );
		$this->customer( 'MT', '', 'MT12345634' );
		do_action( 'woocommerce_checkout_update_order_review', 'billing_country=MT&billing_vatright_number=MT12345634' );

		$result = StoreApiExtension::status( $this->calculate() );

		$this->assertSame( 'domestic', $result['status'] );
	}

	public function test_self_certification_is_offered_only_on_conflicting_evidence() {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
		add_filter(
			'woocommerce_geolocate_ip',
			static function () {
				return 'FR';
			}
		);
		$this->add_to_cart( $this->product( 'digital' ) );
		$this->customer( 'DE', '10115' );

		$conflict = StoreApiExtension::status( $this->calculate() );
		$this->assertTrue( $conflict['needs_self_certification'] );
		$this->assertFalse( $conflict['self_certified'] );
		$this->assertSame( 'I confirm I am located in Germany.', $conflict['self_certification_label'] );

		CartSteering::session()->set( InputGatherer::SELF_CERTIFIED_SESSION, 'DE' );
		$certified = StoreApiExtension::status( $this->calculate() );
		$this->assertFalse( $certified['needs_self_certification'] );
		$this->assertTrue( $certified['self_certified'] );

		CartSteering::session()->set( InputGatherer::SELF_CERTIFIED_SESSION, null );
		$this->settings( array( 'self_certification' => false ) );
		$this->assertFalse( StoreApiExtension::status( $this->calculate() )['needs_self_certification'] );
		remove_all_filters( 'woocommerce_geolocate_ip' );
		unset( $_SERVER['REMOTE_ADDR'] );
	}

	public function test_status_without_a_decision_is_empty() {
		$this->add_to_cart( $this->product( 'goods' ) );
		$this->assertSame( 'none', StoreApiExtension::status( null )['status'] );
		$this->assertInstanceOf( Decision::class, $this->calculate() );
	}

	/**
	 * WooCommerce's own copy of the block field comes from CheckoutFieldsAdmin,
	 * which only hooks in on admin screens and only for orders holding a value.
	 */
	public function test_admin_order_screen_gets_one_input_replacing_woocommerces_copy() {
		$woo_admin = \Automattic\WooCommerce\Blocks\Package::container()->get( \Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFieldsAdmin::class );
		add_filter( 'woocommerce_admin_billing_fields', array( $woo_admin, 'admin_contact_fields' ), 10, 3 );
		$order = wc_create_order();
		$order->update_meta_data( InputGatherer::NUMBER_META, self::VALID_DE );
		$order->set_created_via( 'store-api' );
		$order->update_meta_data( '_wc_other/' . VatField::FIELD_ID, self::VALID_DE );
		$order->save();
		$this->assertNotSame( array(), $woo_admin->admin_contact_fields( array(), $order, 'view' ), 'Control: WooCommerce lists its copy of the field' );

		foreach ( array( 'view', 'edit' ) as $context ) {
			$fields = apply_filters( 'woocommerce_admin_billing_fields', array( 'first_name' => array( 'label' => 'First name' ) ), $order, $context );

			$this->assertArrayHasKey( 'first_name', $fields );
			$this->assertSame( self::VALID_DE, $fields['vatright_number']['value'] );
			$this->assertSame( array( 'vatright_number' ), array_keys( wp_list_filter( $fields, array( 'label' => 'VAT number' ) ) ), $context );
		}
		remove_filter( 'woocommerce_admin_billing_fields', array( $woo_admin, 'admin_contact_fields' ), 10 );
	}

	public function test_supply_type_select_saves_and_clears() {
		$product = $this->product( 'goods' );
		$field   = new ProductSupplyField();

		$_POST = array( \Vatright\Checkout\SupplyType::META => 'service' );
		$field->save( $product );
		$this->assertSame( 'service', $product->get_meta( \Vatright\Checkout\SupplyType::META ) );

		$_POST = array( \Vatright\Checkout\SupplyType::META => '' );
		$field->save( $product );
		$this->assertSame( '', $product->get_meta( \Vatright\Checkout\SupplyType::META ) );

		$_POST = array( \Vatright\Checkout\SupplyType::META => 'nonsense' );
		$field->save( $product );
		$this->assertSame( '', $product->get_meta( \Vatright\Checkout\SupplyType::META ) );
		$_POST = array();
	}
}
