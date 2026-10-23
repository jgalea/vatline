<?php

namespace Vatright\Tests\Integration\Reports;

use Vatright\Ledger\Fx;
use Vatright\Ledger\Schema;
use Vatright\Reports\EcSalesList;
use Vatright\Reports\OssReport;
use Vatright\Reports\ReportsScreen;
use RuntimeException;
use WC_Order;

require_once __DIR__ . '/ReportsTestCase.php';

class ReportsScreenTest extends ReportsTestCase {

	/** @var ReportsScreen */
	private $screen;

	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->screen = new ReportsScreen( new OssReport( new Fx( $this->transport ) ), new EcSalesList() );
		$_GET         = array(
			'year'    => '2026',
			'quarter' => '3',
		);
	}

	public function tear_down() {
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		parent::tear_down();
	}

	private function without_eur_amount( WC_Order $order ): void {
		global $wpdb;
		$wpdb->update( Schema::ledger_table(), array( 'net_eur' => null ), array( 'order_id' => $order->get_id() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	private function render(): string {
		ob_start();
		$this->screen->render();
		return (string) ob_get_clean();
	}

	public function test_screen_lists_the_ec_sales_list_with_its_download_button(): void {
		$this->reverse_charge( 'digital', 100, 'DE123456789' );

		$html = $this->render();

		$this->assertStringContainsString( 'DE123456789', $html );
		$this->assertStringContainsString( 'vatright_export_esl', $html );
		$this->assertStringNotContainsString( 'notice-error', $html );
	}

	public function test_screen_warns_instead_of_listing_when_an_order_has_no_eur_amount(): void {
		$this->reverse_charge( 'digital', 100, 'DE123456789' );
		$incomplete = $this->reverse_charge( 'digital', 50, 'FR12345678901' );
		$this->without_eur_amount( $incomplete );

		$html = $this->render();

		$this->assertStringContainsString( 'notice-error', $html );
		$this->assertStringContainsString( 'No ECB rate was available for these orders, so the list is not shown: ' . $incomplete->get_id(), $html );
		$this->assertStringNotContainsString( 'DE123456789', $html );
		$this->assertStringNotContainsString( 'vatright_export_esl', $html );
		$this->assertStringContainsString( 'vatright_export_oss', $html );
	}

	public function test_esl_export_dies_naming_the_orders_when_the_report_is_incomplete(): void {
		$incomplete = $this->reverse_charge( 'digital', 50, 'FR12345678901' );
		$this->without_eur_amount( $incomplete );
		$_POST                = array(
			'year'    => '2026',
			'quarter' => '3',
		);
		$_REQUEST['_wpnonce'] = wp_create_nonce( ReportsScreen::NONCE );
		add_filter(
			'wp_die_handler',
			static function () {
				return static function ( $message ) {
					throw new RuntimeException( (string) $message ); // phpcs:ignore WordPress.Security.EscapeOutput
				};
			}
		);

		try {
			$this->screen->export_esl();
			$this->fail( 'Expected the export to stop.' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'The EC Sales List cannot be exported: no ECB rate was available for orders ' . $incomplete->get_id() . '.', $e->getMessage() );
		}
	}
}
