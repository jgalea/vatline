<?php

namespace Vatright\Tests\Integration\Privacy;

use Vatright\Privacy\Retention;

require_once __DIR__ . '/PrivacyTestCase.php';

class RetentionTest extends PrivacyTestCase {

	public function test_retention_job_scrubs_old_orders_only(): void {
		$old    = $this->vat_order( $this->year() - 11 );
		$recent = $this->vat_order( $this->year() - 2 );

		( new Retention() )->run_batch( 0 );

		$this->assertNull( $this->record( $old->get_id() )['vat_number'] );
		$this->assertNull( $this->ledger_vat( $old->get_id() ) );
		$this->assertNotEmpty( wc_get_order( $old->get_id() )->get_meta( Retention::SCRUBBED_META ) );
		$this->assertNotNull( $this->record( $recent->get_id() )['vat_number'] );
		$this->assertSame( 'DE123456789', $this->ledger_vat( $recent->get_id() ) );
		$this->assertSame( '', (string) wc_get_order( $recent->get_id() )->get_meta( Retention::SCRUBBED_META ) );
	}

	public function test_retention_follows_the_setting(): void {
		update_option( 'vatright_settings', array( 'retention_years' => 2 ) );
		$order = $this->vat_order( $this->year() - 3 );

		( new Retention() )->run_batch( 0 );

		$this->assertNull( $this->record( $order->get_id() )['vat_number'] );
	}

	public function test_order_paid_in_a_later_year_is_kept_by_the_paid_date(): void {
		$order = $this->vat_order( $this->year() - 11 );
		$order->set_date_paid( strtotime( ( $this->year() - 10 ) . '-02-01 UTC' ) );
		$order->save();

		( new Retention() )->run_batch( 0 );

		$this->assertNotNull( $this->record( $order->get_id() )['vat_number'] );
	}

	public function test_schedules_one_daily_action(): void {
		as_unschedule_all_actions( Retention::HOOK, null, Retention::AS_GROUP );
		$retention = new Retention();

		$retention->schedule();
		$retention->schedule();

		$this->assertCount(
			1,
			as_get_scheduled_actions(
				array(
					'hook'   => Retention::HOOK,
					'group'  => Retention::AS_GROUP,
					'status' => 'pending',
				)
			)
		);
	}
}
