<?php
/**
 * The VAT number on an account, and the proof that goes with it.
 *
 * @package BeltoftEuVat
 */

namespace BeltoftEuVat;

defined( 'ABSPATH' ) || exit;

/**
 * One writer, because the number and its proof must move together: a confirmation that stays
 * behind a changed number describes nothing. A date is written only when VIES was consulted
 * (a domestic or ignored number never reaches it).
 */
final class Evidence {

	public const STATUS     = '_beltoft_eu_vat_status';
	public const REQUEST_ID = '_beltoft_eu_vat_request_id';
	public const NAME       = '_beltoft_eu_vat_name';
	public const CHECKED_AT = '_beltoft_eu_vat_checked_at';

	/** User meta the previous plugin kept the number in. */
	public const LEGACY_USER_META = '_wc_other/alg_eu_vat/billing_eu_vat_number';

	/**
	 * The number stored on an account, with the old plugin's meta as a fallback.
	 *
	 * @param int $user_id User id.
	 */
	public static function stored_number( int $user_id ): string {
		if ( ! $user_id ) {
			return '';
		}

		$number = (string) get_user_meta( $user_id, Field::KEY, true );

		if ( '' === $number ) {
			$number = (string) get_user_meta( $user_id, self::LEGACY_USER_META, true );
		}

		return $number;
	}

	/**
	 * Writes the number and the proof for the decision.
	 *
	 * @param int         $user_id  User id.
	 * @param string      $number   Number to store (empty clears it).
	 * @param VatDecision $decision Decision made about it.
	 */
	public static function store( int $user_id, string $number, VatDecision $decision ): void {
		if ( ! $user_id ) {
			return;
		}

		$consulted = '' !== $number && $decision->consulted();

		update_user_meta( $user_id, Field::KEY, $number );
		update_user_meta( $user_id, self::STATUS, '' === $number ? '' : $decision->status );
		update_user_meta( $user_id, self::REQUEST_ID, $consulted ? $decision->request_identifier : '' );
		update_user_meta( $user_id, self::NAME, $consulted ? $decision->registered_name : '' );
		update_user_meta( $user_id, self::CHECKED_AT, $consulted && $decision->checked_at ? gmdate( 'c', $decision->checked_at ) : '' );
	}
}
