<?php
/**
 * The inline VAT number check: a small wc-ajax endpoint the field calls right after typing.
 *
 * @package BeltoftEuVat
 */

namespace BeltoftEuVat;

defined( 'ABSPATH' ) || exit;

/**
 * Asks VIES (or the cache) for one number, so that the checkout refresh never has to. The
 * answer lands in the cache, and the one refresh the script asks for next reads it from there.
 * Protected by a nonce and rate limited per IP and per customer or session.
 */
final class Inline {

	public const ACTION = 'beltoft_eu_vat_check';
	public const NONCE  = 'beltoft-eu-vat-check';

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'wc_ajax_' . self::ACTION, array( __CLASS__, 'handle' ) );
	}

	/**
	 * The wc-ajax request.
	 */
	public static function handle(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- respond() verifies the nonce and cleans every value.
		$reply = self::respond( wp_unslash( $_POST ) );

		nocache_headers();
		wp_send_json( $reply['body'], $reply['status'] );
	}

	/**
	 * Answers one check.
	 *
	 * @param array $post Posted values: security, vat, country, retry.
	 * @return array{status:int,body:array<string,mixed>}
	 */
	public static function respond( array $post ): array {
		$nonce = isset( $post['security'] ) && is_string( $post['security'] ) ? $post['security'] : '';

		if ( ! wp_verify_nonce( $nonce, self::NONCE ) ) {
			return array(
				'status' => 403,
				'body'   => self::body( 'error', __( 'The page has expired. Reload it and try again.', 'beltoft-eu-vat' ), false ),
			);
		}

		if ( self::limited() ) {
			return array(
				'status' => 429,
				'body'   => self::body( 'error', __( 'Too many checks. Please wait a minute and try again.', 'beltoft-eu-vat' ), true ),
			);
		}

		$number  = Settings::normalise( isset( $post['vat'] ) && is_string( $post['vat'] ) ? sanitize_text_field( $post['vat'] ) : '' );
		$country = strtoupper( isset( $post['country'] ) && is_string( $post['country'] ) ? sanitize_text_field( $post['country'] ) : '' );

		if ( '' === $number ) {
			return array(
				'status' => 200,
				'body'   => self::body( 'empty', '', false ),
			);
		}

		if ( ! empty( $post['retry'] ) ) {
			list( $code, $bare ) = Validator::vies_parts( $number, $country );
			ViesClient::forget_unreachable( $code, $bare );
		}

		$decision            = Decisions::get( $number, $country, true );
		list( $text, $state, $retry ) = Messages::inline( $decision );

		return array(
			'status' => 200,
			'body'   => self::body( $state, $text, $retry ),
		);
	}

	/**
	 * Script settings for the field.
	 *
	 * @return array<string,mixed>
	 */
	public static function config(): array {
		return array(
			'url'      => \WC_AJAX::get_endpoint( self::ACTION ),
			'nonce'    => wp_create_nonce( self::NONCE ),
			'strings'  => array(
				'checking' => __( 'Checking with the EU VAT register…', 'beltoft-eu-vat' ),
				'again'    => __( 'Check again', 'beltoft-eu-vat' ),
				'failed'   => __( 'The EU register could not be reached from this page. You can continue; VAT is charged and we check again later.', 'beltoft-eu-vat' ),
			),
		);
	}

	/**
	 * Whether this visitor has used up the checks of the minute. Counted per IP and per customer
	 * or session. Filter `beltoft_eu_vat_check_rate_limit` returns array( checks, seconds ).
	 */
	public static function limited(): bool {
		/**
		 * Filters the inline check rate limit.
		 *
		 * @param array $limit array( checks, window in seconds ).
		 */
		$limit  = (array) apply_filters( 'beltoft_eu_vat_check_rate_limit', array( 12, MINUTE_IN_SECONDS ) );
		$max    = isset( $limit[0] ) ? (int) $limit[0] : 12;
		$window = isset( $limit[1] ) ? max( 1, (int) $limit[1] ) : MINUTE_IN_SECONDS;

		if ( $max < 1 ) {
			return false;
		}

		$ip      = class_exists( '\WC_Geolocation' ) ? (string) \WC_Geolocation::get_ip_address() : '';
		$session = is_user_logged_in() ? 'u' . get_current_user_id() : ( function_exists( 'WC' ) && WC()->session ? 's' . WC()->session->get_customer_id() : '' );
		$over    = false;

		foreach ( array( 'ip' . $ip, $session ) as $who ) {
			if ( '' === $who || 'ip' === $who ) {
				continue;
			}

			$key   = 'beltoft_eu_vat_rl_' . md5( $who );
			$slot  = get_transient( $key );
			$slot  = is_array( $slot ) ? $slot : array( 0, time() );
			$left  = max( 1, $window - ( time() - (int) $slot[1] ) );

			if ( (int) $slot[0] >= $max ) {
				$over = true;
				continue;
			}

			set_transient( $key, array( (int) $slot[0] + 1, (int) $slot[1] ), $left );
		}

		return $over;
	}

	/**
	 * Response body.
	 *
	 * @param string $state valid, notfound, unreachable, info, error or empty.
	 * @param string $text  Plain text for the line.
	 * @param bool   $retry Whether "Check again" shows.
	 * @return array<string,mixed>
	 */
	private static function body( string $state, string $text, bool $retry ): array {
		return array(
			'state' => $state,
			'text'  => $text,
			'retry' => $retry,
		);
	}
}
