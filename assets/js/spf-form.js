( function () {
	'use strict';

	function ready( fn ) {
		if ( document.readyState !== 'loading' ) {
			fn();
		} else {
			document.addEventListener( 'DOMContentLoaded', fn );
		}
	}

	ready( function () {
		var forms = document.querySelectorAll( '.spf-form' );
		Array.prototype.forEach.call( forms, function ( form ) {
			form.addEventListener( 'submit', function ( e ) {
				e.preventDefault();

				var errEl = form.querySelector( '.spf-error' );
				var btn = form.querySelector( '.spf-btn' );
				var label = form.querySelector( '.spf-btn-label' );
				var spinner = form.querySelector( '.spf-spinner' );
				var originalLabel = label ? label.textContent : '';

				function showError( msg ) {
					if ( errEl ) {
						errEl.textContent = msg;
						errEl.hidden = false;
					}
				}
				function clearError() {
					if ( errEl ) {
						errEl.hidden = true;
					}
				}
				function reset() {
					btn.disabled = false;
					if ( label ) {
						label.textContent = originalLabel;
					}
					if ( spinner ) {
						spinner.hidden = true;
					}
				}

				clearError();

				var nameEl = form.querySelector( '[name=name]' );
				var emailEl = form.querySelector( '[name=email]' );
				var amountEl = form.querySelector( '[name=amount]' );
				var name = nameEl ? nameEl.value.trim() : '';
				var email = emailEl ? emailEl.value.trim() : '';
				var amount = amountEl ? parseFloat( amountEl.value ) : 0;

				if ( ! name || ! email || ! ( amount > 0 ) ) {
					showError( DPFS_FORM.strings.invalid );
					return;
				}

				btn.disabled = true;
				if ( label ) {
					label.textContent = DPFS_FORM.strings.processing;
				}
				if ( spinner ) {
					spinner.hidden = false;
				}

				var data = new FormData( form );
				data.append( 'action', 'dpfs_create_session' );
				data.append( 'nonce', DPFS_FORM.nonce );

				fetch( DPFS_FORM.ajax_url, {
					method: 'POST',
					body: data,
					credentials: 'same-origin'
				} )
					.then( function ( r ) { return r.json(); } )
					.then( function ( res ) {
						if ( res && res.success && res.data && res.data.url ) {
							window.location.href = res.data.url;
						} else {
							showError( ( res && res.data && res.data.message ) || DPFS_FORM.strings.error );
							reset();
						}
					} )
					.catch( function () {
						showError( DPFS_FORM.strings.error );
						reset();
					} );
			} );
		} );
	} );
}() );
