( function () {
	document.addEventListener( 'click', function ( event ) {
		const notice = event.target.closest( '.levy-eu-vat-setup-notice' );
		if ( ! notice || ! event.target.closest( '.notice-dismiss' ) ) {
			return;
		}
		const body = new URLSearchParams( {
			action: window.levyEuVatAdmin.action,
			_ajax_nonce: window.levyEuVatAdmin.nonce,
			issue: notice.dataset.issue,
		} );
		fetch( window.levyEuVatAdmin.ajaxUrl, { method: 'POST', body, credentials: 'same-origin' } );
	} );

	document.addEventListener( 'change', function ( event ) {
		if ( ! event.target.classList.contains( 'levy-eu-vat-class-type' ) ) {
			return;
		}
		const table = event.target.parentElement.querySelector( '.levy-eu-vat-countries' );
		if ( table ) {
			table.hidden = event.target.value !== 'reduced';
		}
	} );
}() );
