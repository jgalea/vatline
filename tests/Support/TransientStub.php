<?php

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals,Generic.CodeAnalysis.UnusedFunctionParameter

$GLOBALS['levy_transients'] = array();

function levy_eu_vat_test_reset_transients(): void {
	$GLOBALS['levy_transients'] = array();
}

if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $key ) {
		return $GLOBALS['levy_transients'][ $key ] ?? false;
	}

	function set_transient( $key, $value, $expiration = 0 ) {
		$GLOBALS['levy_transients'][ $key ] = $value;
		return true;
	}

	function delete_transient( $key ) {
		unset( $GLOBALS['levy_transients'][ $key ] );
		return true;
	}
}
