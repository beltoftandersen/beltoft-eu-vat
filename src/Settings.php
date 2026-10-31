<?php
/**
 * Plugin settings, read from options.
 *
 * @package BeltoftEuVat
 */

namespace BeltoftEuVat;

defined( 'ABSPATH' ) || exit;

/**
 * Settings store. The values are edited under WooCommerce > Settings > Tax > EU VAT.
 */
final class Settings {

	public const SELLER_COUNTRY = 'beltoft_eu_vat_seller_country';
	public const OWN_NUMBER     = 'beltoft_eu_vat_own_number';
	public const LABEL          = 'beltoft_eu_vat_label';
	public const ON_UNREACHABLE = 'beltoft_eu_vat_on_unreachable';
	public const ADMIN_EMAIL    = 'beltoft_eu_vat_admin_email';

	public const FLAG  = 'flag';
	public const BLOCK = 'block';

	/**
	 * A VAT number as compared and stored: letters and digits only, upper case.
	 *
	 * @param string $number Raw number.
	 */
	public static function normalise( string $number ): string {
		return strtoupper( (string) preg_replace( '/[^A-Z0-9]/i', '', $number ) );
	}

	/**
	 * The seller's country: the setting, else the WooCommerce store base country.
	 */
	public static function seller_country(): string {
		$saved = strtoupper( trim( (string) get_option( self::SELLER_COUNTRY, '' ) ) );

		if ( '' === $saved && function_exists( 'WC' ) && WC()->countries ) {
			$saved = strtoupper( (string) WC()->countries->get_base_country() );
		}

		/**
		 * Filters the seller country.
		 *
		 * @param string $country ISO code.
		 */
		return strtoupper( (string) apply_filters( 'beltoft_eu_vat_seller_country', $saved ) );
	}

	/**
	 * The seller's own VAT number, sent to VIES as the requester.
	 */
	public static function own_number(): string {
		$number = self::normalise( (string) get_option( self::OWN_NUMBER, '' ) );

		/**
		 * Filters the seller's own VAT number used as the VIES requester.
		 *
		 * @param string $number Number with country prefix, no spaces.
		 */
		return self::normalise( (string) apply_filters( 'beltoft_eu_vat_own_number', $number ) );
	}

	/**
	 * The field label.
	 */
	public static function label(): string {
		$label = trim( (string) get_option( self::LABEL, '' ) );

		if ( '' === $label ) {
			$label = __( 'EU VAT number', 'beltoft-eu-vat' );
		}

		/**
		 * Filters the label of the checkout field.
		 *
		 * @param string $label Label.
		 */
		return (string) apply_filters( 'beltoft_eu_vat_label', $label );
	}

	/**
	 * What to do when VIES cannot answer: flag (charge VAT, review the order) or block.
	 */
	public static function on_unreachable(): string {
		return self::BLOCK === get_option( self::ON_UNREACHABLE, self::FLAG ) ? self::BLOCK : self::FLAG;
	}

	/**
	 * Where flagged-order mails go.
	 */
	public static function admin_email(): string {
		$email = sanitize_email( (string) get_option( self::ADMIN_EMAIL, '' ) );

		if ( '' === $email ) {
			$email = (string) get_option( 'admin_email' );
		}

		/**
		 * Filters the address that receives mails about orders needing review.
		 *
		 * @param string $email Address.
		 */
		return (string) apply_filters( 'beltoft_eu_vat_admin_email', $email );
	}
}
