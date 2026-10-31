<?php
/**
 * The VAT number in My Account.
 *
 * @package BeltoftEuVat
 */

namespace BeltoftEuVat;

defined( 'ABSPATH' ) || exit;

/**
 * The field is part of the billing address form (see Field), so the customer edits it there.
 * This class applies the same rules as checkout when the address is saved.
 */
final class Account {

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_filter( 'woocommerce_process_myaccount_field_' . Field::KEY, array( __CLASS__, 'normalise' ) );
		add_action( 'woocommerce_after_save_address_validation', array( __CLASS__, 'validate' ), 10, 4 );
		add_action( 'woocommerce_customer_save_address', array( __CLASS__, 'store' ), 10, 4 );
	}

	/**
	 * Stores the number without spaces or punctuation.
	 *
	 * @param mixed $value Submitted value.
	 */
	public static function normalise( $value ): string {
		return Settings::normalise( is_string( $value ) ? $value : '' );
	}

	/**
	 * Refuses a number that checkout would refuse.
	 *
	 * @param int    $user_id User id.
	 * @param string $type    billing or shipping.
	 * @param array  $address Address fields.
	 * @param mixed  $customer Customer.
	 */
	public static function validate( $user_id, $type, $address = array(), $customer = null ): void {
		if ( 'billing' !== $type || ! $customer instanceof \WC_Customer ) {
			return;
		}

		$number = (string) $customer->get_meta( Field::KEY );

		if ( '' === $number ) {
			return;
		}

		$decision = Decisions::get( $number, (string) $customer->get_billing_country(), true );
		$result   = AccountUpdate::from_decision( $decision, $number );

		if ( AccountUpdate::REFUSED === $result['outcome'] ) {
			wc_add_notice( AccountUpdate::refusal_message( $result['reason'] ), 'error', array( 'id' => Field::KEY ) );
		}
	}

	/**
	 * Saves the proof with the number.
	 *
	 * @param int    $user_id User id.
	 * @param string $type    billing or shipping.
	 * @param array  $address Address fields.
	 * @param mixed  $customer Customer.
	 */
	public static function store( $user_id, $type, $address = array(), $customer = null ): void {
		if ( 'billing' !== $type || ! $customer instanceof \WC_Customer ) {
			return;
		}

		$number   = (string) $customer->get_meta( Field::KEY );
		$decision = '' === $number ? new VatDecision() : Decisions::get( $number, (string) $customer->get_billing_country(), true );

		Evidence::store( (int) $user_id, $number, $decision );
	}
}
