( function () {
	document.addEventListener( 'click', function ( event ) {
		const notice = event.target.closest( '.vatline-setup-notice' );
		if ( ! notice || ! event.target.closest( '.notice-dismiss' ) ) {
			return;
		}
		const body = new URLSearchParams( {
			action: window.vatlineAdmin.action,
			_ajax_nonce: window.vatlineAdmin.nonce,
			issue: notice.dataset.issue,
		} );
		fetch( window.vatlineAdmin.ajaxUrl, { method: 'POST', body, credentials: 'same-origin' } );
	} );

	document.addEventListener( 'change', function ( event ) {
		if ( ! event.target.classList.contains( 'vatline-class-type' ) ) {
			return;
		}
		const table = event.target.parentElement.querySelector( '.vatline-countries' );
		if ( table ) {
			table.hidden = event.target.value !== 'reduced';
		}
	} );
}() );
