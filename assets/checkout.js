/**
 * EU VAT number field on the classic checkout.
 *
 * The order summary refreshes only through WooCommerce's own update_checkout. This script asks
 * for that refresh once, debounced, after the customer really changes the field. It never asks
 * on page load.
 */
( function ( $ ) {
	'use strict';

	var timer = null;
	var field = '#billing_eu_vat_number';
	var wrapper = '#billing_eu_vat_number_field';
	var data = window.beltoftEuVat || { countries: [], toggle: false };

	function toggleWrapper() {
		var country = $( '#billing_country' ).val();

		if ( ! data.toggle || ! country ) {
			return;
		}

		$( wrapper ).toggle( -1 !== data.countries.indexOf( country ) );
	}

	$( document.body ).on( 'input', field, function ( event ) {
		if ( ! event.originalEvent ) {
			return;
		}

		clearTimeout( timer );
		timer = setTimeout( function () {
			$( document.body ).trigger( 'update_checkout' );
		}, 600 );
	} );

	$( document.body ).on( 'change', '#billing_country', toggleWrapper );
	toggleWrapper();
}( jQuery ) );
