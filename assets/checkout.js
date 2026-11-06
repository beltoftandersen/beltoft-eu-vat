/**
 * EU VAT number field: checkout and My Account address form.
 *
 * Nothing happens while the customer types: no request and no checkout refresh. The number is
 * checked once the customer is done: on leaving the field, on Enter, or at page load for a number
 * that is already there. Only a number that is complete for its country is sent (formats come
 * from PHP); an incomplete one gets a short local hint and no request. A change of billing
 * country re-checks the number. The answer is written into an inline status line. On the checkout
 * the script then asks for exactly one update_checkout, so the order summary reads the answer
 * from the cache. The checkout refresh itself never calls VIES. In My Account the stored status
 * shows until the customer changes the number; a fresh result replaces it.
 */
( function ( $ ) {
	'use strict';

	var xhr = null;
	var seq = 0;
	var last = '';
	var field = '#billing_eu_vat_number';
	var wrapper = '#billing_eu_vat_number_field';
	var data = window.beltoftEuVat || { countries: [], toggle: false, strings: {} };
	var strings = data.strings || {};
	var minLength = 8;
	var formats = data.formats || {};
	var applied = false;

	function normalised() {
		return String( $( field ).val() || '' ).toUpperCase().replace( /[^A-Z0-9]/g, '' );
	}

	function country() {
		return $( '#billing_country' ).val() || '';
	}

	function format() {
		var number = normalised();
		var prefix = number.substr( 0, 2 );
		var code = 'EL' === prefix ? 'GR' : prefix;

		if ( ! formats[ code ] ) {
			code = String( country() ).toUpperCase();
			prefix = '';
		}

		if ( ! formats[ code ] ) {
			return null;
		}

		return { code: code, bare: prefix ? number.substr( 2 ) : number, info: formats[ code ] };
	}

	function hint() {
		var found = format();

		if ( found && found.info.example ) {
			return ( strings.hint || 'A %1$s VAT number looks like %2$s.' ).replace( '%1$s', found.info.name ).replace( '%2$s', found.info.prefix + found.info.example );
		}

		return strings.incomplete || 'This does not look like a complete VAT number.';
	}

	function complete() {
		var found = format();

		if ( ! found ) {
			return normalised().length >= minLength;
		}

		return new RegExp( '^(?:' + found.info.pattern + ')$' ).test( found.bare );
	}

	/**
	 * Complete, and no longer number could still be valid for the country (a variable-length format such as
	 * Romanian or Lithuanian is checked when the customer leaves the field instead).
	 */
	function full() {
		var found = format();
		var tails = [ '' ];
		var re;
		var i;
		var j;
		var next;

		if ( ! found || ! complete() ) {
			return false;
		}

		re = new RegExp( '^(?:' + found.info.pattern + ')$' );

		for ( i = 0; i < 3; i++ ) {
			next = [];
			for ( j = 0; j < tails.length; j++ ) {
				next.push( tails[ j ] + '0', tails[ j ] + 'A' );
			}
			tails = next;
			for ( j = 0; j < tails.length; j++ ) {
				if ( re.test( found.bare + tails[ j ] ) ) {
					return false;
				}
			}
		}

		return true;
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
		applied = true;

		if ( 'checkout' === data.context ) {
			$( document.body ).trigger( 'update_checkout' );
		}
	}

	function check( again ) {
		var id = ++seq;
		var number = normalised();

		if ( xhr ) {
			xhr.abort();
			xhr = null;
		}

		last = signature();

		if ( ! number || ! complete() ) {
			show( number ? hint() : '', number ? 'error' : '' );

			// An earlier answer is still in the summary: one refresh drops it.
			if ( applied ) {
				refresh();
				applied = false;
			}

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

	// Typing: no request, no refresh. Drop an unfinished check and the old status line quietly.
	$( document.body ).on( 'input', field, function ( event ) {
		if ( ! event.originalEvent ) {
			return;
		}

		if ( xhr ) {
			seq++;
			xhr.abort();
			xhr = null;
		}

		if ( line().text() || line().attr( 'class' ) !== 'beltoft-eu-vat-status' ) {
			show( '', '' );
		}

		// The number is now complete for its country: check it at once, without waiting for the customer to leave the field.
		if ( full() && signature() !== last ) {
			check( false );
		}
	} );

	// Done: leaving the field, or Enter (which would otherwise place the order).
	$( document.body ).on( 'focusout change', field, function () {
		if ( signature() !== last ) {
			check( false );
		}
	} );

	$( document.body ).on( 'keydown', field, function ( event ) {
		if ( 13 !== event.which ) {
			return;
		}

		event.preventDefault();

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

	if ( 'checkout' === data.context && normalised() && applies() ) {
		check( false );
	}
}( jQuery ) );
