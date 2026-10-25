( function () {
	'use strict';

	document.addEventListener( 'click', function ( e ) {
		// Reveal / hide secret key fields.
		var reveal = e.target.closest( '.spf-reveal' );
		if ( reveal ) {
			var input = reveal.parentNode.querySelector( 'input' );
			if ( input ) {
				input.type = ( input.type === 'password' ) ? 'text' : 'password';
			}
			return;
		}

		// Copy the webhook URL.
		var copyBtn = e.target.closest( '.spf-copy-btn' );
		if ( copyBtn ) {
			var value = copyBtn.getAttribute( 'data-copy' );
			if ( navigator.clipboard && value ) {
				navigator.clipboard.writeText( value );
			}
			var original = copyBtn.textContent;
			copyBtn.textContent = 'Copied!';
			setTimeout( function () { copyBtn.textContent = original; }, 1500 );
		}
	} );
}() );
