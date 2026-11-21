( function () {
	'use strict';

	var dialog = document.querySelector( '[data-lclub-adjust-dialog]' );
	var openButton = document.querySelector( '[data-lclub-open-adjust]' );
	var form = document.querySelector( '[data-lclub-adjust-form]' );
	var toast = document.querySelector( '[data-lclub-toast]' );

	if ( dialog && openButton && form && window.lclubAdmin ) {
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
	}

	/* Progressive confirm dialogs for destructive actions. */
	document.querySelectorAll( '[data-lclub-confirm]' ).forEach( function ( element ) {
		element.addEventListener( 'submit', function ( event ) {
			if ( ! window.confirm( element.getAttribute( 'data-lclub-confirm' ) ) ) {
				event.preventDefault();
			}
		} );
	} );

	/* Live Persian sentence preview for the rule form. The form works without JS. */
	var ruleForm = document.querySelector( '.lclub-rule-form' );
	var preview = document.querySelector( '[data-lclub-rule-preview]' );
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
		var eventSelect = field( 'lclub_rule[event]' );
		var when = eventSelect && eventSelect.selectedOptions && eventSelect.selectedOptions[ 0 ]
			? eventSelect.selectedOptions[ 0 ].textContent.trim() : 'رویداد';
		var whenParts = [ when ];

		var minAmount = parseFloat( ( field( 'lclub_rule[min_amount]' ) || {} ).value || 0 );
		if ( minAmount > 0 ) { whenParts.push( 'سفارش حداقل ' + money( minAmount ) ); }

		var firstOrder = field( 'lclub_rule[first_order]' );
		if ( firstOrder && firstOrder.checked ) { whenParts.push( 'اولین سفارش مشتری باشد' ); }

		var categories = selectedLabels( 'lclub_rule[categories][]' );
		if ( categories.length ) {
			var categoryMode = 'exclude' === checkedValue( 'lclub_rule[categories_mode]' ) ? 'غیر' : '';
			whenParts.push( 'دستهٔ ' + categoryMode + categories.join( '، ' ) );
		}
		var products = selectedLabels( 'lclub_rule[products][]' );
		if ( products.length ) {
			var productMode = 'exclude' === checkedValue( 'lclub_rule[products_mode]' ) ? 'غیر' : '';
			whenParts.push( 'محصول ' + productMode + products.join( '، ' ) );
		}

		var roles = selectedLabels( 'lclub_rule[user_roles][]' );
		if ( roles.length ) { whenParts.push( 'نقش ' + roles.join( '، ' ) ); }
		var methods = selectedLabels( 'lclub_rule[payment_methods][]' );
		if ( methods.length ) { whenParts.push( 'روش پرداخت ' + methods.join( '، ' ) ); }

		var rewardType = 'fixed' === checkedValue( 'lclub_rule[reward_type]' ) ? 'fixed' : 'percent';
		var rewardValue = parseFloat( ( field( 'lclub_rule[reward_value]' ) || {} ).value || 0 );
		if ( rewardValue > 0 ) {
			if ( 'fixed' === rewardType ) {
				parts.push( 'مبلغ ثابت ' + money( rewardValue ) + ' کش‌بک' );
			} else {
				var rewardText = faDigits( rewardValue ) + '٪ کش‌بک';
				var cap = parseFloat( ( field( 'lclub_rule[reward_cap]' ) || {} ).value || 0 );
				if ( cap > 0 ) { rewardText += ' با سقف ' + money( cap ); }
				parts.push( rewardText );
			}
		}

		var perCount = parseInt( ( field( 'lclub_rule[per_user_count]' ) || {} ).value || 0, 10 );
		var perDays = parseInt( ( field( 'lclub_rule[per_user_days]' ) || {} ).value || 0, 10 );
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
