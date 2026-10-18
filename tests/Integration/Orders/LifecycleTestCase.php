<?php

namespace Vatline\Tests\Integration\Orders;

use Vatline\Domain\Decision;
use Vatline\Domain\DecisionInput;
use Vatline\Domain\Evidence;
use Vatline\Domain\Line;
use Vatline\Domain\Rules;
use Vatline\Domain\ValidationResult;
use Vatline\Domain\VatNumber;
use Vatline\Ledger\ThresholdTracker;
use Vatline\Orders\DecisionStore;
use Vatline\Orders\Revalidation;
use Vatline\Registry\Brreg;
use Vatline\Registry\Hmrc;
use Vatline\Registry\Router;
use Vatline\Registry\SwissUid;
use Vatline\Registry\ValidationCache;
use Vatline\Registry\Validator;
use Vatline\Registry\Vies;
use Vatline\Registry\WpHttpTransport;
use Vatline\Settings;
use Vatline\Tests\Support\HttpGuard;
use RuntimeException;
use WC_Order;

require_once __DIR__ . '/OrderTestCase.php';

abstract class LifecycleTestCase extends OrderTestCase {

	const NUMBER = 'DE114103379';

	/** @var int */
	protected $vies_calls = 0;

	/** @var array<string,array<string,mixed>> vatNumber digits => WP HTTP response */
	protected $vies = array();

	/** @var array<int,array<string,mixed>> */
	protected $mails = array();

	public function set_up() {
		parent::set_up();
		HttpGuard::uninstall();
		$this->vies_calls = 0;
		$this->vies       = array();
		$this->mails      = array();
		add_filter( 'pre_http_request', array( $this, 'route_vies' ), PHP_INT_MAX, 3 );
		add_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 10, 2 );
		$this->install_schema();
		ThresholdTracker::flush( (int) wp_date( 'Y' ), (int) wp_date( 'Y' ) - 1 );
		delete_transient( ValidationCache::PREFIX . md5( self::NUMBER . '|' ) );
	}

	protected function revalidation(): Revalidation {
		$transport = new WpHttpTransport();
		$settings  = Settings::get();
		return new Revalidation(
			new Validator(
				new Router( new Vies( $transport ), new Hmrc( $transport, '', '' ), new SwissUid( $transport ), new Brreg( $transport ) ),
				new ValidationCache( $settings ),
				$settings
			)
		);
	}

	protected function registry_valid(): void {
		$this->vies_answer( self::NUMBER, '{"countryCode":"DE","vatNumber":"114103379","valid":true,"name":"---","address":"---"}' );
	}

	protected function registry_invalid(): void {
		$this->vies_answer( self::NUMBER, '{"countryCode":"DE","vatNumber":"114103379","valid":false}' );
	}

	protected function registry_down(): void {
		$this->vies_answer( self::NUMBER, '{"actionSucceed":false,"errorWrappers":[{"error":"MS_UNAVAILABLE"}]}' );
	}

	protected function vies_answer( string $number, string $body ): void {
		$this->vies[ substr( $number, 2 ) ] = array(
			'headers'  => array(),
			'body'     => $body,
			'response' => array(
				'code'    => 200,
				'message' => '',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * @param false|array<string,mixed> $preempt
	 * @param array<string,mixed>       $args
	 * @return false|array<string,mixed>
	 */
	public function route_vies( $preempt, $args, string $url ) {
		if ( false !== strpos( $url, 'check-vat-number' ) ) {
			++$this->vies_calls;
			foreach ( $this->vies as $digits => $response ) {
				if ( false !== strpos( (string) ( $args['body'] ?? '' ), '"vatNumber":"' . $digits . '"' ) ) {
					return $response;
				}
			}
		}
		throw new RuntimeException( "Unstubbed outbound request: $url" ); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/**
	 * @param mixed               $short_circuit
	 * @param array<string,mixed> $atts
	 */
	public function capture_mail( $short_circuit, $atts ): bool {
		$this->mails[] = $atts;
		return true;
	}

	/**
	 * A German business buying a digital service whose number the registry
	 * could not check, decided under the given outage policy.
	 */
	protected function pending_decision( string $policy ): Decision {
		$vat = VatNumber::parse( self::NUMBER, 'DE' );
		return ( new Rules() )->decide(
			new DecisionInput(
				'MT',
				false,
				false,
				array(
					'current_eur'  => '0.00',
					'previous_eur' => '0.00',
					'exceeded'     => false,
					'forced'       => false,
				),
				$policy,
				false,
				false,
				'DE',
				'10115',
				'DE',
				'10115',
				false,
				$vat,
				self::NUMBER,
				new ValidationResult( ValidationResult::UNAVAILABLE, 'vies', '2026-10-17T12:00:00Z', null, null, null, false, 'MS_UNAVAILABLE' ),
				new Evidence( 'DE', null, null, null ),
				array( new Line( 'line-digital', 'digital', false ) ),
				'checkout',
				'2026-10-17T12:00:00Z'
			)
		);
	}

	protected function pending_order( string $policy ): WC_Order {
		$order = $this->order( 'DE', 'DE', '10115', array( 'digital' ) );
		DecisionStore::apply( $order, $this->pending_decision( $policy ), 'checkout' );
		$order->calculate_totals();
		$order->save();
		return $order;
	}

	protected function fresh( WC_Order $order ): WC_Order {
		$fresh = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $fresh );
		return $fresh;
	}

	protected function stored( WC_Order $order ): Decision {
		$decision = DecisionStore::get( $this->fresh( $order ) );
		$this->assertNotNull( $decision );
		return $decision;
	}

	protected function admin(): int {
		$id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $id );
		return $id;
	}

	protected function notes( WC_Order $order ): string {
		return implode( "\n", array_map( 'wp_strip_all_tags', wp_list_pluck( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), 'content' ) ) );
	}
}
