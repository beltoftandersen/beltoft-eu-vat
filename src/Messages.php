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
				if ( $decision->grace ) {
					return array( self::grace_text( $decision ), 'valid' );
				}

				return array( __( 'VAT number confirmed. VAT is not charged (reverse charge).', 'beltoft-eu-vat' ), 'valid' );
			case 'pending':
				return array( __( 'VAT is charged until your VAT number has been checked.', 'beltoft-eu-vat' ), 'info' );
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

	/**
	 * The grace wording: VIES is down and an earlier confirmation is used.
	 *
	 * @param VatDecision $decision A decision with grace set.
	 */
	public static function grace_text( VatDecision $decision ): string {
		return sprintf(
			/* translators: %s: date of the earlier confirmation, for example 4 Nov 2026 */
			__( 'The EU register is not answering right now. Your VAT number was confirmed on %s, so no VAT is charged (reverse charge).', 'beltoft-eu-vat' ),
			self::date( $decision->checked_at )
		);
	}

	/**
	 * A date as shown to customers, for example 4 Nov 2026.
	 *
	 * @param int $timestamp Unix time.
	 */
	public static function date( int $timestamp ): string {
		return wp_date( 'j M Y', $timestamp );
	}

	/**
	 * The line under the field after the inline check: array( text, state, retry ). State is
	 * valid, notfound, unreachable, info or error. Retry shows the "Check again" link.
	 *
	 * @param VatDecision $decision Decision made with VIES allowed.
	 * @return array{0:string,1:string,2:bool}
	 */
	public static function inline( VatDecision $decision ): array {
		switch ( $decision->status ) {
			case 'valid':
				if ( $decision->grace ) {
					return array( self::grace_text( $decision ), 'valid', false );
				}

				if ( '' !== $decision->registered_name ) {
					return array(
						sprintf(
							/* translators: %s: name VIES holds for the number */
							__( 'Confirmed: %s. No VAT is charged (reverse charge).', 'beltoft-eu-vat' ),
							$decision->registered_name
						),
						'valid',
						false,
					);
				}

				return array( __( 'Confirmed. No VAT is charged (reverse charge).', 'beltoft-eu-vat' ), 'valid', false );
			case 'invalid':
				return array( __( 'This VAT number was not found in the EU register. Check it, or remove it to continue with VAT charged.', 'beltoft-eu-vat' ), 'notfound', false );
			case 'unverified':
				if ( Settings::BLOCK === Settings::on_unreachable() ) {
					return array( self::error( $decision ), 'unreachable', true );
				}

				return array( __( 'The EU register is not answering right now. You can continue; VAT is charged and we check again later.', 'beltoft-eu-vat' ), 'unreachable', true );
			case 'pending':
				return array( '', 'info', true );
			default:
				list( $text, $type ) = self::for_field( $decision );

				return array( $text, 'error' === $type ? 'error' : 'info', false );
		}
	}
}
