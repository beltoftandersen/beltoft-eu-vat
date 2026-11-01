<?php
/**
 * Applies reverse charge while the totals are worked out.
 *
 * @package BeltoftEuVat
 */

namespace BeltoftEuVat;

defined( 'ABSPATH' ) || exit;

/**
 * The exemption must be set before any line is totalled. Setting it on
 * woocommerce_checkout_create_order is too late: WooCommerce has already copied the tax lines,
 * the total and is_vat_exempt to the order by then. woocommerce_before_calculate_totals is the
 * seam every price path goes through.
 *
 * Fail closed: exemption needs a definitive VIES valid. An unreachable service, an unchecked
 * number or a number for goods that stay in (or leave) the seller's country charge VAT. The
 * cart never calls VIES; the checkout refresh and submission do, and the answer is reused.
 */
final class Exemption {

	private const PRIORITY = 5;
	private const SESSION  = 'beltoft_eu_vat_exempt';

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'apply' ), self::PRIORITY );
	}

	/**
	 * Sets the customer's exempt flag. A flag some other code set is left alone: only a flag
	 * this plugin set is switched off again.
	 */
	public static function apply(): void {
		if ( ! function_exists( 'WC' ) || ! WC()->customer ) {
			return;
		}

		$exempt = self::is_exempt();
		$ours   = WC()->session ? (bool) WC()->session->get( self::SESSION, false ) : false;

		if ( $exempt ) {
			WC()->customer->set_is_vat_exempt( true );

			if ( WC()->session ) {
				WC()->session->set( self::SESSION, true );
			}

			return;
		}

		if ( $ours ) {
			WC()->customer->set_is_vat_exempt( false );

			if ( WC()->session ) {
				WC()->session->set( self::SESSION, false );
			}
		}
	}

	/**
	 * Whether this plugin switched the session's exempt flag on.
	 */
	public static function set_by_us(): bool {
		return function_exists( 'WC' ) && WC()->session && (bool) WC()->session->get( self::SESSION, false );
	}

	/**
	 * Whether the current cart is reverse charged.
	 */
	public static function is_exempt(): bool {
		$number = self::number();

		if ( '' === $number ) {
			return false;
		}

		$decision = Decisions::get( $number, self::billing_country(), false );

		if ( ! $decision->exempt ) {
			return false;
		}

		$destination = self::destination();

		return Countries::is_eu( $destination ) && Settings::seller_country() !== $destination;
	}

	/**
	 * The number the totals are worked out with: the checkout submission, the checkout refresh,
	 * else the account's (not in a checkout request). A field posted empty is the answer, not a gap to fill from the account.
	 */
	public static function number(): string {
		$posted = Checkout::posted();

		if ( null !== $posted ) {
			return $posted['number'];
		}

		// A checkout request without the field has no evidence to attach to the order.
		if ( Checkout::in_checkout_request() ) {
			return '';
		}

		return function_exists( 'WC' ) && WC()->customer && WC()->customer->get_id() ? Evidence::stored_number( (int) WC()->customer->get_id() ) : '';
	}

	/**
	 * Billing country of the request.
	 */
	public static function billing_country(): string {
		$posted = Checkout::posted();

		if ( null !== $posted && '' !== $posted['billing_country'] ) {
			return $posted['billing_country'];
		}

		return function_exists( 'WC' ) && WC()->customer ? (string) WC()->customer->get_billing_country() : '';
	}

	/**
	 * Where the goods or services go: the shipping country when something ships, else billing.
	 * Reverse charge needs a destination in another EU country than the seller's.
	 */
	public static function destination(): string {
		$posted      = Checkout::posted();
		$needs_ships = function_exists( 'WC' ) && WC()->cart && WC()->cart->needs_shipping();
		$customer    = function_exists( 'WC' ) ? WC()->customer : null;
		$country     = self::billing_country();
		$postcode    = $customer ? (string) $customer->get_billing_postcode() : '';

		if ( $needs_ships ) {
			if ( null !== $posted ) {
				if ( $posted['ship_different'] && '' !== $posted['shipping_country'] ) {
					$country  = $posted['shipping_country'];
					$postcode = $posted['shipping_postcode'];
				}
			} elseif ( $customer && '' !== (string) $customer->get_shipping_country() ) {
				$country  = (string) $customer->get_shipping_country();
				$postcode = (string) $customer->get_shipping_postcode();
			}
		}

		$country = strtoupper( $country );

		// WooCommerce has no Northern Ireland: a GB address with a BT postcode is.
		if ( 'GB' === $country && 0 === strpos( strtoupper( trim( $postcode ) ), 'BT' ) ) {
			$country = 'XI';
		}

		/**
		 * Filters the country the sale is delivered to, for the reverse charge rule.
		 *
		 * @param string $country ISO code (XI for Northern Ireland).
		 */
		return strtoupper( (string) apply_filters( 'beltoft_eu_vat_destination', $country ) );
	}
}
