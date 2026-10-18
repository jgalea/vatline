<?php

namespace Levy\EuVat\Tests\Integration;

use Automattic\WooCommerce\Utilities\FeaturesUtil;
use Levy\EuVat\Tests\Support\IntegrationTestCase;

class PluginBootTest extends IntegrationTestCase {

	public function test_plugin_is_loaded(): void {
		$this->assertTrue( defined( 'LEVY_EU_VAT_VERSION' ) );
		$this->assertTrue( class_exists( \Levy\EuVat\Plugin::class ) );
	}

	public function test_hpos_is_enabled_in_the_environment(): void {
		$this->assertTrue( \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() );
	}

	public function test_declares_hpos_and_block_checkout_compatibility(): void {
		$basename = plugin_basename( LEVY_EU_VAT_FILE );
		foreach ( array( 'custom_order_tables', 'cart_checkout_blocks' ) as $feature ) {
			$compatible = FeaturesUtil::get_compatible_plugins_for_feature( $feature );
			$this->assertContains( $basename, $compatible['compatible'], $feature );
		}
	}
}
