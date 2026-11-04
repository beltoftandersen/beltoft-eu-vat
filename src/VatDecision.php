<?php
/**
 * The outcome of applying the decision table to a VAT number.
 *
 * @package BeltoftEuVat
 */

namespace BeltoftEuVat;

defined( 'ABSPATH' ) || exit;

/**
 * Value object.
 *
 * Status: empty (no number), ignored (billing outside the EU), domestic, country_mismatch,
 * invalid_format, valid, pending (nothing cached yet, VIES not asked), unverified (VIES did not answer) or invalid. A valid decision has grace set when VIES did not answer and an earlier confirmation was accepted (checked_at and request_identifier are those of that confirmation). A domestic decision has
 * confirmed set when VIES said the number is valid.
 */
class VatDecision {

	public bool $exempt               = false;
	public bool $blocks_checkout      = false;
	public bool $confirmed            = false;
	public bool $grace                = false;
	public string $status             = '';
	public string $vat_number         = '';
	public string $country            = '';
	public string $request_identifier = '';
	public string $registered_name    = '';
	public string $error              = '';
	public int $checked_at            = 0;

	/**
	 * Constructor.
	 *
	 * @param string $status     Status.
	 * @param string $vat_number Normalised number.
	 */
	public function __construct( string $status = '', string $vat_number = '' ) {
		$this->status     = $status;
		$this->vat_number = $vat_number;
		$this->checked_at = time();
	}

	/**
	 * Whether VIES was actually asked (and a date is worth recording).
	 */
	public function consulted(): bool {
		if ( 'domestic' === $this->status ) {
			return $this->confirmed;
		}

		return in_array( $this->status, array( 'valid', 'invalid', 'unverified' ), true ) && ViesResult::NOT_CHECKED !== $this->error;
	}
}
