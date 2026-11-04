<?php
/**
 * Outcome of one VIES consultation.
 *
 * @package BeltoftEuVat
 */

namespace BeltoftEuVat;

defined( 'ABSPATH' ) || exit;

/**
 * Status is valid, invalid or unreachable. Anything that is not an explicit valid or invalid
 * answer is unreachable, never invalid.
 */
class ViesResult {

	/** Error text of the cache-only answer when nothing is cached: VIES was not asked. */
	public const NOT_CHECKED = 'not checked in this context';

	public string $status;
	public string $request_identifier;
	public string $registered_name;
	public int $checked_at;
	public string $error;

	/**
	 * Constructor.
	 *
	 * @param string $status             valid, invalid or unreachable.
	 * @param string $request_identifier VIES consultation number.
	 * @param string $registered_name    Name VIES holds for the number.
	 * @param string $error              Detail for an unreachable answer.
	 * @param int    $checked_at         When the answer was obtained (a cached answer keeps its own time).
	 */
	public function __construct( string $status, string $request_identifier = '', string $registered_name = '', string $error = '', int $checked_at = 0 ) {
		$this->status             = $status;
		$this->request_identifier = $request_identifier;
		$this->registered_name    = $registered_name;
		$this->error              = $error;
		$this->checked_at         = $checked_at > 0 ? $checked_at : time();
	}

	/**
	 * Whether VIES confirmed the number.
	 */
	public function is_valid(): bool {
		return 'valid' === $this->status;
	}

	/**
	 * Whether VIES gave no definitive answer.
	 */
	public function is_unreachable(): bool {
		return 'unreachable' === $this->status;
	}

	/**
	 * Whether VIES was not asked at all (cache-only lookup with nothing cached).
	 */
	public function is_pending(): bool {
		return 'unreachable' === $this->status && self::NOT_CHECKED === $this->error;
	}
}
