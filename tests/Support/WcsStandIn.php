<?php
// phpcs:disable Universal.Files.SeparateFunctionsFromOO, Generic.Files.OneObjectStructurePerFile, WordPress.NamingConventions.PrefixAllGlobals -- stand-in for the paid plugin's global symbols.

if ( ! class_exists( 'WC_Subscriptions' ) ) {
	define( 'VATLINE_TEST_WCS_STAND_IN', true );

	class WC_Subscriptions {} // phpcs:ignore Generic.Classes.OpeningBraceSameLine -- empty stand-in.

	class WC_Subscription extends WC_Order {} // phpcs:ignore Generic.Classes.OpeningBraceSameLine -- empty stand-in.

	function wcs_get_subscriptions_for_switch_order( $order ) {
		return apply_filters( 'vatline_test_wcs_switch_subscriptions', array(), $order );
	}
}
