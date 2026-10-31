<?php
/**
 * Per-request memory of decisions.
 *
 * @package BeltoftEuVat
 */

namespace BeltoftEuVat;

defined( 'ABSPATH' ) || exit;

/**
 * VIES is asked at most once per number and country in a request. The checkout asks for real
 * (it is an AJAX round trip the customer waits on); totals, evidence and messages then reuse
 * that answer. Cart totals themselves never call VIES.
 */
final class Decisions {

	/**
	 * Remote answers by key.
	 *
	 * @var array<string,VatDecision>
	 */
	private static array $memo = array();

	/**
	 * The decision for a number.
	 *
	 * @param string $number       As typed.
	 * @param string $country      Billing country.
	 * @param bool   $allow_remote Whether VIES may be asked.
	 */
	public static function get( string $number, string $country, bool $allow_remote = false ): VatDecision {
		$key = Settings::normalise( $number ) . '|' . strtoupper( $country );

		if ( isset( self::$memo[ $key ] ) ) {
			return self::$memo[ $key ];
		}

		$decision = Validator::decide( $number, $country, $allow_remote );

		if ( $allow_remote ) {
			self::$memo[ $key ] = $decision;
		}

		return $decision;
	}

	/**
	 * Forgets everything. Used by tests.
	 */
	public static function reset(): void {
		self::$memo = array();
	}
}
