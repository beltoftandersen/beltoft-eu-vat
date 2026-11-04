<?php
/**
 * The VAT number as a real billing field.
 *
 * @package BeltoftEuVat
 */

namespace BeltoftEuVat;

defined( 'ABSPATH' ) || exit;

/**
 * Added through woocommerce_billing_fields, so it sits in the billing form at checkout and in
 * the billing address form of My Account, and WooCommerce stores it as user meta
 * billing_eu_vat_number and order meta _billing_eu_vat_number. Classic checkout only.
 */
final class Field {

	public const KEY     = 'billing_eu_vat_number';
	public const WRAPPER = 'billing_eu_vat_number_field';

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_filter( 'woocommerce_billing_fields', array( __CLASS__, 'fields' ), 20 );
		add_filter( 'woocommerce_checkout_get_value', array( __CLASS__, 'prefill' ), 10, 2 );
		add_filter( 'woocommerce_my_account_edit_address_field_value', array( __CLASS__, 'account_value' ), 10, 2 );
		add_filter( 'woocommerce_form_field', array( __CLASS__, 'append_message' ), 10, 2 );
		add_filter( 'woocommerce_admin_billing_fields', array( __CLASS__, 'admin_field' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	/**
	 * Adds the field after the company field.
	 *
	 * @param array $fields Billing fields.
	 * @return array
	 */
	public static function fields( $fields ) {
		if ( ! is_array( $fields ) ) {
			return $fields;
		}

		$priority = isset( $fields['billing_company']['priority'] ) ? (int) $fields['billing_company']['priority'] + 1 : 35;

		$fields[ self::KEY ] = array(
			'type'         => 'text',
			'label'        => Settings::label(),
			'required'     => false,
			'class'        => array( 'form-row-wide' ),
			'clear'        => true,
			'autocomplete' => 'off',
			'priority'     => $priority,
			'maxlength'    => 20,
		);

		return $fields;
	}

	/**
	 * Fills the checkout field from the account (old plugin's meta as a fallback).
	 *
	 * @param mixed  $value Value so far.
	 * @param string $input Field key.
	 * @return mixed
	 */
	public static function prefill( $value, $input ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Only checks whether a submission carries the field; nothing is read from it.
		if ( self::KEY !== $input || null !== $value || isset( $_POST[ self::KEY ] ) || ! is_user_logged_in() ) {
			return $value;
		}

		$stored = Evidence::stored_number( get_current_user_id() );

		return '' !== $stored ? $stored : $value;
	}

	/**
	 * Same fallback in the My Account billing form.
	 *
	 * @param mixed  $value Value so far.
	 * @param string $key   Field key.
	 * @return mixed
	 */
	public static function account_value( $value, $key ) {
		if ( self::KEY === $key && '' === (string) $value ) {
			return Evidence::stored_number( get_current_user_id() );
		}

		return $value;
	}

	/**
	 * Puts the result line inside the field's wrapper. The checkout refresh replaces it through
	 * a fragment (see Checkout).
	 *
	 * @param string $field Field markup.
	 * @param string $key   Field key.
	 * @return string
	 */
	public static function append_message( $field, $key ) {
		if ( self::KEY !== $key || ! is_string( $field ) ) {
			return $field;
		}

		$pos = strrpos( $field, '</p>' );

		if ( false === $pos ) {
			return $field;
		}

		return substr( $field, 0, $pos ) . self::status_html() . self::message_html( '', '' ) . self::stored_html() . substr( $field, $pos );
	}

	/**
	 * The result line.
	 *
	 * @param string $text Text, empty for none.
	 * @param string $type valid, info or error.
	 */
	public static function message_html( string $text, string $type ): string {
		return '<span class="beltoft-eu-vat-message' . ( '' !== $type ? ' is-' . esc_attr( $type ) : '' ) . '" role="status" aria-live="polite">' . esc_html( $text ) . '</span>';
	}

	/**
	 * The line the inline check writes into (see assets/checkout.js). Hides the refresh's own line while it has text (see assets/checkout.css).
	 */
	public static function status_html(): string {
		return '<span class="beltoft-eu-vat-status" role="status" aria-live="polite"></span>';
	}

	/**
	 * In My Account, what is stored for the customer: confirmed with date and reference, or not confirmed.
	 */
	public static function stored_html(): string {
		if ( ! self::is_account_form() || ! is_user_logged_in() ) {
			return '';
		}

		$user_id = get_current_user_id();

		if ( '' === Evidence::stored_number( $user_id ) ) {
			return '';
		}

		return '<span class="beltoft-eu-vat-stored">' . esc_html( self::stored_text( $user_id ) ) . '</span>';
	}

	/**
	 * The stored status of an account as text.
	 *
	 * @param int $user_id User id.
	 */
	public static function stored_text( int $user_id ): string {
		$status = (string) get_user_meta( $user_id, Evidence::STATUS, true );
		$when   = strtotime( (string) get_user_meta( $user_id, Evidence::CHECKED_AT, true ) );

		if ( ! in_array( $status, array( 'valid', 'domestic' ), true ) || ! $when || '' !== (string) get_user_meta( $user_id, Evidence::FLAG, true ) ) {
			return __( 'Not confirmed', 'beltoft-eu-vat' );
		}

		$reference = (string) get_user_meta( $user_id, Evidence::REQUEST_ID, true );

		if ( '' === $reference ) {
			/* translators: %s: date, for example 4 Nov 2026 */
			return sprintf( __( 'Confirmed on %s', 'beltoft-eu-vat' ), Messages::date( $when ) );
		}

		/* translators: 1: date, for example 4 Nov 2026, 2: VIES reference number */
		return sprintf( __( 'Confirmed on %1$s, reference %2$s', 'beltoft-eu-vat' ), Messages::date( $when ), $reference );
	}

	/**
	 * Whether this is the My Account address form.
	 */
	private static function is_account_form(): bool {
		return function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'edit-address' );
	}

	/**
	 * Shows the number on the admin order screen.
	 *
	 * @param array $fields Admin billing fields.
	 * @return array
	 */
	public static function admin_field( $fields ) {
		if ( is_array( $fields ) ) {
			$fields['eu_vat_number'] = array(
				'label' => Settings::label(),
				'show'  => true,
			);
		}

		return $fields;
	}

	/**
	 * Script and style, on the classic checkout and the My Account address form.
	 */
	public static function assets(): void {
		$checkout = function_exists( 'is_checkout' ) && is_checkout() && ! is_order_received_page();

		if ( ! $checkout && ! self::is_account_form() ) {
			return;
		}

		wp_enqueue_style( 'beltoft-eu-vat', BELTOFT_EU_VAT_URL . 'assets/checkout.css', array(), BELTOFT_EU_VAT_VERSION );
		wp_enqueue_script( 'beltoft-eu-vat', BELTOFT_EU_VAT_URL . 'assets/checkout.js', $checkout ? array( 'jquery', 'wc-checkout' ) : array( 'jquery' ), BELTOFT_EU_VAT_VERSION, true );
		wp_localize_script(
			'beltoft-eu-vat',
			'beltoftEuVat',
			array_merge(
				Inline::config(),
				array(
					'countries' => array_merge( Countries::eu(), array( 'GB' ) ),
					'toggle'    => (bool) apply_filters( 'beltoft_eu_vat_toggle_field', true ),
					'context'   => $checkout ? 'checkout' : 'account',
				)
			)
		);
	}
}
