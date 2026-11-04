<?php
/**
 * Remembers the last valid confirmation of a number, for the grace period.
 *
 * @package BeltoftEuVat
 */

namespace BeltoftEuVat;

defined( 'ABSPATH' ) || exit;

/**
 * The 72 hour cache is too short to bridge a long VIES outage. Every valid answer is therefore
 * also kept for the grace period (default 30 days). While VIES does not answer, a number whose
 * country and digits match a confirmation inside that period is accepted. The sources are, in
 * order: that record, the evidence stored on the logged-in customer's account, and the evidence
 * stored on any account holding the same number. An invalid answer drops the record.
 */
final class Grace {

	private const PREFIX = 'beltoft_eu_vat_last_valid_';

	/**
	 * Keeps a valid answer for the grace period.
	 *
	 * @param string     $country VIES code.
	 * @param string     $number  Number without prefix.
	 * @param ViesResult $result  A valid answer.
	 */
	public static function remember( string $country, string $number, ViesResult $result ): void {
		$days = Settings::grace_days();

		if ( $days < 1 || ! $result->is_valid() ) {
			return;
		}

		set_transient(
			self::key( $country, $number ),
			array(
				'request_identifier' => $result->request_identifier,
				'registered_name'    => $result->registered_name,
				'checked_at'         => $result->checked_at,
			),
			$days * DAY_IN_SECONDS
		);
	}

	/**
	 * Drops the record, after VIES said the number is invalid.
	 *
	 * @param string $country VIES code.
	 * @param string $number  Number without prefix.
	 */
	public static function forget( string $country, string $number ): void {
		delete_transient( self::key( $country, $number ) );
	}

	/**
	 * The confirmation to fall back on, or null.
	 *
	 * @param string $country VIES code (EL for Greece).
	 * @param string $number  Number without prefix.
	 */
	public static function find( string $country, string $number ): ?ViesResult {
		$days = Settings::grace_days();

		if ( $days < 1 ) {
			return null;
		}

		$since  = time() - $days * DAY_IN_SECONDS;
		$record = get_transient( self::key( $country, $number ) );

		if ( is_array( $record ) && (int) ( $record['checked_at'] ?? 0 ) >= $since ) {
			return new ViesResult(
				'valid',
				(string) ( $record['request_identifier'] ?? '' ),
				(string) ( $record['registered_name'] ?? '' ),
				'',
				(int) $record['checked_at']
			);
		}

		$full = strtoupper( $country ) . strtoupper( $number );

		foreach ( self::user_ids( $full, $country, $number ) as $user_id ) {
			$found = self::from_user( (int) $user_id, $since );

			if ( null !== $found ) {
				return $found;
			}
		}

		return null;
	}

	/**
	 * The accounts that may hold this number: the current customer first, then any other.
	 *
	 * @param string $full    Country code and number.
	 * @param string $country VIES code.
	 * @param string $number  Number without prefix.
	 * @return int[]
	 */
	private static function user_ids( string $full, string $country, string $number ): array {
		$ids      = array();
		$variants = array( $full );

		if ( 'EL' === strtoupper( $country ) ) {
			$variants[] = 'GR' . strtoupper( $number );
		}

		if ( is_user_logged_in() && in_array( Settings::normalise( Evidence::stored_number( get_current_user_id() ) ), $variants, true ) ) {
			$ids[] = get_current_user_id();
		}

		$others = get_users(
			array(
				'meta_key'   => Field::KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Only runs while VIES is down.
				'meta_value' => $variants, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'compare'    => 'IN',
				'number'     => 10,
				'fields'     => 'ID',
			)
		);

		return array_values( array_unique( array_merge( $ids, array_map( 'intval', $others ) ) ) );
	}

	/**
	 * A confirmation from an account's stored evidence, when it is for this number and recent enough.
	 *
	 * @param int $user_id User id.
	 * @param int $since   Oldest acceptable time.
	 */
	private static function from_user( int $user_id, int $since ): ?ViesResult {
		$status = (string) get_user_meta( $user_id, Evidence::STATUS, true );
		$when   = strtotime( (string) get_user_meta( $user_id, Evidence::CHECKED_AT, true ) );

		if ( ! in_array( $status, array( 'valid' ), true ) || ! $when || $when < $since || '' !== (string) get_user_meta( $user_id, Evidence::FLAG, true ) ) {
			return null;
		}

		return new ViesResult(
			'valid',
			(string) get_user_meta( $user_id, Evidence::REQUEST_ID, true ),
			(string) get_user_meta( $user_id, Evidence::NAME, true ),
			'',
			$when
		);
	}

	/**
	 * Transient name.
	 *
	 * @param string $country VIES code.
	 * @param string $number  Number without prefix.
	 */
	private static function key( string $country, string $number ): string {
		return self::PREFIX . strtoupper( $country ) . '_' . strtoupper( Settings::normalise( $number ) );
	}
}
