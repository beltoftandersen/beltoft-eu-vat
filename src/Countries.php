<?php
/**
 * EU country helpers.
 *
 * @package BeltoftEuVat
 */

namespace BeltoftEuVat;

defined( 'ABSPATH' ) || exit;

/**
 * Which countries count as EU for VAT on goods, and how to name them.
 */
final class Countries {

	/**
	 * EU member states (ISO codes, so Greece is GR) and XI, Northern Ireland, whose businesses
	 * buy goods under EU VAT rules and which VIES checks.
	 *
	 * @return string[]
	 */
	public static function eu(): array {
		return array(
			'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FR',
			'GR', 'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL',
			'PT', 'RO', 'SE', 'SI', 'SK', 'XI',
		);
	}

	/**
	 * Whether a country code is in the EU for VAT on goods.
	 *
	 * @param string $country ISO code (XI allowed).
	 */
	public static function is_eu( string $country ): bool {
		return in_array( strtoupper( $country ), self::eu(), true );
	}

	/**
	 * The country name as WooCommerce knows it.
	 *
	 * @param string $code ISO code.
	 */
	public static function name( string $code ): string {
		$code = strtoupper( $code );

		if ( 'XI' === $code ) {
			return __( 'Northern Ireland', 'beltoft-eu-vat' );
		}

		$all = function_exists( 'WC' ) && WC()->countries ? WC()->countries->get_countries() : array();

		return isset( $all[ $code ] ) ? (string) $all[ $code ] : $code;
	}

	/**
	 * Names for the settings drop-down, keyed by code.
	 *
	 * @return array<string,string>
	 */
	public static function options(): array {
		$options = array();

		foreach ( self::eu() as $code ) {
			if ( 'XI' !== $code ) {
				$options[ $code ] = self::name( $code );
			}
		}

		asort( $options );

		return $options;
	}

	/**
	 * The adjective used in "Danish VAT is charged on domestic sales." Falls back to the country
	 * name from WooCommerce for a code without one.
	 *
	 * @param string $code ISO code.
	 */
	public static function adjective( string $code ): string {
		$code = strtoupper( $code );
		$map  = array(
			'AT' => __( 'Austrian', 'beltoft-eu-vat' ),
			'BE' => __( 'Belgian', 'beltoft-eu-vat' ),
			'BG' => __( 'Bulgarian', 'beltoft-eu-vat' ),
			'CY' => __( 'Cypriot', 'beltoft-eu-vat' ),
			'CZ' => __( 'Czech', 'beltoft-eu-vat' ),
			'DE' => __( 'German', 'beltoft-eu-vat' ),
			'DK' => __( 'Danish', 'beltoft-eu-vat' ),
			'EE' => __( 'Estonian', 'beltoft-eu-vat' ),
			'ES' => __( 'Spanish', 'beltoft-eu-vat' ),
			'FI' => __( 'Finnish', 'beltoft-eu-vat' ),
			'FR' => __( 'French', 'beltoft-eu-vat' ),
			'GR' => __( 'Greek', 'beltoft-eu-vat' ),
			'HR' => __( 'Croatian', 'beltoft-eu-vat' ),
			'HU' => __( 'Hungarian', 'beltoft-eu-vat' ),
			'IE' => __( 'Irish', 'beltoft-eu-vat' ),
			'IT' => __( 'Italian', 'beltoft-eu-vat' ),
			'LT' => __( 'Lithuanian', 'beltoft-eu-vat' ),
			'LU' => __( 'Luxembourg', 'beltoft-eu-vat' ),
			'LV' => __( 'Latvian', 'beltoft-eu-vat' ),
			'MT' => __( 'Maltese', 'beltoft-eu-vat' ),
			'NL' => __( 'Dutch', 'beltoft-eu-vat' ),
			'PL' => __( 'Polish', 'beltoft-eu-vat' ),
			'PT' => __( 'Portuguese', 'beltoft-eu-vat' ),
			'RO' => __( 'Romanian', 'beltoft-eu-vat' ),
			'SE' => __( 'Swedish', 'beltoft-eu-vat' ),
			'SI' => __( 'Slovenian', 'beltoft-eu-vat' ),
			'SK' => __( 'Slovak', 'beltoft-eu-vat' ),
		);
		$text = $map[ $code ] ?? self::name( $code );

		/**
		 * Filters the adjective for the seller country in the domestic-sale message.
		 *
		 * @param string $text Adjective, or the country name.
		 * @param string $code ISO code.
		 */
		return (string) apply_filters( 'beltoft_eu_vat_domestic_adjective', $text, $code );
	}
}
