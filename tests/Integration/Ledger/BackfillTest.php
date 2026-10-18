<?php

namespace Levy\EuVat\Tests\Integration\Ledger;

use ActionScheduler_QueueRunner;
use ActionScheduler_Store;
use Levy\EuVat\Admin\SettingsSection;
use Levy\EuVat\Admin\SetupCheck;
use Levy\EuVat\Domain\Decision;
use Levy\EuVat\Domain\Scheme;
use Levy\EuVat\Ledger\Backfill;
use Levy\EuVat\Ledger\LedgerWriter;
use Levy\EuVat\Ledger\ThresholdTracker;
use WC_Order;

require_once __DIR__ . '/LedgerTestCase.php';

class BackfillTest extends LedgerTestCase {

	/** @var Backfill */
	private $backfill;

	/** @var string */
	private $recent;

	public function set_up() {
		parent::set_up();
		$this->backfill = new Backfill( $this->writer );
		$this->recent   = gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS );
	}

	/**
	 * @param array<string,mixed> $extra
	 */
	private function legacy_order( string $country, ?int $rate_id, float $percent, array $extra = array() ): WC_Order {
		$item = array(
			'name' => 'Gadget',
			'net'  => 100,
		);
		if ( null !== $rate_id ) {
			$item['rate_id'] = $rate_id;
			$item['percent'] = $percent;
		}
		return $this->make_order(
			array_merge(
				array(
					'billing_country' => $country,
					'created'         => $this->recent,
					'items'           => array( $item ),
				),
				$extra
			)
		);
	}

	private function scheme_of( WC_Order $order ): string {
		return $this->ledger_rows( 'order_id = ' . $order->get_id() )[0]['scheme'];
	}

	public function test_backfill_infers_and_flags(): void {
		$de          = $this->tax_rate( 'DE', '19.0000' );
		$mt          = $this->tax_rate( 'MT', '18.0000' );
		$destination = $this->legacy_order( 'DE', $de, 19 );
		$origin      = $this->legacy_order( 'DE', $mt, 18 );
		$exempt      = $this->legacy_order( 'DE', null, 0 );
		$exempt->update_meta_data( 'is_vat_exempt', 'yes' );
		$exempt->save_meta_data();
		$export   = $this->legacy_order( 'US', null, 0 );
		$domestic = $this->legacy_order( 'MT', $mt, 18 );
		$canary   = $this->legacy_order( 'ES', null, 0 );
		$canary->set_billing_postcode( '35001' );
		$canary->save();

		$this->backfill->run_batch( 0 );

		$this->assertSame( Scheme::OSS_DESTINATION, $this->scheme_of( $destination ) );
		$this->assertSame( Scheme::ORIGIN, $this->scheme_of( $origin ) );
		$this->assertSame( Scheme::REVERSE_CHARGE_SERVICES, $this->scheme_of( $exempt ) );
		$this->assertSame( Scheme::EXPORT, $this->scheme_of( $export ) );
		$this->assertSame( Scheme::DOMESTIC, $this->scheme_of( $domestic ) );
		$this->assertSame( Scheme::OUTSIDE_TERRITORY, $this->scheme_of( $canary ) );

		$decision = Decision::from_array( json_decode( (string) wc_get_order( $destination->get_id() )->get_meta( LedgerWriter::DECISION_META ), true ) );
		$this->assertSame( 'backfill', $decision->trigger() );
		$this->assertSame( array( 'backfilled' ), $decision->flags() );
		$this->assertCount( 1, $decision->lines() );
		$this->assertSame( 'DE', $decision->lines()[0]->tax_country() );
	}

	public function test_backfilled_exempt_order_is_marked_exempt(): void {
		$order = $this->legacy_order( 'DE', null, 0 );
		$order->update_meta_data( 'is_vat_exempt', 'yes' );
		$order->save_meta_data();

		$this->backfill->run_batch( 0 );

		$decision = Decision::from_array( json_decode( (string) wc_get_order( $order->get_id() )->get_meta( LedgerWriter::DECISION_META ), true ) );
		$this->assertTrue( $decision->is_vat_exempt() );
	}

	public function test_backfilled_rows_carry_amounts_from_the_order(): void {
		$de    = $this->tax_rate( 'DE', '19.0000' );
		$order = $this->legacy_order( 'DE', $de, 19 );

		$this->backfill->run_batch( 0 );

		$row = $this->ledger_rows( 'order_id = ' . $order->get_id() )[0];
		$this->assertSame( '100.0000', $row['net'] );
		$this->assertSame( '19.0000', $row['vat'] );
		$this->assertSame( '19.0000', $row['rate_percent'] );
	}

	public function test_orders_with_ledger_rows_are_left_alone(): void {
		$de    = $this->tax_rate( 'DE', '19.0000' );
		$order = $this->legacy_order( 'DE', $de, 19 );
		$this->seed_decision( $order, Scheme::ORIGIN );
		$this->writer->record_order( $order );
		$before = $this->ledger_rows( 'order_id = ' . $order->get_id() );

		$this->backfill->run_batch( 0 );

		$this->assertSame( $before, $this->ledger_rows( 'order_id = ' . $order->get_id() ) );
	}

	public function test_existing_decision_is_not_overwritten(): void {
		$de    = $this->tax_rate( 'DE', '19.0000' );
		$order = $this->legacy_order( 'DE', $de, 19 );
		$this->seed_decision( $order, Scheme::ORIGIN );

		$this->backfill->run_batch( 0 );

		$decision = Decision::from_array( json_decode( (string) wc_get_order( $order->get_id() )->get_meta( LedgerWriter::DECISION_META ), true ) );
		$this->assertSame( 'checkout', $decision->trigger() );
		$this->assertSame( Scheme::ORIGIN, $this->scheme_of( $order ) );
	}

	public function test_unpaid_and_old_orders_are_skipped(): void {
		$de      = $this->tax_rate( 'DE', '19.0000' );
		$pending = $this->legacy_order( 'DE', $de, 19, array( 'status' => 'pending' ) );
		$old     = $this->legacy_order( 'DE', $de, 19, array( 'created' => ( (int) gmdate( 'Y' ) - 2 ) . '-06-01 10:00:00' ) );
		$last_yr = $this->legacy_order( 'DE', $de, 19, array( 'created' => ( (int) gmdate( 'Y' ) - 1 ) . '-06-01 10:00:00' ) );

		$this->backfill->run_batch( 0 );

		$this->assertSame( array(), $this->ledger_rows( 'order_id = ' . $pending->get_id() ) );
		$this->assertSame( array(), $this->ledger_rows( 'order_id = ' . $old->get_id() ) );
		$this->assertCount( 1, $this->ledger_rows( 'order_id = ' . $last_yr->get_id() ) );
	}

	public function test_refunded_order_gets_sale_and_refund_rows(): void {
		$de    = $this->tax_rate( 'DE', '19.0000' );
		$order = $this->legacy_order( 'DE', $de, 19 );
		wc_create_refund(
			array(
				'order_id' => $order->get_id(),
				'amount'   => '59.5',
			)
		);

		$this->backfill->run_batch( 0 );

		$this->assertCount( 1, $this->ledger_rows( "order_id = {$order->get_id()} AND entry_type = 'sale'" ) );
		$this->assertCount( 1, $this->ledger_rows( "order_id = {$order->get_id()} AND entry_type = 'refund'" ) );
	}

	public function test_a_full_batch_queues_the_next_one(): void {
		for ( $i = 0; $i < Backfill::BATCH; $i++ ) {
			$this->legacy_order( 'MT', null, 0 );
		}

		$this->backfill->run_batch( 0 );

		$this->assertTrue( as_has_scheduled_action( Backfill::HOOK, array( Backfill::BATCH ), Backfill::AS_GROUP ) );
	}

	public function test_a_short_batch_ends_the_run(): void {
		$this->legacy_order( 'MT', null, 0 );

		$this->backfill->run_batch( 0 );

		$this->assertFalse( as_has_scheduled_action( Backfill::HOOK, array( Backfill::BATCH ), Backfill::AS_GROUP ) );
	}

	public function test_schedule_queues_the_first_batch_once(): void {
		$this->backfill->schedule();
		$this->backfill->schedule();

		$this->assertCount(
			1,
			as_get_scheduled_actions(
				array(
					'hook'   => Backfill::HOOK,
					'status' => 'pending',
				),
				'ids'
			)
		);
	}

	public function test_registered_hook_runs_a_batch(): void {
		$de    = $this->tax_rate( 'DE', '19.0000' );
		$order = $this->legacy_order( 'DE', $de, 19 );
		$this->backfill->register();

		do_action( Backfill::HOOK, 0 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals

		$this->assertCount( 1, $this->ledger_rows( 'order_id = ' . $order->get_id() ) );
	}

	public function test_activation_jobs_bring_earlier_orders_into_the_ledger_and_threshold(): void {
		$de     = $this->tax_rate( 'DE', '19.0000' );
		$orders = array();
		for ( $i = 0; $i < Backfill::BATCH + 2; $i++ ) {
			$orders[] = $this->legacy_order( 'DE', $de, 19 );
		}
		$this->backfill->register();
		as_unschedule_all_actions( '', array(), Backfill::AS_GROUP );
		delete_option( SettingsSection::JOBS_SCHEDULED );
		$year   = (int) substr( $this->recent, 0, 4 );
		$before = ( new ThresholdTracker() )->status( $year )->current_eur;

		( new SettingsSection( new SetupCheck() ) )->schedule_initial_jobs();
		$ran = $this->run_pending( Backfill::HOOK );

		$this->assertSame( 2, $ran );
		$this->assertCount( Backfill::BATCH + 2, $this->ledger_rows( "entry_type = 'sale'" ) );
		foreach ( $orders as $order ) {
			$this->assertCount( 1, $this->ledger_rows( 'order_id = ' . $order->get_id() ) );
		}
		$this->assertSame( 0.0, $before );
		$this->assertEqualsWithDelta( 100.0 * ( Backfill::BATCH + 2 ), ( new ThresholdTracker() )->status( $year )->current_eur, 0.01 );
	}

	/**
	 * Runs pending actions for $hook through Action Scheduler, including the
	 * ones the run itself queues, and fails on any action that did not complete.
	 */
	private function run_pending( string $hook ): int {
		$runner = ActionScheduler_QueueRunner::instance();
		$store  = ActionScheduler_Store::instance();
		$ran    = 0;
		for ( $round = 0; $round < 20; $round++ ) {
			$ids = as_get_scheduled_actions(
				array(
					'hook'   => $hook,
					'status' => ActionScheduler_Store::STATUS_PENDING,
				),
				'ids'
			);
			if ( ! $ids ) {
				return $ran;
			}
			foreach ( $ids as $id ) {
				$runner->process_action( (int) $id, 'test' );
				$this->assertSame( ActionScheduler_Store::STATUS_COMPLETE, $store->get_status( (string) $id ), "action {$id} on {$hook}" );
				++$ran;
			}
		}
		$this->fail( "{$hook} kept queueing actions" );
	}
}
