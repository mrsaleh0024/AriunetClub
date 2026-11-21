/* LClub phase 3: wallet switch at classic checkout. Progressive enhancement
   only — the fee and every amount are computed server-side; without JS the
   order simply completes without the wallet slice. */
( function () {
	'use strict';

	var config = window.lclubCheckout || {};

	function boxOf( input ) {
		return input.closest ? input.closest( '[data-lclub-wallet-box]' ) : null;
	}

	function note( box, message, tone ) {
		if ( ! box ) {
			return;
		}
		var element = box.querySelector( '[data-lclub-wallet-note]' );
		if ( ! element ) {
			return;
		}
		element.textContent = message || '';
		element.className = 'lclub-wallet-toggle__note' + ( tone ? ' lclub-wallet-toggle__note--' + tone : '' );
	}

	function updateAmounts( box, data ) {
		if ( ! box ) {
			return;
		}
		var available = box.querySelector( '[data-lclub-wallet-available]' );
		var spend = box.querySelector( '[data-lclub-wallet-spend]' );
		if ( available && data.availableFormatted ) {
			available.textContent = data.availableFormatted;
		}
		if ( spend && data.spendFormatted ) {
			spend.textContent = data.spendFormatted;
		}
	}

	function triggerUpdate() {
		if ( window.jQuery ) {
			window.jQuery( document.body ).trigger( 'update_checkout' );
		}
	}

	function toggle( input ) {
		var box = boxOf( input );
		if ( ! box || ! config.ajaxUrl ) {
			return;
		}
		input.disabled = true;
		note( box, '', '' );

		var body = new FormData();
		body.append( 'action', 'lclub_toggle_wallet' );
		body.append( 'nonce', config.nonce );
		body.append( 'use', input.checked ? '1' : '0' );

		window.fetch( config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( response ) { return response.json(); } )
			.then( function ( response ) {
				if ( ! response.success ) {
					input.checked = false;
					note( box, ( response.data && response.data.message ) || ( config.strings && config.strings.error ), 'error' );
					triggerUpdate();
					return;
				}
				updateAmounts( box, response.data );
				note( box, '', '' );
				triggerUpdate();
			} )
			.catch( function () {
				input.checked = false;
				note( box, config.strings && config.strings.error, 'error' );
			} )
			.finally( function () {
				input.disabled = false;
			} );
	}

	document.addEventListener( 'change', function ( event ) {
		var target = event.target;
		if ( target && target.matches && target.matches( '[data-lclub-wallet-toggle]' ) ) {
			toggle( target );
		}
	} );
}() );
