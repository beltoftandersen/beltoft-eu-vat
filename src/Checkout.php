<?php
/**
 * The classic checkout flow.
 *
 * @package BeltoftEuVat
 */

namespace BeltoftEuVat;

defined( 'ABSPATH' ) || exit;

/**
 * The order summary refreshes through WooCommerce's own update_order_review request, and that
 * refresh never calls VIES: it decides from the cache (a valid answer lives 72 hours) and, with
 * nothing cached, charges VAT with a pending note. The customer's number is checked by the
 * inline request (Inline) right after typing, which fills the cache before the one refresh the
 * script then asks for. VIES is asked here only when the order is placed with nothing cached
 * (woocommerce_checkout_process, then woocommerce_after_checkout_validation reuses that answer
 * to block). The result line is returned in the order review fragments.
 */
final class Checkout {

	/**
	 * Data from the last refresh request.
	 *
	 * @var array<string,mixed>|null
	 */
	private static ?array $review = null;

	/**
	 * Whether this request is a checkout refresh or submission (set by their hooks).
	 *
	 * @var bool
	 */
	private static bool $in_checkout = false;

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'woocommerce_checkout_update_order_review', array( __CLASS__, 'on_review' ), 5 );
		add_filter( 'woocommerce_update_order_review_fragments', array( __CLASS__, 'fragments' ) );
		add_action( 'woocommerce_checkout_process', array( __CLASS__, 'on_process' ), 5 );
		add_filter( 'woocommerce_checkout_posted_data', array( __CLASS__, 'posted_data' ) );
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validate' ), 10, 2 );
		add_action( 'woocommerce_checkout_update_customer', array( __CLASS__, 'save_evidence' ), 10, 2 );
	}

	/**
	 * What this request posted: the submission, else the last refresh, else null.
	 *
	 * @return array{number:string,billing_country:string,shipping_country:string,shipping_postcode:string,ship_different:bool,company:string,first_name:string,last_name:string}|null
	 */
	public static function posted(): ?array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the checkout nonce before the checkout hooks run; nothing here changes state.
		if ( isset( $_POST[ Field::KEY ] ) ) {
			return self::extract( wp_unslash( $_POST ) );
		}
		// phpcs:enable

		return self::$review;
	}

	/**
	 * Whether this request is a checkout refresh or submission. There the field is the only
	 * source of the number: an absent field never falls back to the account's number.
	 */
	public static function in_checkout_request(): bool {
		return self::$in_checkout;
	}

	/**
	 * Picks and cleans the relevant values.
	 *
	 * @param array $fields Posted fields.
	 * @return array{number:string,billing_country:string,shipping_country:string,shipping_postcode:string,ship_different:bool,company:string,first_name:string,last_name:string}
	 */
	private static function extract( array $fields ): array {
		$text = static function ( $key ) use ( $fields ): string {
			return isset( $fields[ $key ] ) && is_string( $fields[ $key ] ) ? sanitize_text_field( $fields[ $key ] ) : '';
		};

		return array(
			'number'            => Settings::normalise( $text( Field::KEY ) ),
			'billing_country'   => strtoupper( $text( 'billing_country' ) ),
			'shipping_country'  => strtoupper( $text( 'shipping_country' ) ),
			'shipping_postcode' => $text( 'shipping_postcode' ),
			'ship_different'    => ! empty( $fields['ship_to_different_address'] ),
			'company'           => $text( 'billing_company' ),
			'first_name'        => $text( 'billing_first_name' ),
			'last_name'         => $text( 'billing_last_name' ),
		);
	}

	/**
	 * The checkout refresh: reads the cache only, never VIES, and sets the exemption before
	 * WooCommerce works out the totals.
	 *
	 * @param mixed $post_data The form, url-encoded.
	 */
	public static function on_review( $post_data ): void {
		if ( ! is_string( $post_data ) ) {
			return;
		}

		self::$in_checkout = true;

		$fields = array();
		parse_str( $post_data, $fields );

		if ( ! array_key_exists( Field::KEY, $fields ) ) {
			Exemption::apply();

			return;
		}

		self::$review = self::extract( $fields );

		Exemption::apply();
	}

	/**
	 * Adds the result line to the refreshed fragments.
	 *
	 * @param mixed $fragments Fragments by selector.
	 * @return mixed
	 */
	public static function fragments( $fragments ) {
		if ( ! is_array( $fragments ) || null === self::$review ) {
			return $fragments;
		}

		$decision = self::$review['number'] ? Decisions::get( self::$review['number'], self::$review['billing_country'], false ) : new VatDecision();
		list( $text, $type ) = Messages::for_field( $decision );

		// A refresh can come while the customer is still typing the number (another field changed): an incomplete or
		// malformed number says nothing here. The field's own hint and the submission tell them.
		if ( 'invalid_format' === $decision->status ) {
			$text = '';
			$type = '';
		}

		// A valid number whose holder is not the company entered: VAT is charged and the customer is told why.
		$notice = Messages::name_notice( $decision, array(
			'company'    => self::$review['company'],
			'first_name' => self::$review['first_name'],
			'last_name'  => self::$review['last_name'],
		) );

		if ( '' !== $notice ) {
			$text = $notice;
			$type = 'info';
		}

		$fragments['.beltoft-eu-vat-message'] = Field::message_html( $text, $type );

		return $fragments;
	}

	/**
	 * The submission, before totals: asks VIES and sets the exemption.
	 */
	public static function on_process(): void {
		self::$in_checkout = true;

		$posted = self::posted();

		if ( null === $posted ) {
			return;
		}

		if ( '' !== $posted['number'] ) {
			Decisions::get( $posted['number'], $posted['billing_country'], true );
		}

		Exemption::apply();
	}

	/**
	 * A billing country outside the EU makes the field irrelevant: it is not saved.
	 *
	 * @param mixed $data Posted checkout data.
	 * @return mixed
	 */
	public static function posted_data( $data ) {
		if ( ! is_array( $data ) || ! array_key_exists( Field::KEY, $data ) ) {
			return $data;
		}

		$number = Settings::normalise( (string) $data[ Field::KEY ] );

		if ( Validator::is_ignored_country( (string) ( $data['billing_country'] ?? '' ), $number ) ) {
			$number = '';
		}

		$data[ Field::KEY ] = $number;

		return $data;
	}

	/**
	 * Blocks the checkout when the number cannot be accepted.
	 *
	 * @param array     $data   Posted data.
	 * @param \WP_Error $errors Validation errors.
	 */
	public static function validate( $data, $errors ): void {
		if ( ! is_array( $data ) || ! $errors instanceof \WP_Error ) {
			return;
		}

		$number = (string) ( $data[ Field::KEY ] ?? '' );

		if ( '' === $number ) {
			return;
		}

		$decision = Decisions::get( $number, (string) ( $data['billing_country'] ?? '' ), true );

		// The answer may have been unknown to the totals worked out earlier.
		if ( Messages::blocks( $decision ) ) {
			$errors->add( 'beltoft_eu_vat_number', Messages::error( $decision ), array( 'id' => Field::KEY ) );
		}
	}

	/**
	 * Keeps the account's proof in step with the number the checkout saves.
	 *
	 * @param mixed $customer Customer.
	 * @param mixed $data     Posted data.
	 */
	public static function save_evidence( $customer, $data ): void {
		if ( ! $customer instanceof \WC_Customer || ! $customer->get_id() || ! is_array( $data ) || ! array_key_exists( Field::KEY, $data ) ) {
			return;
		}

		$number   = Settings::normalise( (string) $data[ Field::KEY ] );
		$decision = '' === $number ? new VatDecision() : Decisions::get( $number, (string) ( $data['billing_country'] ?? '' ), false );

		Evidence::store( (int) $customer->get_id(), $number, $decision );
	}

	/**
	 * Forgets the refresh data. Used by tests.
	 */
	public static function reset(): void {
		self::$review      = null;
		self::$in_checkout = false;
	}
}
