<?php

namespace Vatright\Integrations;

use Vatright\Checkout\InputGatherer;
use Vatright\Checkout\TaxLocation;
use Vatright\Domain\Decision;
use Vatright\Domain\DecisionInput;
use Vatright\Domain\Rules;
use Vatright\Domain\Territory;
use Vatright\Domain\ValidationResult;
use Vatright\Orders\DecisionStore;
use Vatright\Settings;
use Throwable;
use WC_Order;
use WC_Tax;

/**
 * Inert unless WooCommerce Subscriptions is active. A renewal order is a new
 * sale, so it gets its own decision: the subscription's stored registry answer
 * is reused while it is fresh, otherwise the number is checked again.
 */
class Subscriptions {

	/** @var InputGatherer */
	private $gatherer;

	/** @var Rules */
	private $rules;

	public function __construct( InputGatherer $gatherer, Rules $rules ) {
		$this->gatherer = $gatherer;
		$this->rules    = $rules;
	}

	public function register(): void {
		if ( ! class_exists( 'WC_Subscriptions' ) ) {
			return;
		}
		add_filter( 'wcs_renewal_order_created', array( $this, 'decide_renewal' ), 10, 2 );
		add_action( 'woocommerce_checkout_subscription_created', array( $this, 'record_on_subscription' ), 10, 2 );
		add_action( 'woocommerce_subscriptions_switch_completed', array( $this, 'record_switch' ) );
	}

	/**
	 * A renewal that cannot be decided is charged VAT: the subscription's
	 * decision, copied verbatim by Subscriptions, must not carry an exemption
	 * into a payment nobody checked.
	 *
	 * @param mixed $renewal
	 * @param mixed $subscription
	 * @return mixed
	 */
	public function decide_renewal( $renewal, $subscription ) {
		if ( ! $renewal instanceof WC_Order || ! $subscription instanceof WC_Order ) {
			return $renewal;
		}
		try {
			$this->renew( $renewal, $subscription );
		} catch ( Throwable $e ) {
			wc_get_logger()->error( 'Renewal VAT decision failed, charging VAT: ' . $e->getMessage(), array( 'source' => 'vatright' ) );
			$renewal->delete_meta_data( DecisionStore::META );
			$renewal->update_meta_data( 'is_vat_exempt', 'no' );
			add_filter( 'woocommerce_order_get_tax_location', array( self::class, 'origin_when_rate_missing' ), 20 );
			$renewal->calculate_totals( true );
			remove_filter( 'woocommerce_order_get_tax_location', array( self::class, 'origin_when_rate_missing' ), 20 );
			$renewal->save();
		}
		return $renewal;
	}

	/**
	 * The undecided renewal's counterpart of TaxLocation's rate_missing
	 * fallback: an EU destination with no rate row is taxed at the store's
	 * rate instead of not at all.
	 *
	 * @param array<string,string> $args
	 * @return array<string,string>
	 */
	public static function origin_when_rate_missing( $args ) {
		$country  = strtoupper( (string) ( $args['country'] ?? '' ) );
		$postcode = (string) ( $args['postcode'] ?? '' );
		if ( ! Territory::in_vat_area( $country, $postcode ) || array() !== WC_Tax::find_rates( array_merge( $args, array( 'tax_class' => '' ) ) ) ) {
			return $args;
		}
		return array_merge( $args, TaxLocation::base() );
	}

	/**
	 * @param mixed $subscription
	 * @param mixed $order
	 */
	public function record_on_subscription( $subscription, $order ): void {
		if ( $subscription instanceof WC_Order && $order instanceof WC_Order ) {
			$this->copy_decision( $order, $subscription );
		}
	}

	/**
	 * @param mixed $order The switch order.
	 */
	public function record_switch( $order ): void {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		// @phpstan-ignore function.notFound (Subscriptions is not in the stubs.)
		foreach ( wcs_get_subscriptions_for_switch_order( $order ) as $subscription ) {
			$this->copy_decision( $order, $subscription );
		}
	}

	private function copy_decision( WC_Order $order, WC_Order $subscription ): void {
		$decision = DecisionStore::get( $order );
		if ( null === $decision ) {
			return;
		}
		DecisionStore::apply( $subscription, $decision, $decision->trigger() );
		$subscription->save();
	}

	private function renew( WC_Order $renewal, WC_Order $subscription ): void {
		$previous = DecisionStore::get( $subscription );
		$this->inherit( $renewal, $subscription );

		$stored = $this->fresh_validation( $previous, (string) $renewal->get_meta( InputGatherer::NUMBER_META ) );
		$input  = $this->gatherer->from_order( $renewal, 'renewal', null === $stored );
		if ( null !== $stored ) {
			$input = self::with_validation( $input, $stored );
		}
		$decision = $this->rules->decide( $input );

		$taxes_differ = null === $previous
			? $decision->is_vat_exempt() !== ( 'yes' === $renewal->get_meta( 'is_vat_exempt' ) )
			: $decision->differs_in_tax_from( $previous );

		DecisionStore::apply( $renewal, $decision, 'renewal' );
		if ( $taxes_differ ) {
			$renewal->calculate_totals( true );
		}
		$renewal->save();

		if ( null === $stored || $taxes_differ ) {
			DecisionStore::apply( $subscription, $decision, 'renewal' );
			$subscription->save();
		}
	}

	/**
	 * Subscriptions copies the parent order's meta to the subscription and the
	 * subscription's to each renewal, but which keys it skips is not relied on:
	 * the subscription's own number and decision are the starting point, so the
	 * renewal's log shows what it replaced and checkout evidence carries over.
	 */
	private function inherit( WC_Order $renewal, WC_Order $subscription ): void {
		$number = (string) $subscription->get_meta( InputGatherer::NUMBER_META );
		if ( '' !== $number ) {
			$renewal->update_meta_data( InputGatherer::NUMBER_META, $number );
		}
		$record = (string) $subscription->get_meta( DecisionStore::META );
		if ( '' !== $record ) {
			$renewal->update_meta_data( DecisionStore::META, $record );
		}
	}

	private function fresh_validation( ?Decision $previous, string $number ): ?ValidationResult {
		if ( null === $previous || '' === $number ) {
			return null;
		}
		$record = $previous->to_array();
		$stored = $record['validation'];
		if ( null === $stored || ( $record['vat_number']['canonical'] ?? null ) !== $number ) {
			return null;
		}
		$result = ValidationResult::from_array( $stored );
		$age    = time() - (int) strtotime( $result->checked_at() );
		$ttl    = Settings::get()->cache_ttl_valid_days() * DAY_IN_SECONDS;
		if ( ValidationResult::VALID !== $result->status() || $age < 0 || $age >= $ttl ) {
			return null;
		}
		return $result->with_from_cache( true );
	}

	private static function with_validation( DecisionInput $in, ValidationResult $validation ): DecisionInput {
		return new DecisionInput(
			$in->store_country(),
			$in->oss_registered(),
			$in->threshold_exceeded(),
			$in->threshold(),
			$in->outage_policy(),
			$in->allow_domestic_exemption(),
			$in->fixed_prices(),
			$in->billing_country(),
			$in->billing_postcode(),
			$in->shipping_country(),
			$in->shipping_postcode(),
			$in->local_pickup(),
			$in->vat_number(),
			$in->vat_raw(),
			$validation,
			$in->evidence(),
			$in->lines(),
			$in->trigger(),
			$in->now_utc()
		);
	}
}
