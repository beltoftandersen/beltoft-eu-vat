<?php
/**
 * Per-country VAT number formats.
 *
 * @package BeltoftEuVat
 */

namespace BeltoftEuVat;

defined( 'ABSPATH' ) || exit;

/**
 * What a complete VAT number looks like in each member state (without the country prefix, letters
 * upper case, spaces and punctuation removed). The checkout script uses the same table to wait for
 * a complete number, and the server refuses a malformed one before VIES is asked. Filter
 * `beltoft_eu_vat_formats` can change a pattern or add a country.
 */
final class VatFormats {

	/**
	 * Pattern and an example number for each country (ISO code, GR for Greece, XI for Northern Ireland).
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function all(): array {
		$formats = array(
			'AT' => array( 'U\d{8}', 'U12345678' ),
			'BE' => array( '[01]\d{9}', '0123456789' ),
			'BG' => array( '\d{9,10}', '123456789' ),
			'CY' => array( '\d{8}[A-Z]', '12345678X' ),
			'CZ' => array( '\d{8,10}', '12345678' ),
			'DE' => array( '\d{9}', '123456789' ),
			'DK' => array( '\d{8}', '12345678' ),
			'EE' => array( '\d{9}', '123456789' ),
			'ES' => array( '[A-Z0-9]\d{7}[A-Z0-9]', 'X1234567X' ),
			'FI' => array( '\d{8}', '12345678' ),
			'FR' => array( '[A-Z0-9]{2}\d{9}', 'AB123456789' ),
			'GR' => array( '\d{9}', '123456789' ),
			'HR' => array( '\d{11}', '12345678901' ),
			'HU' => array( '\d{8}', '12345678' ),
			'IE' => array( '\d{7}[A-Z]{1,2}|\d[A-Z0-9]\d{5}[A-Z]', '1234567X' ),
			'IT' => array( '\d{11}', '12345678901' ),
			'LT' => array( '\d{9}|\d{12}', '123456789' ),
			'LU' => array( '\d{8}', '12345678' ),
			'LV' => array( '\d{11}', '12345678901' ),
			'MT' => array( '\d{8}', '12345678' ),
			'NL' => array( '\d{9}B\d{2}', '123456789B01' ),
			'PL' => array( '\d{10}', '1234567890' ),
			'PT' => array( '\d{9}', '123456789' ),
			'RO' => array( '\d{2,10}', '1234567890' ),
			'SE' => array( '\d{12}', '123456789012' ),
			'SI' => array( '\d{8}', '12345678' ),
			'SK' => array( '\d{10}', '1234567890' ),
			'XI' => array( '\d{9}|\d{12}|(GD|HA)\d{3}', '123456789' ),
		);

		/**
		 * Filters the VAT number formats.
		 *
		 * @param array $formats Country => array( pattern without delimiters, example number ).
		 */
		$filtered = apply_filters( 'beltoft_eu_vat_formats', $formats );

		return is_array( $filtered ) ? $filtered : $formats;
	}

	/**
	 * Whether a number is complete for its country. Null when no format is known.
	 *
	 * @param string $country ISO code.
	 * @param string $number  Number without prefix, normalised.
	 */
	public static function matches( string $country, string $number ): ?bool {
		$formats = self::all();
		$country = strtoupper( $country );

		if ( ! isset( $formats[ $country ][0] ) ) {
			return null;
		}

		return 1 === preg_match( '/^(?:' . str_replace( '/', '\/', (string) $formats[ $country ][0] ) . ')$/', $number );
	}

	/**
	 * Formats for the script, keyed by country: the pattern, the prefix VIES uses, the adjective and an example.
	 *
	 * @return array<string,array<string,string>>
	 */
	public static function for_script(): array {
		$out = array();

		foreach ( self::all() as $country => $format ) {
			$out[ $country ] = array(
				'pattern' => (string) $format[0],
				'prefix'  => 'GR' === $country ? 'EL' : $country,
				'name'    => Countries::adjective( $country ),
				'example' => (string) ( $format[1] ?? '' ),
			);
		}

		return $out;
	}
}
