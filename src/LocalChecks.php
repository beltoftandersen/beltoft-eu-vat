<?php
/**
 * Per-country format or checksum checks.
 *
 * @package BeltoftEuVat
 */

namespace BeltoftEuVat;

defined( 'ABSPATH' ) || exit;

/**
 * The filter beltoft_eu_vat_local_check lets a site refuse a number before VIES is asked. The
 * callback receives null, the country (ISO, GR for Greece), the number without prefix and the
 * number as typed, and returns true (accept), false (refuse) or null (no opinion). Portugal's
 * NIF check digit is built in as an example.
 */
final class LocalChecks {

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_filter( 'beltoft_eu_vat_local_check', array( __CLASS__, 'portugal' ), 5, 3 );
	}

	/**
	 * Runs the filter.
	 *
	 * @param string $country Country code.
	 * @param string $number  Number without prefix.
	 * @param string $raw     Number as typed, normalised.
	 * @return bool|null
	 */
	public static function run( string $country, string $number, string $raw ) {
		$result = apply_filters( 'beltoft_eu_vat_local_check', null, $country, $number, $raw );

		return is_bool( $result ) ? $result : null;
	}

	/**
	 * Portuguese NIF check digit.
	 *
	 * @param mixed  $result  Earlier answer.
	 * @param string $country Country code.
	 * @param string $number  Number without prefix.
	 * @return mixed
	 */
	public static function portugal( $result, $country = '', $number = '' ) {
		if ( null !== $result || 'PT' !== $country ) {
			return $result;
		}

		$digits = preg_replace( '/\D/', '', (string) $number );

		if ( 9 !== strlen( (string) $digits ) ) {
			return false;
		}

		$sum = 0;

		for ( $i = 0; $i < 8; $i++ ) {
			$sum += (int) $digits[ $i ] * ( 9 - $i );
		}

		$check = 11 - ( $sum % 11 );

		if ( $check >= 10 ) {
			$check = 0;
		}

		return (int) $digits[8] === $check;
	}
}
