<?php

namespace Vatline\Tests\Integration\Integrations;

use Vatline\Tests\Support\IntegrationTestCase;

class SubscriptionsInertTest extends IntegrationTestCase {

	public function test_nothing_is_hooked_without_subscriptions() {
		if ( class_exists( 'WC_Subscriptions' ) && ! defined( 'VATLINE_TEST_WCS_STAND_IN' ) ) {
			$this->markTestSkipped( 'WooCommerce Subscriptions is active, so the plugin legitimately hooks into it.' );
		}

		$this->assertFalse( has_filter( 'wcs_renewal_order_created' ) );
		$this->assertFalse( has_action( 'woocommerce_checkout_subscription_created' ) );
		$this->assertFalse( has_action( 'woocommerce_subscriptions_switch_completed' ) );
	}
}
