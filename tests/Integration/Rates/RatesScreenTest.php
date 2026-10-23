<?php

namespace Vatright\Tests\Integration\Rates;

use Vatright\Rates\RatesScreen;
use Vatright\Rates\RateSync;
use Vatright\Rates\Tedb;
use Vatright\Tests\Support\FixtureTransport;
use Vatright\Tests\Support\IntegrationTestCase;
use ReflectionProperty;
use WC_Admin_Settings;

require_once dirname( __DIR__, 2 ) . '/Support/FixtureTransport.php';

class RatesScreenTest extends IntegrationTestCase {

	/** @var RatesScreen */
	private $screen;

	public function set_up() {
		parent::set_up();
		foreach ( array( 'errors', 'messages' ) as $property ) {
			$reflection = new ReflectionProperty( WC_Admin_Settings::class, $property );
			$reflection->setAccessible( true );
			$reflection->setValue( null, array() );
		}
		$this->screen = new RatesScreen( new RateSync( new Tedb( new FixtureTransport( sys_get_temp_dir() ) ) ) );
	}

	public function tear_down() {
		unset( $_GET['vatright_applied'], $_GET['vatright_refreshed'] );
		parent::tear_down();
	}

	private function messages(): string {
		ob_start();
		WC_Admin_Settings::show_messages();
		return (string) ob_get_clean();
	}

	public function test_applying_rates_confirms_how_many_were_written(): void {
		$_GET['vatright_applied'] = '28';
		$this->screen->register();

		do_action( 'woocommerce_before_settings_tax' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals

		$html = $this->messages();
		$this->assertStringContainsString( 'class="updated inline"', $html );
		$this->assertStringContainsString( '28 VAT rates applied.', $html );
	}

	public function test_applying_nothing_says_so(): void {
		$_GET['vatright_applied'] = '0';
		$this->screen->result_message();

		$this->assertStringContainsString( 'No VAT rates were changed.', $this->messages() );
	}

	public function test_a_failed_refresh_shows_an_error(): void {
		$_GET['vatright_refreshed'] = '0';
		$this->screen->result_message();

		$this->assertStringContainsString( 'class="error inline"', $this->messages() );
	}

	public function test_no_message_without_a_redirect(): void {
		$this->screen->result_message();

		$this->assertSame( '', $this->messages() );
	}
}
