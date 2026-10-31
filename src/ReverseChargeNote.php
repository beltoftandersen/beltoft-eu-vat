<?php
/**
 * The reverse charge statement on orders.
 *
 * @package BeltoftEuVat
 */

namespace BeltoftEuVat;

defined( 'ABSPATH' ) || exit;

/**
 * Shown after the order table in emails (HTML and plain), on the order received page and in the
 * My Account order view, for orders that are VAT exempt through this plugin.
 */
final class ReverseChargeNote {

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'woocommerce_email_after_order_table', array( __CLASS__, 'email' ), 10, 4 );
		add_action( 'woocommerce_order_details_after_order_table', array( __CLASS__, 'page' ) );
	}

	/**
	 * Whether the order was reverse charged by this plugin: a VIES-valid number and
	 * WooCommerce's own exempt flag.
	 *
	 * @param mixed $order Order.
	 */
	public static function applies( $order ): bool {
		return $order instanceof \WC_Order
			&& 'valid' === $order->get_meta( OrderEvidence::STATUS )
			&& 'yes' === $order->get_meta( 'is_vat_exempt' );
	}

	/**
	 * The statement.
	 */
	public static function text(): string {
		$text = __( 'Reverse charge: VAT is to be accounted for by the customer (Article 196, Council Directive 2006/112/EC).', 'beltoft-eu-vat' );

		/**
		 * Filters the reverse charge statement on orders.
		 *
		 * @param string $text Text.
		 */
		return (string) apply_filters( 'beltoft_eu_vat_reverse_charge_text', $text );
	}

	/**
	 * Email, after the order table.
	 *
	 * @param mixed $order         Order.
	 * @param mixed $sent_to_admin Whether the mail is for the admin.
	 * @param mixed $plain_text    Plain text mail.
	 */
	public static function email( $order, $sent_to_admin = false, $plain_text = false ): void {
		if ( ! self::applies( $order ) || '' === self::text() ) {
			return;
		}

		if ( $plain_text ) {
			echo "\n" . esc_html( wp_strip_all_tags( self::text() ) ) . "\n";

			return;
		}

		echo '<p class="beltoft-eu-vat-reverse-charge">' . esc_html( self::text() ) . '</p>';
	}

	/**
	 * Order received page and My Account order view.
	 *
	 * @param mixed $order Order.
	 */
	public static function page( $order ): void {
		if ( ! self::applies( $order ) || '' === self::text() ) {
			return;
		}

		echo '<p class="beltoft-eu-vat-reverse-charge">' . esc_html( self::text() ) . '</p>';
	}
}
