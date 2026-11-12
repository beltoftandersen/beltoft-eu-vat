<?php
/**
 * Decides whether the company name earns the reverse charge.
 *
 * @package BeltoftEuVat
 */

namespace BeltoftEuVat;

defined( 'ABSPATH' ) || exit;

/**
 * A valid VAT number in the right country is not enough: the number's holder must be the
 * customer. VIES returns the registered name, and this compares it with the billing company
 * (or, for a sole trader, the billing person). A mismatch charges VAT; it never blocks the order.
 *
 * Only a decision with status valid is judged. Stored evidence of earlier orders and subscription
 * renewals is never judged again: nothing here runs when a renewal is created.
 */
final class NameGate {

	/** Name the register did not disclose. */
	public const NO_NAME = 'no name available';

	/**
	 * What the customer entered: the posted checkout fields, else the customer's saved billing details.
	 *
	 * @return array{company:string,first_name:string,last_name:string}
	 */
	public static function entered(): array {
		$posted = Checkout::posted();

		if ( null !== $posted ) {
			return array(
				'company'    => $posted['company'],
				'first_name' => $posted['first_name'],
				'last_name'  => $posted['last_name'],
			);
		}

		$customer = function_exists( 'WC' ) ? WC()->customer : null;

		return array(
			'company'    => $customer ? (string) $customer->get_billing_company() : '',
			'first_name' => $customer ? (string) $customer->get_billing_first_name() : '',
			'last_name'  => $customer ? (string) $customer->get_billing_last_name() : '',
		);
	}

	/**
	 * Judges a decision.
	 *
	 * checked is false when no judgement applies (setting off, or not a reverse-charge candidate): the
	 * number is then treated as before. ok says whether the exemption stands.
	 *
	 * @param VatDecision                                     $decision Decision.
	 * @param array{company:string,first_name:string,last_name:string} $entered  What the customer entered.
	 * @return array{checked:bool,ok:bool,reason:string,entered:string,registered:string}
	 */
	public static function judge( VatDecision $decision, array $entered ): array {
		$out = array(
			'checked'    => false,
			'ok'         => true,
			'reason'     => '',
			'entered'    => self::label( $entered ),
			'registered' => '',
		);

		if ( ! Settings::name_match() || 'valid' !== $decision->status || ! $decision->exempt ) {
			return $out;
		}

		$registered         = self::clean( $decision->registered_name );
		$out['checked']     = true;
		$out['registered']  = $registered;

		if ( '' === $registered ) {
			$out['ok']     = Settings::ALLOW === Settings::name_missing();
			$out['reason'] = self::NO_NAME;

			return $out;
		}

		$result        = NameMatch::matches(
			(string) $entered['company'],
			$registered,
			array(
				'vat_number' => $decision->vat_number,
				'country'    => $decision->country,
				'company'    => (string) $entered['company'],
				'first_name' => (string) $entered['first_name'],
				'last_name'  => (string) $entered['last_name'],
			)
		);
		$out['ok']     = $result['matched'];
		$out['reason'] = $result['reason'];

		return $out;
	}

	/**
	 * The register's name, or an empty string when it has none ("---", "n/a", only punctuation).
	 *
	 * @param string $name Raw name.
	 */
	public static function clean( string $name ): string {
		$name = trim( $name );

		return ( '' === $name || ! preg_match( '/[\p{L}\p{N}]/u', $name ) || preg_match( '/^(n\/?a|none|unknown)$/i', $name ) ) ? '' : $name;
	}

	/**
	 * What to record as "entered": the company, else the person.
	 *
	 * @param array $entered Entered values.
	 */
	public static function label( array $entered ): string {
		$company = trim( (string) ( $entered['company'] ?? '' ) );

		return '' !== $company ? $company : trim( trim( (string) ( $entered['first_name'] ?? '' ) ) . ' ' . trim( (string) ( $entered['last_name'] ?? '' ) ) );
	}

	/**
	 * Whether the exemption stands for what was entered.
	 *
	 * @param VatDecision $decision Decision.
	 * @param array       $entered  Entered values.
	 */
	public static function allows( VatDecision $decision, array $entered ): bool {
		return self::judge( $decision, $entered )['ok'];
	}
}
