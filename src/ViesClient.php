<?php
/**
 * VIES REST consultation.
 *
 * @package BeltoftEuVat
 */

namespace BeltoftEuVat;

defined( 'ABSPATH' ) || exit;

/**
 * Asks the European Commission's VIES service. No key is needed. The seller's own VAT number is
 * sent as the requester, which makes VIES return a consultation number, the one piece of
 * evidence that cannot be rebuilt later.
 *
 * VIES is a federation of member state services and single countries go offline regularly. A
 * transport failure, a timeout, a non-2xx reply or an unknown body all give unreachable, never
 * invalid. VIES also answers HTTP 200 with valid:false and a userError code for outages and
 * limits (MS_UNAVAILABLE, TIMEOUT, MS_MAX_CONCURRENT_REQ ...): any userError other than VALID or
 * INVALID is unreachable too. A valid answer is cached for 72 hours, an invalid one for 10
 * minutes and an unreachable one for 60 seconds (all filterable), so a repeated or junk number
 * does not ask VIES again. An unreachable answer never becomes invalid from the cache.
 */
class ViesClient {

	private const ENDPOINT     = 'https://ec.europa.eu/taxation_customs/vies/rest-api/check-vat-number';
	private const CACHE_PREFIX = 'beltoft_eu_vat_vies_';
	private const MAX_LENGTH   = 20;

	/**
	 * Consults VIES, or the cache.
	 *
	 * @param string $country      VIES member state code (EL for Greece).
	 * @param string $number       Number without the country prefix.
	 * @param bool   $allow_remote False answers from the cache only, never over HTTP.
	 * @param bool   $fresh        True ignores the cache and asks VIES (the nightly re-check). The answer is cached as usual.
	 */
	public static function check( string $country, string $number, bool $allow_remote = true, bool $fresh = false ): ViesResult {
		$country = strtoupper( trim( $country ) );
		$number  = Settings::normalise( $number );

		/**
		 * Filters the longest number (without country prefix) that is sent to VIES.
		 *
		 * @param int $length Characters.
		 */
		$max = (int) apply_filters( 'beltoft_eu_vat_max_number_length', self::MAX_LENGTH );

		if ( strlen( $number ) > $max ) {
			return new ViesResult( 'invalid', '', '', 'number too long' );
		}

		$cached = $fresh ? false : get_transient( self::cache_key( $country, $number ) );

		if ( is_array( $cached ) && isset( $cached['status'] ) ) {
			if ( 'valid' === $cached['status'] ) {
				return new ViesResult(
					'valid',
					(string) ( $cached['request_identifier'] ?? '' ),
					(string) ( $cached['registered_name'] ?? '' ),
					'',
					(int) ( $cached['checked_at'] ?? 0 )
				);
			}

			if ( 'invalid' === $cached['status'] ) {
				return new ViesResult( 'invalid', '', '', '', (int) ( $cached['checked_at'] ?? 0 ) );
			}

			if ( 'unreachable' === $cached['status'] ) {
				return new ViesResult( 'unreachable', '', '', (string) ( $cached['error'] ?? '' ) );
			}
		}

		if ( ! $allow_remote ) {
			return new ViesResult( 'unreachable', '', '', ViesResult::NOT_CHECKED );
		}

		$body = array(
			'countryCode' => $country,
			'vatNumber'   => $number,
		);

		$own = Settings::own_number();

		if ( strlen( $own ) > 2 ) {
			$body['requesterMemberStateCode'] = substr( $own, 0, 2 );
			$body['requesterNumber']          = substr( $own, 2 );
		}

		$response = wp_remote_post(
			(string) apply_filters( 'beltoft_eu_vat_vies_endpoint', self::ENDPOINT ),
			array(
				'timeout' => 10,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return self::unreachable( $country, $number, $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( $code < 200 || $code >= 300 ) {
			return self::unreachable( $country, $number, 'HTTP ' . $code );
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $data ) || ! array_key_exists( 'valid', $data ) ) {
			return self::unreachable( $country, $number, 'unrecognised response' );
		}

		$user_error = isset( $data['userError'] ) && is_scalar( $data['userError'] ) ? trim( (string) $data['userError'] ) : '';

		if ( '' !== $user_error && ! in_array( strtoupper( $user_error ), array( 'VALID', 'INVALID' ), true ) ) {
			return self::unreachable( $country, $number, $user_error );
		}

		$result = new ViesResult(
			$data['valid'] ? 'valid' : 'invalid',
			(string) ( $data['requestIdentifier'] ?? '' ),
			(string) ( $data['name'] ?? '' )
		);

		if ( $result->is_valid() ) {
			Grace::remember( $country, $number, $result );

			set_transient(
				self::cache_key( $country, $number ),
				array(
					'status'             => 'valid',
					'request_identifier' => $result->request_identifier,
					'registered_name'    => $result->registered_name,
					'checked_at'         => $result->checked_at,
				),
				(int) apply_filters( 'beltoft_eu_vat_cache_ttl', 3 * DAY_IN_SECONDS )
			);
		} else {
			Grace::forget( $country, $number );

			set_transient(
				self::cache_key( $country, $number ),
				array(
					'status'     => 'invalid',
					'checked_at' => $result->checked_at,
				),
				(int) apply_filters( 'beltoft_eu_vat_invalid_cache_ttl', 10 * MINUTE_IN_SECONDS )
			);
		}

		return $result;
	}

	/**
	 * Drops a remembered unreachable answer, so that "check again" asks VIES again. A valid or
	 * invalid answer is left in place.
	 *
	 * @param string $country Member state code.
	 * @param string $number  Number without prefix.
	 */
	public static function forget_unreachable( string $country, string $number ): void {
		$key    = self::cache_key( $country, Settings::normalise( $number ) );
		$cached = get_transient( $key );

		if ( is_array( $cached ) && 'unreachable' === ( $cached['status'] ?? '' ) ) {
			delete_transient( $key );
		}
	}

	/**
	 * An unreachable result, remembered briefly.
	 *
	 * @param string $country Member state code.
	 * @param string $number  Number without prefix.
	 * @param string $detail  What went wrong.
	 */
	private static function unreachable( string $country, string $number, string $detail ): ViesResult {
		set_transient(
			self::cache_key( $country, $number ),
			array(
				'status' => 'unreachable',
				'error'  => $detail,
			),
			(int) apply_filters( 'beltoft_eu_vat_unreachable_cache_ttl', MINUTE_IN_SECONDS )
		);

		return new ViesResult( 'unreachable', '', '', $detail );
	}

	/**
	 * Transient name for a number.
	 *
	 * @param string $country Member state code.
	 * @param string $number  Number without prefix.
	 */
	public static function cache_key( string $country, string $number ): string {
		return self::CACHE_PREFIX . strtoupper( $country ) . '_' . strtoupper( $number );
	}
}
