<?php

namespace Levy\EuVat\Tests\Integration\Privacy;

use Levy\EuVat\Ledger\Schema;
use Levy\EuVat\Orders\DecisionStore;
use Levy\EuVat\Tests\Integration\Orders\OrderTestCase;
use WC_Order;

require_once dirname( __DIR__ ) . '/Orders/OrderTestCase.php';

abstract class PrivacyTestCase extends OrderTestCase {

	const EMAIL = 'buyer@example.com';

	public function set_up() {
		parent::set_up();
		$this->install_schema();
	}

	protected function year(): int {
		return (int) wp_date( 'Y' );
	}

	/**
	 * A B2B order with a validated VAT number, registry name and address, an
	 * IP country and one ledger row, placed in the given year.
	 */
	protected function vat_order( int $year, string $email = self::EMAIL ): WC_Order {
		$order = $this->order( 'DE', 'DE', '10115', array( 'digital' ) );
		$order->set_billing_email( $email );
		$order->set_date_created( strtotime( $year . '-06-01 10:00:00 UTC' ) );
		DecisionStore::apply( $order, $this->decide( 'DE', 'DE', '10115', array( 'digital' ), false, 'DE123456789', 'valid', 'DE', 'Acme GmbH' ), 'checkout' );
		$order->save();

		global $wpdb;
		$wpdb->insert(
			Schema::ledger_table(),
			array(
				'order_id'          => $order->get_id(),
				'entry_type'        => 'sale',
				'decision_id'       => '3f1c1f0e-0000-4000-8000-000000000001',
				'scheme'            => 'reverse_charge_services',
				'rule'              => 'RC_SERVICES_ART196',
				'supply_type'       => 'digital',
				'customer_country'  => 'DE',
				'place_of_supply'   => 'DE',
				'customer_vat'      => 'DE123456789',
				'rate_percent'      => '0',
				'net'               => '100',
				'vat'               => '0',
				'currency'          => 'EUR',
				'supply_at'         => $year . '-06-01 10:00:00',
				'period'            => $year . '-Q2',
				'relates_to_period' => $year . '-Q2',
				'created_at'        => $year . '-06-01 10:00:00',
			)
		);
		return $order;
	}

	/**
	 * @return array<string,mixed>
	 */
	protected function record( int $order_id ): array {
		$order = wc_get_order( $order_id );
		return json_decode( (string) $order->get_meta( DecisionStore::META ), true );
	}

	protected function ledger_vat( int $order_id ): ?string {
		global $wpdb;
		$table = Schema::ledger_table();
		return $wpdb->get_var( $wpdb->prepare( "SELECT customer_vat FROM {$table} WHERE order_id = %d", $order_id ) ); // phpcs:ignore WordPress.DB
	}
}
