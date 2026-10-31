<?php
/**
 * The decision table.
 *
 * @package BeltoftEuVat
 */

namespace BeltoftEuVat;

defined( 'ABSPATH' ) || exit;

/**
 * | Case                                   | Behaviour                                          |
 * |----------------------------------------|----------------------------------------------------|
 * | Field empty                            | Consumer sale, VAT charged                         |
 * | Billing country outside the EU         | Ignored, no VIES call (export is a tax-rate matter)|
 * | Number of the seller's own country     | Domestic, no VIES call, no exemption, VAT charged  |
 * | Other EU number, billing elsewhere     | Checkout blocked: reverse charge belongs to the    |
 * |                                        | number's own country                               |
 * | Other EU number, VIES valid            | Reverse charge (exempt), evidence stored           |
 * | Other EU number, VIES invalid          | Checkout blocked                                   |
 * | VIES unreachable                       | Unverified: VAT charged, flagged (or blocked by    |
 * |                                        | setting)                                           |
 */
class Validator {

	private const GREECE_VAT_PREFIX = 'EL';

	/**
	 * Whether a billing country makes the field irrelevant: a known country outside the EU.
	 * Great Britain counts as EU only for an XI number (Northern Ireland).
	 *
	 * @param string $billing_country Billing country.
	 * @param string $number          Normalised number.
	 */
	public static function is_ignored_country( string $billing_country, string $number = '' ): bool {
		$billing = strtoupper( trim( $billing_country ) );

		if ( '' === $billing || Countries::is_eu( $billing ) ) {
			return false;
		}

		return ! ( 'GB' === $billing && 0 === strpos( $number, 'XI' ) );
	}

	/**
	 * Decides.
	 *
	 * @param string $vat_number      As typed.
	 * @param string $billing_country Billing country.
	 * @param bool   $allow_remote    False answers from the cache only.
	 */
	public static function decide( string $vat_number, string $billing_country, bool $allow_remote = true ): VatDecision {
		$raw      = Settings::normalise( $vat_number );
		$decision = new VatDecision( '', $raw );

		if ( '' === $raw ) {
			return $decision;
		}

		if ( self::is_ignored_country( $billing_country, $raw ) ) {
			$decision->status = 'ignored';

			return $decision;
		}

		$country = self::country_of( $raw, $billing_country );
		$number  = self::number_of( $raw, $country );

		$decision->country = $country;

		if ( ! Countries::is_eu( $country ) ) {
			return $decision;
		}

		if ( Settings::seller_country() === $country ) {
			$decision->status = 'domestic';

			if ( false === LocalChecks::run( $country, $number, $raw ) ) {
				$decision->status          = 'invalid_format';
				$decision->blocks_checkout = true;
			}

			return $decision;
		}

		if ( self::billing_country_of( $billing_country, $country ) !== $country ) {
			$decision->status          = 'country_mismatch';
			$decision->blocks_checkout = true;

			return $decision;
		}

		if ( false === LocalChecks::run( $country, $number, $raw ) ) {
			$decision->status          = 'invalid_format';
			$decision->blocks_checkout = true;

			return $decision;
		}

		$result = ViesClient::check( self::vies_code( $country ), $number, $allow_remote );

		$decision->request_identifier = $result->request_identifier;
		$decision->registered_name    = $result->registered_name;
		$decision->checked_at         = $result->checked_at;

		if ( $result->is_valid() ) {
			$decision->status = 'valid';
			$decision->exempt = true;

			return $decision;
		}

		if ( $result->is_unreachable() ) {
			$decision->status = 'unverified';
			$decision->error  = $result->error;

			return $decision;
		}

		$decision->status          = 'invalid';
		$decision->blocks_checkout = true;

		return $decision;
	}

	/**
	 * The country a number belongs to: its prefix if it has an EU one (EL is Greece), else the
	 * billing country.
	 *
	 * @param string $raw             Normalised number.
	 * @param string $billing_country Billing country.
	 */
	private static function country_of( string $raw, string $billing_country ): string {
		$prefix = substr( $raw, 0, 2 );

		if ( self::GREECE_VAT_PREFIX === $prefix ) {
			return 'GR';
		}

		if ( Countries::is_eu( $prefix ) ) {
			return $prefix;
		}

		return strtoupper( substr( $billing_country, 0, 2 ) );
	}

	/**
	 * WooCommerce has no Northern Ireland, so an XI number is billed in GB.
	 *
	 * @param string $billing_country Billing country.
	 * @param string $number_country  The number's country.
	 */
	private static function billing_country_of( string $billing_country, string $number_country ): string {
		$billing = strtoupper( trim( $billing_country ) );

		return ( 'XI' === $number_country && 'GB' === $billing ) ? 'XI' : $billing;
	}

	/**
	 * The number without its country prefix.
	 *
	 * @param string $raw     Normalised number.
	 * @param string $country The number's country.
	 */
	private static function number_of( string $raw, string $country ): string {
		$prefixes = 'GR' === $country ? array( 'GR', self::GREECE_VAT_PREFIX ) : array( $country );

		foreach ( $prefixes as $prefix ) {
			if ( 0 === strpos( $raw, $prefix ) ) {
				return substr( $raw, 2 );
			}
		}

		return $raw;
	}

	/**
	 * The member state code VIES knows: EL for Greece.
	 *
	 * @param string $country ISO code.
	 */
	private static function vies_code( string $country ): string {
		return 'GR' === $country ? self::GREECE_VAT_PREFIX : $country;
	}
}
