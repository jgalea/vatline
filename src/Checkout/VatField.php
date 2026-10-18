<?php

namespace Vatline\Checkout;

use Vatline\Domain\FormatCheck;
use Vatline\Domain\ValidationResult;
use Vatline\Domain\VatNumber;
use Vatline\Plugin;
use Vatline\Registry\Validator;
use WC_Customer;
use WC_Data;
use WP_Error;

/**
 * The VAT number input on every screen that takes one. WooCommerce renders
 * the block field itself on the block checkout, My Account address forms and
 * the admin order screen; only the classic checkout needs a field of ours.
 * It sits in the contact location, so there is one value per customer and
 * order instead of a billing and a shipping copy to keep in step.
 * Whichever input saved it, the number ends up in _vatline_number on the
 * customer and the order.
 */
class VatField {

	const FIELD_ID = 'vatline/vat-number';

	const CLASSIC_FIELD = 'billing_vatline_number';

	const SELF_CERTIFY_FIELD = 'vatline_self_certify';

	const SELF_CERTIFY_MARKER = 'vatline_self_certify_field';

	/**
	 * Countries where a VAT number means something to us: the EU, Northern
	 * Ireland (GB), Switzerland and Norway.
	 */
	const COUNTRIES = array(
		'AT',
		'BE',
		'BG',
		'HR',
		'CY',
		'CZ',
		'DK',
		'EE',
		'FI',
		'FR',
		'DE',
		'GR',
		'HU',
		'IE',
		'IT',
		'LV',
		'LT',
		'LU',
		'MT',
		'NL',
		'PL',
		'PT',
		'RO',
		'SK',
		'SI',
		'ES',
		'SE',
		'GB',
		'CH',
		'NO',
	);

	/** @var Validator */
	private $validator;

	public function __construct( Validator $validator ) {
		$this->validator = $validator;
	}

	public function register(): void {
		add_action( 'woocommerce_init', array( $this, 'register_block_field' ) );
		add_action( 'woocommerce_set_additional_field_value', array( $this, 'mirror_block_value' ), 10, 4 );

		add_filter( 'woocommerce_billing_fields', array( $this, 'add_classic_field' ) );
		add_filter( 'woocommerce_checkout_get_value', array( $this, 'classic_default' ), 10, 2 );
		add_action( 'woocommerce_checkout_update_order_review', array( $this, 'on_update_order_review' ) );
		add_filter( 'woocommerce_checkout_posted_data', array( $this, 'on_posted_data' ) );
		add_action( 'woocommerce_checkout_create_order', array( $this, 'copy_to_order' ), 5 );
		add_action( 'woocommerce_review_order_before_order_total', array( $this, 'render_classic_notice' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_classic_script' ) );

		add_filter( 'woocommerce_admin_billing_fields', array( $this, 'add_admin_field' ), 20, 2 );
		add_filter( 'woocommerce_customer_meta_fields', array( $this, 'add_profile_field' ) );
	}

	public function register_block_field(): void {
		if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
			return;
		}
		woocommerce_register_additional_checkout_field(
			array(
				'id'                         => self::FIELD_ID,
				'label'                      => __( 'VAT number', 'vatline' ),
				'location'                   => 'contact',
				'type'                       => 'text',
				'required'                   => false,
				// InvoiceText adds the number to the order totals, which every order shows.
				'show_in_order_confirmation' => false,
				'hidden'                     => array(
					'type'       => 'object',
					'required'   => array( 'customer' ),
					'properties' => array(
						'customer' => array(
							'required'   => array( 'billing_address' ),
							'properties' => array(
								'billing_address' => array(
									'required'   => array( 'country' ),
									'properties' => array(
										'country' => array(
											'minLength' => 1,
											'not'       => array( 'enum' => self::COUNTRIES ),
										),
									),
								),
							),
						),
					),
				),
				'sanitize_callback'          => array( self::class, 'normalise' ),
				'validate_callback'          => array( $this, 'validate_block_value' ),
			)
		);
	}

	/**
	 * The canonical form when the text parses as a VAT number, otherwise the
	 * trimmed upper-case text (a bare number that needs the billing country
	 * to be understood).
	 *
	 * @param mixed $value
	 */
	public static function normalise( $value ): string {
		$raw    = trim( (string) $value );
		$number = VatNumber::parse( $raw, '' );
		return null === $number ? strtoupper( $raw ) : $number->canonical();
	}

	/**
	 * Format and cached-verdict checks only; the registry is asked later, in
	 * the cart update or at checkout.
	 *
	 * @param mixed $value
	 * @return WP_Error|true
	 */
	public function validate_block_value( $value ) {
		$raw = trim( (string) $value );
		if ( '' === $raw ) {
			return true;
		}
		$number = VatNumber::parse( $raw, self::billing_country() );
		if ( null === $number || ! FormatCheck::is_plausible( $number ) ) {
			return new WP_Error( 'vatline_format', __( 'This does not look like a VAT number. Check it, or leave the field empty to pay with VAT.', 'vatline' ) );
		}
		if ( ValidationResult::INVALID === $this->validator->validate( $number, false )->status() ) {
			return new WP_Error( 'vatline_invalid', CheckoutDecider::message( 'invalid_vat_number' ) );
		}
		return true;
	}

	/**
	 * A cleared field clears the number.
	 *
	 * @param string  $key
	 * @param mixed   $value
	 * @param string  $group
	 * @param WC_Data $wc_object
	 */
	public function mirror_block_value( $key, $value, $group, $wc_object ): void {
		if ( self::FIELD_ID !== $key ) {
			return;
		}
		$wc_object->update_meta_data( InputGatherer::NUMBER_META, self::normalise( $value ) );
	}

	/**
	 * @param array<string,mixed> $fields
	 * @return array<string,mixed>
	 */
	public function add_classic_field( $fields ) {
		if ( ! self::is_classic_checkout() ) {
			return $fields;
		}
		$fields[ self::CLASSIC_FIELD ] = array(
			'type'         => 'text',
			'label'        => __( 'VAT number', 'vatline' ),
			'required'     => false,
			'class'        => array( 'form-row-wide' ),
			'priority'     => 125,
			'autocomplete' => 'off',
		);
		return $fields;
	}

	/**
	 * @param mixed  $value
	 * @param string $input
	 * @return mixed
	 */
	public function classic_default( $value, $input ) {
		if ( self::CLASSIC_FIELD !== $input || ! WC()->customer instanceof WC_Customer ) { // @phpstan-ignore instanceof.alwaysTrue
			return $value;
		}
		return (string) WC()->customer->get_meta( InputGatherer::NUMBER_META );
	}

	/**
	 * Runs before WooCommerce applies the posted address to the customer, so
	 * the billing country is read from the posted data.
	 *
	 * @param string $post_data
	 */
	public function on_update_order_review( $post_data ): void {
		parse_str( (string) $post_data, $posted );
		$this->apply_posted( (array) wc_clean( wp_unslash( $posted ) ) );
	}

	/**
	 * @param array<string,mixed> $data
	 * @return array<string,mixed>
	 */
	public function on_posted_data( $data ) {
		// WooCommerce fills a registered field that was not posted with an empty string; only a field that was posted may clear the number.
		$this->apply_posted( (array) wc_clean( wp_unslash( $_POST ) ) + array( 'billing_country' => (string) ( $data['billing_country'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verified the checkout nonce.
		return $data;
	}

	/**
	 * @param \WC_Order $order
	 */
	public function copy_to_order( $order ): void {
		$number = WC()->customer instanceof WC_Customer ? (string) WC()->customer->get_meta( InputGatherer::NUMBER_META ) : ''; // @phpstan-ignore instanceof.alwaysTrue
		if ( '' !== $number ) {
			$order->update_meta_data( InputGatherer::NUMBER_META, $number );
		}
	}

	public function render_classic_notice(): void {
		$decision = Plugin::cart_steering() ? Plugin::cart_steering()->current() : null;
		$status   = StoreApiExtension::status( $decision );
		if ( '' === $status['message'] && ! $status['needs_self_certification'] && ! $status['self_certified'] ) {
			return;
		}
		echo '<tr class="vatline-notice"><td colspan="2">';
		if ( '' !== $status['message'] ) {
			echo '<p class="vatline-notice__message vatline-notice__message--' . esc_attr( $status['status'] ) . '" role="status">' . esc_html( $status['message'] ) . '</p>';
		}
		if ( $status['needs_self_certification'] || $status['self_certified'] ) {
			echo '<input type="hidden" name="' . esc_attr( self::SELF_CERTIFY_MARKER ) . '" value="1" />';
			echo '<label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox">';
			echo '<input type="checkbox" class="woocommerce-form__input woocommerce-form__input-checkbox input-checkbox" name="' . esc_attr( self::SELF_CERTIFY_FIELD ) . '" value="1"' . checked( $status['self_certified'], true, false ) . ' /> ';
			echo '<span>' . esc_html( $status['self_certification_label'] ) . '</span></label>';
		}
		echo '</td></tr>';
	}

	public function enqueue_classic_script(): void {
		if ( ! self::is_classic_checkout() ) {
			return;
		}
		$script = self::asset( 'assets/classic-checkout.js' );
		wp_enqueue_script( 'vatline-classic-checkout', $script['url'], array( 'jquery', 'wc-checkout' ), $script['version'], true );
		$style = self::asset( 'assets/checkout-block/style.css' );
		wp_enqueue_style( 'vatline-checkout', $style['url'], array(), $style['version'] );
	}

	/**
	 * WooCommerce lists its own copy of the block field here, but only for
	 * orders placed through the Store API that have a value, so an admin could
	 * not add a number to any other order. One input of ours replaces it; the
	 * saved value is read from _billing_vatline_number by AdminRecalc.
	 *
	 * @param array<string,mixed> $fields
	 * @param mixed               $order
	 * @return array<string,mixed>
	 */
	public function add_admin_field( $fields, $order = null ) {
		unset( $fields[ self::FIELD_ID ], $fields[ '_wc_other/' . self::FIELD_ID ] );
		$fields['vatline_number'] = array(
			'label'         => __( 'VAT number', 'vatline' ),
			'wrapper_class' => 'form-field-wide',
			'show'          => true,
			'value'         => $order instanceof \WC_Order ? (string) $order->get_meta( InputGatherer::NUMBER_META ) : '',
		);
		return $fields;
	}

	/**
	 * @param array<string,mixed> $fields
	 * @return array<string,mixed>
	 */
	public function add_profile_field( $fields ) {
		if ( isset( $fields['billing']['fields'] ) ) {
			$fields['billing']['fields'][ InputGatherer::NUMBER_META ] = array(
				'label'       => __( 'VAT number', 'vatline' ),
				'description' => '',
			);
		}
		return $fields;
	}

	/**
	 * Registry lookup for a number entered by the visitor. The cart update
	 * and the classic order review call this, so the next totals calculation
	 * finds the verdict in the cache.
	 */
	public function set_number( WC_Customer $customer, string $raw, string $billing_country ): void {
		$customer->update_meta_data( InputGatherer::NUMBER_META, self::normalise( $raw ) );
		$number = VatNumber::parse( $raw, $billing_country );
		if ( null !== $number ) {
			$this->validator->validate_for_visitor( $number, 'update' );
		}
	}

	/**
	 * @param array<string,mixed> $posted
	 */
	private function apply_posted( array $posted ): void {
		$customer = WC()->customer;
		if ( ! $customer instanceof WC_Customer ) { // @phpstan-ignore instanceof.alwaysTrue
			return;
		}
		$billing = strtoupper( (string) ( $posted['billing_country'] ?? $posted['country'] ?? $customer->get_billing_country() ) );

		if ( array_key_exists( self::CLASSIC_FIELD, $posted ) ) {
			$this->set_number( $customer, (string) $posted[ self::CLASSIC_FIELD ], $billing );
			if ( $customer->get_id() ) {
				$customer->update_meta_data( '_wc_other/' . self::FIELD_ID, self::normalise( $posted[ self::CLASSIC_FIELD ] ) );
			}
		}
		if ( array_key_exists( self::SELF_CERTIFY_MARKER, $posted ) && null !== CartSteering::session() ) {
			CartSteering::session()->set( InputGatherer::SELF_CERTIFIED_SESSION, isset( $posted[ self::SELF_CERTIFY_FIELD ] ) ? $billing : null );
		}
	}

	/**
	 * @return array{url: string, version: string}
	 */
	public static function asset( string $path ): array {
		$main = dirname( __DIR__, 2 ) . '/vatline.php';
		return array(
			'url'     => plugins_url( $path, $main ),
			'version' => (string) filemtime( dirname( __DIR__, 2 ) . '/' . $path ),
		);
	}

	private static function billing_country(): string {
		return WC()->customer instanceof WC_Customer ? strtoupper( WC()->customer->get_billing_country() ) : ''; // @phpstan-ignore instanceof.alwaysTrue
	}

	/**
	 * The classic input belongs on a classic checkout page only: not on a
	 * block checkout page, in REST requests, or on My Account, where
	 * WooCommerce adds the block field to the address form itself.
	 */
	private static function is_classic_checkout(): bool {
		if ( defined( 'REST_REQUEST' ) || ( ! did_action( 'wp' ) && ! defined( 'WOOCOMMERCE_CHECKOUT' ) ) || ! is_checkout() ) {
			return false;
		}
		$page = get_queried_object_id();
		return ! ( $page > 0 && has_block( 'woocommerce/checkout', $page ) );
	}
}
