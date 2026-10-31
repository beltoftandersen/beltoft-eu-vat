<?php
/**
 * The VAT number as part of the formatted billing address.
 *
 * @package BeltoftEuVat
 */

namespace BeltoftEuVat;

defined( 'ABSPATH' ) || exit;

/**
 * Adds a {vat_number} line to every WooCommerce address format and fills it for billing
 * addresses of orders and of the customer. Everything that prints the formatted billing address
 * (emails, order received, My Account, admin order screen, most invoice plugins) shows it. A
 * shipping address, or a billing address without a number, gets an empty value and WooCommerce
 * drops the empty line.
 */
final class AddressFormat {

	public const PLACEHOLDER = '{vat_number}';

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_filter( 'woocommerce_localisation_address_formats', array( __CLASS__, 'formats' ) );
		add_filter( 'woocommerce_order_formatted_billing_address', array( __CLASS__, 'order_address' ), 10, 2 );
		add_filter( 'woocommerce_my_account_my_address_formatted_address', array( __CLASS__, 'account_address' ), 10, 3 );
		add_filter( 'woocommerce_formatted_address_replacements', array( __CLASS__, 'replacements' ), 10, 2 );
	}

	/**
	 * Appends the placeholder as a last line to each format, once.
	 *
	 * @param mixed $formats Formats by country code.
	 * @return mixed
	 */
	public static function formats( $formats ) {
		if ( ! is_array( $formats ) ) {
			return $formats;
		}

		foreach ( $formats as $country => $format ) {
			if ( is_string( $format ) && false === strpos( $format, self::PLACEHOLDER ) ) {
				$formats[ $country ] = rtrim( $format ) . "\n" . self::PLACEHOLDER;
			}
		}

		return $formats;
	}

	/**
	 * Puts the order's number in its billing address.
	 *
	 * @param mixed $address Address parts.
	 * @param mixed $order   Order.
	 * @return mixed
	 */
	public static function order_address( $address, $order = null ) {
		if ( ! is_array( $address ) || ! $order instanceof \WC_Order ) {
			return $address;
		}

		$address['vat_number'] = self::text( (string) $order->get_meta( '_' . Field::KEY ) );

		return $address;
	}

	/**
	 * Puts the customer's number in the billing address shown in My Account.
	 *
	 * @param mixed $address     Address parts.
	 * @param mixed $customer_id Customer id.
	 * @param mixed $type        billing or shipping.
	 * @return mixed
	 */
	public static function account_address( $address, $customer_id = 0, $type = '' ) {
		if ( ! is_array( $address ) || 'billing' !== $type ) {
			return $address;
		}

		$address['vat_number'] = self::text( (string) get_user_meta( (int) $customer_id, Field::KEY, true ) );

		return $address;
	}

	/**
	 * Fills the placeholder. WooCommerce escapes the values.
	 *
	 * @param mixed $replace Replacements.
	 * @param mixed $args    Address parts.
	 * @return mixed
	 */
	public static function replacements( $replace, $args = array() ) {
		if ( ! is_array( $replace ) ) {
			return $replace;
		}

		$replace[ self::PLACEHOLDER ] = is_array( $args ) && isset( $args['vat_number'] ) ? (string) $args['vat_number'] : '';

		return $replace;
	}

	/**
	 * The line for a number, for example "VAT DK40817921", or an empty string.
	 *
	 * @param string $number Stored number.
	 */
	public static function text( string $number ): string {
		$number = Settings::normalise( $number );

		if ( '' === $number ) {
			return '';
		}

		/**
		 * Filters the label in front of the VAT number in addresses.
		 *
		 * @param string $label Label.
		 */
		$label = trim( (string) apply_filters( 'beltoft_eu_vat_address_label', __( 'VAT', 'beltoft-eu-vat' ) ) );

		return trim( $label . ' ' . $number );
	}
}
