<?php
/**
 * Customer-facing wording.
 *
 * @package BeltoftEuVat
 */

namespace BeltoftEuVat;

defined( 'ABSPATH' ) || exit;

/**
 * Plain-words texts for a decision.
 */
final class Messages {

	/**
	 * The error that blocks a checkout or an account save, or an empty string.
	 *
	 * @param VatDecision $decision Decision.
	 */
	public static function error( VatDecision $decision ): string {
		switch ( $decision->status ) {
			case 'country_mismatch':
				return __( 'This VAT number is from a different country than your billing address. Change the billing country to the VAT number\'s country, or leave the field empty.', 'beltoft-eu-vat' );
			case 'invalid_format':
				return __( 'The VAT number you entered is not valid. Please correct it or leave the field empty.', 'beltoft-eu-vat' );
			case 'invalid':
				return __( 'The VAT number you entered could not be validated. Please correct it or leave the field empty.', 'beltoft-eu-vat' );
			case 'unverified':
				if ( Settings::BLOCK === Settings::on_unreachable() ) {
					return __( 'The VAT number could not be checked right now. Please try again in a few minutes or leave the field empty.', 'beltoft-eu-vat' );
				}
				return '';
			default:
				return '';
		}
	}

	/**
	 * Whether the decision stops the checkout.
	 *
	 * @param VatDecision $decision Decision.
	 */
	public static function blocks( VatDecision $decision ): bool {
		return $decision->blocks_checkout || ( 'unverified' === $decision->status && Settings::BLOCK === Settings::on_unreachable() );
	}

	/**
	 * The result shown under the field after a refresh: array( text, type ) with type one of
	 * valid, info or error. Empty text means nothing to show.
	 *
	 * @param VatDecision $decision Decision.
	 * @return array{0:string,1:string}
	 */
	public static function for_field( VatDecision $decision ): array {
		switch ( $decision->status ) {
			case 'valid':
				return array( __( 'VAT number confirmed. VAT is not charged (reverse charge).', 'beltoft-eu-vat' ), 'valid' );
			case 'domestic':
				return array(
					sprintf(
						$decision->confirmed
							/* translators: %s: adjective for the seller's country, for example Danish */
							? __( 'VAT number confirmed. %s VAT is charged on domestic sales.', 'beltoft-eu-vat' )
							/* translators: %s: adjective for the seller's country, for example Danish */
							: __( '%s VAT is charged on domestic sales.', 'beltoft-eu-vat' ),
						Countries::adjective( Settings::seller_country() )
					),
					'info',
				);
			case 'unverified':
				$error = self::error( $decision );

				if ( '' !== $error ) {
					return array( $error, 'error' );
				}

				return array( __( 'The VAT number could not be checked right now. VAT is charged and the order will be reviewed.', 'beltoft-eu-vat' ), 'info' );
			case 'country_mismatch':
			case 'invalid':
			case 'invalid_format':
				return array( self::error( $decision ), 'error' );
			default:
				return array( '', '' );
		}
	}
}
