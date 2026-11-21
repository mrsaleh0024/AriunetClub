( function () {
	'use strict';

	var dialog = document.querySelector( '[data-ariunetclub-adjust-dialog]' );
	var openButton = document.querySelector( '[data-ariunetclub-open-adjust]' );
	var form = document.querySelector( '[data-ariunetclub-adjust-form]' );
	var toast = document.querySelector( '[data-ariunetclub-toast]' );

	if ( dialog && openButton && form && window.ariunetclubAdmin ) {
		function showToast( message, type ) {
			toast.textContent = message;
			toast.className = 'ariunetclub-toast ariunetclub-toast--' + type;
			toast.hidden = false;
			window.setTimeout( function () { toast.hidden = true; }, 5000 );
		}

		openButton.addEventListener( 'click', function () {
			dialog.showModal();
			dialog.querySelector( '[name="amount"]' ).focus();
		} );

		document.querySelectorAll( '[data-ariunetclub-close-adjust]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () { dialog.close(); } );
		} );

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			var saveButton = form.querySelector( '[data-ariunetclub-save-adjust]' );
			var data = new FormData( form );
			data.append( 'action', 'ariunetclub_adjust_balance' );
			data.append( 'nonce', window.ariunetclubAdmin.nonce );
			saveButton.disabled = true;

			window.fetch( window.ariunetclubAdmin.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data } )
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
	}

	/* Progressive confirm dialogs for destructive actions. */
	document.querySelectorAll( '[data-ariunetclub-confirm]' ).forEach( function ( element ) {
		element.addEventListener( 'submit', function ( event ) {
			if ( ! window.confirm( element.getAttribute( 'data-ariunetclub-confirm' ) ) ) {
				event.preventDefault();
			}
		} );
	} );

	/* Live Persian sentence preview for the rule form. The form works without JS. */
	var ruleForm = document.querySelector( '.ariunetclub-rule-form' );
	var preview = document.querySelector( '[data-ariunetclub-rule-preview]' );
	if ( ! ruleForm || ! preview ) {
		return;
	}

	function field( name ) { return ruleForm.querySelector( '[name="' + name + '"]' ); }

	function selectedLabels( name, limit ) {
		var select = field( name );
		if ( ! select || ! select.selectedOptions || 0 === select.selectedOptions.length ) { return []; }
		return Array.prototype.slice.call( select.selectedOptions, 0, limit || 3 )
			.map( function ( option ) { return option.textContent.trim(); } );
	}

	function checkedValue( name ) {
		var input = ruleForm.querySelector( '[name="' + name + '"]:checked' );
		return input ? input.value : '';
	}

	function faDigits( value ) {
		return String( value ).replace( /[0-9]/g, function ( digit ) {
			return '۰۱۲۳۴۵۶۷۸۹'[ digit ];
		} );
	}

	function money( value ) {
		var number = Math.round( parseFloat( value ) || 0 );
		return faDigits( number.toLocaleString( 'en-US' ) ) + ' تومان';
	}

	function buildSentence() {
		var parts = [];
		var eventSelect = field( 'ariunetclub_rule[event]' );
		var when = eventSelect && eventSelect.selectedOptions && eventSelect.selectedOptions[ 0 ]
			? eventSelect.selectedOptions[ 0 ].textContent.trim() : 'رویداد';
		var whenParts = [ when ];

		var minAmount = parseFloat( ( field( 'ariunetclub_rule[min_amount]' ) || {} ).value || 0 );
		if ( minAmount > 0 ) { whenParts.push( 'سفارش حداقل ' + money( minAmount ) ); }

		var firstOrder = field( 'ariunetclub_rule[first_order]' );
		if ( firstOrder && firstOrder.checked ) { whenParts.push( 'اولین سفارش مشتری باشد' ); }

		var categories = selectedLabels( 'ariunetclub_rule[categories][]' );
		if ( categories.length ) {
			var categoryMode = 'exclude' === checkedValue( 'ariunetclub_rule[categories_mode]' ) ? 'غیر' : '';
			whenParts.push( 'دستهٔ ' + categoryMode + categories.join( '، ' ) );
		}
		var products = selectedLabels( 'ariunetclub_rule[products][]' );
		if ( products.length ) {
			var productMode = 'exclude' === checkedValue( 'ariunetclub_rule[products_mode]' ) ? 'غیر' : '';
			whenParts.push( 'محصول ' + productMode + products.join( '، ' ) );
		}

		var roles = selectedLabels( 'ariunetclub_rule[user_roles][]' );
		if ( roles.length ) { whenParts.push( 'نقش ' + roles.join( '، ' ) ); }
		var methods = selectedLabels( 'ariunetclub_rule[payment_methods][]' );
		if ( methods.length ) { whenParts.push( 'روش پرداخت ' + methods.join( '، ' ) ); }

		var rewardType = 'fixed' === checkedValue( 'ariunetclub_rule[reward_type]' ) ? 'fixed' : 'percent';
		var rewardValue = parseFloat( ( field( 'ariunetclub_rule[reward_value]' ) || {} ).value || 0 );
		if ( rewardValue > 0 ) {
			if ( 'fixed' === rewardType ) {
				parts.push( 'مبلغ ثابت ' + money( rewardValue ) + ' کش‌بک' );
			} else {
				var rewardText = faDigits( rewardValue ) + '٪ کش‌بک';
				var cap = parseFloat( ( field( 'ariunetclub_rule[reward_cap]' ) || {} ).value || 0 );
				if ( cap > 0 ) { rewardText += ' با سقف ' + money( cap ); }
				parts.push( rewardText );
			}
		}

		var perCount = parseInt( ( field( 'ariunetclub_rule[per_user_count]' ) || {} ).value || 0, 10 );
		var perDays = parseInt( ( field( 'ariunetclub_rule[per_user_days]' ) || {} ).value || 0, 10 );
		if ( perCount > 0 && perDays > 0 ) {
			parts.push( 'حداکثر ' + faDigits( perCount ) + ' بار در ' + faDigits( perDays ) + ' روز' );
		}

		var sentence = 'وقتی ' + whenParts.join( ' و ' ) + '، ' + ( parts.length ? parts.join( '؛ ' ) : 'هیچ پاداشی تعریف نشده' ) + ' بده.';
		return sentence;
	}

	function renderPreview() {
		preview.textContent = buildSentence();
	}

	ruleForm.addEventListener( 'change', renderPreview );
	ruleForm.addEventListener( 'input', renderPreview );
	renderPreview();
}() );
