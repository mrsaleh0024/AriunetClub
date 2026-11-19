( function () {
	'use strict';

	var dialog = document.querySelector( '[data-lclub-adjust-dialog]' );
	var openButton = document.querySelector( '[data-lclub-open-adjust]' );
	var form = document.querySelector( '[data-lclub-adjust-form]' );
	var toast = document.querySelector( '[data-lclub-toast]' );

	if ( ! dialog || ! openButton || ! form || ! window.lclubAdmin ) {
		return;
	}

	function showToast( message, type ) {
		toast.textContent = message;
		toast.className = 'lclub-toast lclub-toast--' + type;
		toast.hidden = false;
		window.setTimeout( function () { toast.hidden = true; }, 5000 );
	}

	openButton.addEventListener( 'click', function () {
		dialog.showModal();
		dialog.querySelector( '[name="amount"]' ).focus();
	} );

	document.querySelectorAll( '[data-lclub-close-adjust]' ).forEach( function ( button ) {
		button.addEventListener( 'click', function () { dialog.close(); } );
	} );

	form.addEventListener( 'submit', function ( event ) {
		event.preventDefault();
		var saveButton = form.querySelector( '[data-lclub-save-adjust]' );
		var data = new FormData( form );
		data.append( 'action', 'lclub_adjust_balance' );
		data.append( 'nonce', window.lclubAdmin.nonce );
		saveButton.disabled = true;

		window.fetch( window.lclubAdmin.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data } )
			.then( function ( response ) { return response.json(); } )
			.then( function ( response ) {
				if ( ! response.success ) {
					throw new Error( response.data && response.data.message ? response.data.message : 'تغییر موجودی ثبت نشد.' );
				}
				dialog.close();
				showToast( response.data.message, 'success' );
				window.setTimeout( function () { window.location.reload(); }, 1200 );
			} )
			.catch( function ( error ) { showToast( error.message || 'تغییر موجودی ثبت نشد.', 'error' ); } )
			.finally( function () { saveButton.disabled = false; } );
	} );
}() );
