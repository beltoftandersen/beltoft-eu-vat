/**
 * EU VAT number field: checkout and My Account address form.
 *
 * The customer's number is checked by one small request (wc-ajax) right after typing: on blur,
 * or 600 ms after the typing stops. The answer is written into an inline status line. On the
 * checkout the script then asks for exactly one update_checkout, so the order summary reads the
 * answer from the cache. The checkout refresh itself never calls VIES. It never asks on page
 * load, except to check a number that is already in the field.
 */
( function ( $ ) {
	'use strict';

	var timer = null;
	var xhr = null;
	var seq = 0;
	var last = '';
	var field = '#billing_eu_vat_number';
	var wrapper = '#billing_eu_vat_number_field';
	var data = window.beltoftEuVat || { countries: [], toggle: false, strings: {} };
	var strings = data.strings || {};
	var minLength = 8;

	function normalised() {
		return String( $( field ).val() || '' ).toUpperCase().replace( /[^A-Z0-9]/g, '' );
	}

	function country() {
		return $( '#billing_country' ).val() || '';
	}

	function signature() {
		return normalised() + '|' + country();
	}

	function toggleWrapper() {
		if ( ! data.toggle || ! country() ) {
			return;
		}

		$( wrapper ).toggle( -1 !== data.countries.indexOf( country() ) );
	}

	function applies() {
		return ! data.toggle || ! country() || -1 !== data.countries.indexOf( country() );
	}

	function line() {
		var $line = $( wrapper ).find( '.beltoft-eu-vat-status' );

		if ( ! $line.length ) {
			$line = $( '<span class="beltoft-eu-vat-status" role="status" aria-live="polite"></span>' );

			if ( $( wrapper ).find( '.beltoft-eu-vat-message' ).length ) {
				$( wrapper ).find( '.beltoft-eu-vat-message' ).first().before( $line );
			} else {
				$( wrapper ).append( $line );
			}
		}

		return $line;
	}

	function show( text, state, retry ) {
		var $line = line();

		$line.empty().attr( 'class', 'beltoft-eu-vat-status' + ( state ? ' is-' + state : '' ) ).attr( 'aria-busy', 'checking' === state ? 'true' : 'false' );

		if ( ! text ) {
			return;
		}

		$line.append( document.createTextNode( text ) );

		if ( retry ) {
			$line.append( ' ', $( '<a href="#" class="beltoft-eu-vat-again"></a>' ).text( strings.again || 'Check again' ) );
		}
	}

	function refresh() {
		if ( 'checkout' === data.context ) {
			$( document.body ).trigger( 'update_checkout' );
		}
	}

	function check( again ) {
		var id = ++seq;
		var number = normalised();

		clearTimeout( timer );

		if ( xhr ) {
			xhr.abort();
			xhr = null;
		}

		last = signature();

		if ( number.length < minLength ) {
			show( '', '' );
			refresh();
			return;
		}

		show( strings.checking || 'Checking with the EU VAT register…', 'checking' );

		xhr = $.ajax( {
			url: data.url,
			method: 'POST',
			dataType: 'json',
			timeout: 25000,
			data: { security: data.nonce, vat: number, country: country(), retry: again ? 1 : 0 }
		} ).done( function ( reply ) {
			if ( id === seq ) {
				show( reply.text, reply.state, reply.retry );
			}
		} ).fail( function ( request, status ) {
			var reply = request.responseJSON;

			if ( id !== seq || 'abort' === status ) {
				return;
			}

			if ( reply && reply.text ) {
				show( reply.text, reply.state || 'error', reply.retry );
			} else {
				show( strings.failed || '', 'unreachable', true );
			}
		} ).always( function () {
			if ( id === seq ) {
				xhr = null;
				refresh();
			}
		} );
	}

	$( document.body ).on( 'input', field, function ( event ) {
		if ( ! event.originalEvent ) {
			return;
		}

		clearTimeout( timer );
		show( '', '' );
		timer = setTimeout( function () {
			check( false );
		}, 600 );
	} );

	$( document.body ).on( 'focusout', field, function () {
		if ( signature() !== last ) {
			check( false );
		}
	} );

	$( document.body ).on( 'click', '.beltoft-eu-vat-again', function ( event ) {
		event.preventDefault();
		check( true );
	} );

	$( document.body ).on( 'change', '#billing_country', function () {
		toggleWrapper();

		if ( normalised() && applies() && signature() !== last ) {
			check( false );
		}
	} );

	toggleWrapper();

	if ( normalised() && applies() ) {
		check( false );
	}
}( jQuery ) );
