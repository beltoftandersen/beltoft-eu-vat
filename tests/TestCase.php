<?php
/**
 * Shared helpers. Tests never call the network: every HTTP request is answered here.
 *
 * @package BeltoftEuVat
 */

namespace BeltoftEuVat\Tests;

use BeltoftEuVat\Checkout;
use BeltoftEuVat\Decisions;
use BeltoftEuVat\Settings;

abstract class TestCase extends \WP_UnitTestCase {

	/** HTTP requests seen. @var int */
	protected int $requests = 0;

	/** Request bodies seen. @var array */
	protected array $bodies = array();

	/** What VIES answers: valid, invalid, unreachable, shape, error503. @var string */
	protected string $vies = 'unreachable';

	public function set_up(): void {
		parent::set_up();

		foreach ( array( Settings::SELLER_COUNTRY, Settings::OWN_NUMBER, Settings::LABEL, Settings::ON_UNREACHABLE, Settings::ADMIN_EMAIL ) as $option ) {
			delete_option( $option );
		}

		update_option( Settings::SELLER_COUNTRY, 'DK' );
		update_option( Settings::ADMIN_EMAIL, 'christian.beltoft@gmail.com' );

		$GLOBALS['wpdb']->query( "DELETE FROM {$GLOBALS['wpdb']->options} WHERE option_name LIKE '%beltoft_eu_vat_vies_%'" );

		Decisions::reset();
		Checkout::reset();
		$this->requests = 0;
		$this->bodies   = array();
		$this->vies     = 'unreachable';
		$_POST          = array();

		add_filter( 'pre_http_request', array( $this, 'fake_http' ), 1, 3 );
		wc_clear_notices();
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'fake_http' ), 1 );
		$_POST = array();
		wc_clear_notices();
		Decisions::reset();
		Checkout::reset();
		parent::tear_down();
	}

	/**
	 * The only HTTP the suite ever does.
	 *
	 * @param mixed  $pre  Short-circuit value.
	 * @param array  $args Request arguments.
	 * @param string $url  URL.
	 * @return mixed
	 */
	public function fake_http( $pre, $args, $url ) {
		++$this->requests;
		$this->bodies[] = json_decode( (string) ( $args['body'] ?? '' ), true );

		switch ( $this->vies ) {
			case 'valid':
			case 'invalid':
				return array(
					'headers'  => array(),
					'body'     => wp_json_encode(
						array(
							'valid'             => 'valid' === $this->vies,
							'requestIdentifier' => 'WAPI-TEST-1',
							'name'              => 'ACME GmbH',
						)
					),
					'response' => array( 'code' => 200, 'message' => 'OK' ),
				);
			case 'shape':
				return array( 'headers' => array(), 'body' => '{"other":1}', 'response' => array( 'code' => 200, 'message' => 'OK' ) );
			case 'error503':
				return array( 'headers' => array(), 'body' => '{"message":"MS_UNAVAILABLE"}', 'response' => array( 'code' => 503, 'message' => 'Unavailable' ) );
			default:
				return new \WP_Error( 'http_request_failed', 'timed out' );
		}
	}

	/**
	 * Puts a valid answer in the cache without HTTP.
	 *
	 * @param string $country VIES code.
	 * @param string $number  Number without prefix.
	 */
	protected function cache_valid( string $country, string $number ): void {
		set_transient(
			\BeltoftEuVat\ViesClient::cache_key( $country, $number ),
			array(
				'status'             => 'valid',
				'request_identifier' => 'WAPI-CACHED',
				'registered_name'    => 'ACME GmbH',
				'checked_at'         => time() - 3600,
			),
			DAY_IN_SECONDS
		);
	}
}
