( function () {
	document.addEventListener( 'click', function ( event ) {
		const notice = event.target.closest( '.vatright-setup-notice' );
		if ( ! notice || ! event.target.closest( '.notice-dismiss' ) ) {
			return;
		}
		const body = new URLSearchParams( {
			action: window.vatrightAdmin.action,
			_ajax_nonce: window.vatrightAdmin.nonce,
			issue: notice.dataset.issue,
		} );
		fetch( window.vatrightAdmin.ajaxUrl, { method: 'POST', body, credentials: 'same-origin' } );
	} );

	document.addEventListener( 'change', function ( event ) {
		if ( ! event.target.classList.contains( 'vatright-class-type' ) ) {
			return;
		}
		const table = event.target.parentElement.querySelector( '.vatright-countries' );
		if ( table ) {
			table.hidden = event.target.value !== 'reduced';
		}
	} );
}() );
