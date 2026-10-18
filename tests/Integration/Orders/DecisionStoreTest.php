<?php

namespace Levy\EuVat\Tests\Integration\Orders;

use Levy\EuVat\Domain\Rule;
use Levy\EuVat\Orders\DecisionStore;

require_once __DIR__ . '/OrderTestCase.php';

class DecisionStoreTest extends OrderTestCase {

	public function test_apply_writes_decision_log_and_exempt_flag() {
		$order    = $this->order( 'DE', 'DE', '10115', array( 'digital' ) );
		$decision = $this->decide( 'DE', 'DE', '10115', array( 'digital' ), false, 'DE114103379', 'valid' );

		DecisionStore::apply( $order, $decision, 'checkout', 7 );
		$order->save();
		$order = wc_get_order( $order->get_id() );

		$this->assertSame( $decision->to_array(), DecisionStore::get( $order )->to_array() );
		$this->assertSame( 'yes', $order->get_meta( 'is_vat_exempt' ) );
		$this->assertSame( 'DE114103379', $order->get_meta( '_levy_eu_vat_number' ) );
		$log = DecisionStore::log( $order );
		$this->assertCount( 1, $log );
		$this->assertSame(
			array(
				'trigger'       => 'checkout',
				'decision_id'   => $decision->id(),
				'previous_id'   => null,
				'scheme'        => 'reverse_charge_services',
				'rule'          => Rule::RC_SERVICES_ART196,
				'flags'         => array(),
				'actor_user_id' => 7,
			),
			array_diff_key( $log[0], array( 'at' => true ) )
		);
	}

	public function test_redecision_appends_log_and_clears_exemption() {
		$order = $this->order( 'DE', 'DE', '10115', array( 'digital' ) );
		$first = $this->decide( 'DE', 'DE', '10115', array( 'digital' ), false, 'DE114103379', 'valid' );
		DecisionStore::apply( $order, $first, 'checkout' );
		DecisionStore::apply( $order, $first, 'checkout' );
		$order->save();

		$second = $this->decide( 'DE', 'DE', '10115', array( 'digital' ), false, 'DE114103379', 'invalid' );
		DecisionStore::apply( $order, $second, 'admin', 3 );
		$this->assertSame( $second->id(), DecisionStore::log( $order )[1]['decision_id'], 'An unsaved row sorts after the saved ones' );
		$order->save();
		$order = wc_get_order( $order->get_id() );

		$log = DecisionStore::log( $order );
		$this->assertCount( 2, $log, 'Re-applying the stored decision must not add a log row' );
		$this->assertSame( $first->id(), $log[1]['previous_id'] );
		$this->assertSame( 'no', $order->get_meta( 'is_vat_exempt' ) );
		$this->assertSame( $second->id(), DecisionStore::get( $order )->id() );
	}

	/**
	 * Registry text with quotes, a backslash, a newline and non-ASCII must
	 * read back byte for byte from both order storages.
	 */
	public function test_hpos_and_cpt_store_identical_meta() {
		$decision = $this->decide( 'DE', 'DE', '10115', array( 'digital' ), false, 'DE114103379', 'valid', '', 'Müller & "Söhne" \\ GmbH' );
		$stored   = array();
		$stores   = array();

		foreach ( array( 'yes', 'no' ) as $hpos ) {
			$filter = static function () use ( $hpos ) {
				return $hpos;
			};
			add_filter( 'pre_option_woocommerce_custom_orders_table_enabled', $filter, 20 );

			$order = $this->order( 'DE', 'DE', '10115', array( 'digital' ) );
			DecisionStore::apply( $order, $decision, 'checkout' );
			$order->save();
			$order = wc_get_order( $order->get_id() );

			$stores[ $hpos ] = $order->get_data_store()->get_current_class_name();
			$log             = DecisionStore::log( $order );
			$stored[ $hpos ] = array(
				'decision'  => $order->get_meta( DecisionStore::META ),
				'number'    => $order->get_meta( DecisionStore::NUMBER_META ),
				'exempt'    => $order->get_meta( 'is_vat_exempt' ),
				'log_rule'  => $log[0]['rule'],
				'log_count' => count( $log ),
			);
			remove_filter( 'pre_option_woocommerce_custom_orders_table_enabled', $filter, 20 );
		}

		$this->assertNotSame( $stores['yes'], $stores['no'], 'Both storages must actually be exercised' );
		$this->assertStringContainsString( 'OrdersTableDataStore', $stores['yes'] );
		$this->assertSame( $stored['yes'], $stored['no'] );
		$this->assertSame( $decision->to_array(), json_decode( $stored['no']['decision'], true ) );
		$this->assertSame( 'Müller & "Söhne" \\ GmbH', json_decode( $stored['no']['decision'], true )['validation']['name'] );
	}
}
