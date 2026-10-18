<?php

namespace Vatline\Tests\Integration;

use Vatline\Settings;
use Vatline\Tests\Support\IntegrationTestCase;

class SettingsTest extends IntegrationTestCase {

	public function set_up() {
		parent::set_up();
		delete_option( Settings::OPTION );
	}

	public function test_defaults(): void {
		$settings = Settings::get();
		$this->assertSame( '', $settings->store_vat_number() );
		$this->assertSame( 'charge', $settings->outage_policy() );
		$this->assertSame( 7, $settings->cache_ttl_valid_days() );
		$this->assertFalse( $settings->allow_domestic_exemption() );
		$this->assertFalse( $settings->oss_registered() );
		$this->assertFalse( $settings->fixed_prices() );
		$this->assertTrue( $settings->self_certification() );
		$this->assertTrue( $settings->notify_admin_on_revalidation() );
		$this->assertSame( 10, $settings->retention_years() );
		$this->assertSame( '', $settings->hmrc_client_id() );
		$this->assertSame( '', $settings->hmrc_client_secret() );
		$this->assertSame( 'EUR', $settings->report_currency() );
		$this->assertFalse( $settings->delete_data_on_uninstall() );
	}

	public function test_stored_values_override_defaults(): void {
		update_option(
			Settings::OPTION,
			array(
				'outage_policy'   => 'reject',
				'retention_years' => 6,
			)
		);
		$this->assertSame( 'reject', Settings::get()->outage_policy() );
		$this->assertSame( 6, Settings::get()->retention_years() );
		$this->assertSame( 7, Settings::get()->cache_ttl_valid_days() );
	}

	public function test_unknown_outage_policy_falls_back_to_charge(): void {
		update_option( Settings::OPTION, array( 'outage_policy' => 'nonsense' ) );
		$this->assertSame( 'charge', Settings::get()->outage_policy() );
	}
}
