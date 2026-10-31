<?php
/**
 * Evidence written to the order.
 *
 * @package BeltoftEuVat
 */

namespace BeltoftEuVat;

defined( 'ABSPATH' ) || exit;

/**
 * The number itself is the order meta _billing_eu_vat_number that WooCommerce saves with the
 * billing fields, and is_vat_exempt is WooCommerce's own flag. The consultation evidence is
 * stored under _beltoft_eu_vat_* keys.
 */
final class OrderEvidence {

	public const STATUS     = '_beltoft_eu_vat_status';
	public const REQUEST_ID = '_beltoft_eu_vat_request_id';
	public const NAME       = '_beltoft_eu_vat_name';
	public const CHECKED_AT = '_beltoft_eu_vat_checked_at';
	public const COUNTRY    = '_beltoft_eu_vat_country';
	public const REVIEW     = '_beltoft_eu_vat_review';

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'attach' ), 10, 2 );
	}

	/**
	 * Records the decision on the order being created. From what the checkout already learnt,
	 * not a second VIES call.
	 *
	 * @param mixed $order Order.
	 * @param mixed $data  Posted checkout data.
	 */
	public static function attach( $order, $data ): void {
		if ( ! $order instanceof \WC_Order || ! is_array( $data ) ) {
			return;
		}

		$number = Settings::normalise( (string) ( $data[ Field::KEY ] ?? '' ) );

		if ( '' === $number ) {
			return;
		}

		self::write( $order, Decisions::get( $number, (string) ( $data['billing_country'] ?? '' ), false ) );
	}

	/**
	 * Writes the evidence for a decision, and the review flag for an unverified number.
	 *
	 * @param \WC_Order   $order    Order.
	 * @param VatDecision $decision Decision.
	 */
	public static function write( \WC_Order $order, VatDecision $decision ): void {
		if ( '' === $decision->status || 'ignored' === $decision->status ) {
			return;
		}

		$consulted = $decision->consulted();

		$order->update_meta_data( self::STATUS, $decision->status );
		$order->update_meta_data( self::COUNTRY, $decision->country );
		$order->update_meta_data( self::REQUEST_ID, $consulted ? $decision->request_identifier : '' );
		$order->update_meta_data( self::NAME, $consulted ? $decision->registered_name : '' );
		$order->update_meta_data( self::CHECKED_AT, $consulted && $decision->checked_at ? gmdate( 'c', $decision->checked_at ) : '' );

		if ( 'unverified' === $decision->status ) {
			$order->update_meta_data( self::REVIEW, 'yes' );
			$order->add_order_note(
				sprintf(
					/* translators: %s: detail from the VIES service */
					__( 'VAT number could not be verified: VIES did not answer (%s). VAT was charged and this order needs review.', 'beltoft-eu-vat' ),
					'' !== $decision->error ? $decision->error : __( 'no detail', 'beltoft-eu-vat' )
				)
			);
		}
	}
}
