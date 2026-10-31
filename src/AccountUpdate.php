<?php
/**
 * Whether a number typed into the account gets stored.
 *
 * @package BeltoftEuVat
 */

namespace BeltoftEuVat;

defined( 'ABSPATH' ) || exit;

/**
 * Pure function of a decision Validator already made.
 *
 * | Decision                            | Stored? |
 * |-------------------------------------|---------|
 * | Empty submission                    | yes, clears the number |
 * | Nothing readable in it              | NO      |
 * | Failed local check (invalid_format) | NO      |
 * | VIES invalid                        | NO      |
 * | Number of another country than the  | NO      |
 * | billing address                     |         |
 * | Domestic, VIES valid                | yes     |
 * | VIES unreachable                    | yes (an outage is "we do not know") |
 * | Billing outside the EU (ignored)    | yes     |
 *
 * At checkout an invalid number blocks the order; here it only refuses the save.
 */
final class AccountUpdate {

	public const STORED  = 'stored';
	public const CLEARED = 'cleared';
	public const REFUSED = 'refused';

	/**
	 * Applies the table.
	 *
	 * @param VatDecision $decision  What Validator made of the submission.
	 * @param string      $submitted The raw string typed.
	 * @return array{outcome:string,status:string,reason:string}
	 */
	public static function from_decision( VatDecision $decision, string $submitted ): array {
		if ( '' === trim( $submitted ) ) {
			return array(
				'outcome' => self::CLEARED,
				'status'  => '',
				'reason'  => '',
			);
		}

		if ( '' === $decision->vat_number ) {
			return self::refused( $decision, 'unreadable' );
		}

		if ( in_array( $decision->status, array( 'invalid_format', 'invalid', 'country_mismatch' ), true ) ) {
			return self::refused( $decision, $decision->status );
		}

		return array(
			'outcome' => self::STORED,
			'status'  => 'ignored' === $decision->status ? '' : $decision->status,
			'reason'  => '',
		);
	}

	/**
	 * The notice text for a refusal.
	 *
	 * @param string $reason Reason key from from_decision().
	 */
	public static function refusal_message( string $reason ): string {
		switch ( $reason ) {
			case 'unreadable':
				return __( 'No VAT number could be read from what you typed.', 'beltoft-eu-vat' );
			case 'country_mismatch':
				return __( 'This VAT number is from a different country than your billing address. Change the billing country to the VAT number\'s country, or leave the field empty.', 'beltoft-eu-vat' );
			case 'invalid_format':
				return __( 'The VAT number you entered is not valid. Please correct it or leave the field empty.', 'beltoft-eu-vat' );
			default:
				return __( 'The VAT number you entered could not be validated. Please correct it or leave the field empty.', 'beltoft-eu-vat' );
		}
	}

	/**
	 * Builds a refusal.
	 *
	 * @param VatDecision $decision Decision.
	 * @param string      $reason   Reason key.
	 * @return array{outcome:string,status:string,reason:string}
	 */
	private static function refused( VatDecision $decision, string $reason ): array {
		return array(
			'outcome' => self::REFUSED,
			'status'  => $decision->status,
			'reason'  => $reason,
		);
	}
}
