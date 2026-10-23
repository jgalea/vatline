<?php

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals,Generic.CodeAnalysis.UnusedFunctionParameter

$GLOBALS['vatright_transients'] = array();

function vatright_test_reset_transients(): void {
	$GLOBALS['vatright_transients'] = array();
}

if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $key ) {
		return $GLOBALS['vatright_transients'][ $key ] ?? false;
	}

	function set_transient( $key, $value, $expiration = 0 ) {
		$GLOBALS['vatright_transients'][ $key ] = $value;
		return true;
	}

	function delete_transient( $key ) {
		unset( $GLOBALS['vatright_transients'][ $key ] );
		return true;
	}
}
