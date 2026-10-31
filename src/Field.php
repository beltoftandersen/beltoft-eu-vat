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

		return substr( $field, 0, $pos ) . self::message_html( '', '' ) . substr( $field, $pos );
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
	 * Script and style, on the classic checkout only.
	 */
	public static function assets(): void {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_order_received_page() ) {
			return;
		}

		wp_enqueue_style( 'beltoft-eu-vat', BELTOFT_EU_VAT_URL . 'assets/checkout.css', array(), BELTOFT_EU_VAT_VERSION );
		wp_enqueue_script( 'beltoft-eu-vat', BELTOFT_EU_VAT_URL . 'assets/checkout.js', array( 'jquery', 'wc-checkout' ), BELTOFT_EU_VAT_VERSION, true );
		wp_localize_script(
			'beltoft-eu-vat',
			'beltoftEuVat',
			array(
				'countries' => array_merge( Countries::eu(), array( 'GB' ) ),
				'toggle'    => (bool) apply_filters( 'beltoft_eu_vat_toggle_field', true ),
			)
		);
	}
}
