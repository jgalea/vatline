jQuery( function ( $ ) {
	const DEBOUNCE_MS = 600;
	let timer = null;

	$( document.body ).on( 'input', '#billing_vatline_number', function () {
		clearTimeout( timer );
		timer = setTimeout( function () {
			$( document.body ).trigger( 'update_checkout' );
		}, DEBOUNCE_MS );
	} );

	$( document.body ).on( 'change', 'input[name="vatline_self_certify"]', function () {
		$( document.body ).trigger( 'update_checkout' );
	} );
} );
