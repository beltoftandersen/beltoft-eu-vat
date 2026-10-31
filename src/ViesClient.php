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
 * invalid. Only a valid answer is cached (24 hours); an outage or an invalid answer is not.
 */
class ViesClient {

	private const ENDPOINT     = 'https://ec.europa.eu/taxation_customs/vies/rest-api/check-vat-number';
	private const CACHE_PREFIX = 'beltoft_eu_vat_vies_';

	/**
	 * Consults VIES, or the cache.
	 *
	 * @param string $country      VIES member state code (EL for Greece).
	 * @param string $number       Number without the country prefix.
	 * @param bool   $allow_remote False answers from the cache only, never over HTTP.
	 */
	public static function check( string $country, string $number, bool $allow_remote = true ): ViesResult {
		$country = strtoupper( trim( $country ) );
		$number  = Settings::normalise( $number );

		$cached = get_transient( self::cache_key( $country, $number ) );

		if ( is_array( $cached ) && isset( $cached['status'] ) && 'valid' === $cached['status'] ) {
			return new ViesResult(
				'valid',
				(string) ( $cached['request_identifier'] ?? '' ),
				(string) ( $cached['registered_name'] ?? '' ),
				'',
				(int) ( $cached['checked_at'] ?? 0 )
			);
		}

		if ( ! $allow_remote ) {
			return new ViesResult( 'unreachable', '', '', 'not checked in this context' );
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
			return new ViesResult( 'unreachable', '', '', $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( $code < 200 || $code >= 300 ) {
			return new ViesResult( 'unreachable', '', '', 'HTTP ' . $code );
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $data ) || ! array_key_exists( 'valid', $data ) ) {
			return new ViesResult( 'unreachable', '', '', 'unrecognised response' );
		}

		$result = new ViesResult(
			$data['valid'] ? 'valid' : 'invalid',
			(string) ( $data['requestIdentifier'] ?? '' ),
			(string) ( $data['name'] ?? '' )
		);

		if ( $result->is_valid() ) {
			set_transient(
				self::cache_key( $country, $number ),
				array(
					'status'             => 'valid',
					'request_identifier' => $result->request_identifier,
					'registered_name'    => $result->registered_name,
					'checked_at'         => $result->checked_at,
				),
				(int) apply_filters( 'beltoft_eu_vat_cache_ttl', DAY_IN_SECONDS )
			);
		}

		return $result;
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
