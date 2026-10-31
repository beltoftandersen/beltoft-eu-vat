<?php
namespace BeltoftEuVat\Tests;

use BeltoftEuVat\Settings;
use BeltoftEuVat\ViesClient;

class ViesClientTest extends TestCase {

	public function test_valid_number_gives_consultation_number_and_name() {
		$this->vies = 'valid';
		$result     = ViesClient::check( 'DE', '123456789' );

		$this->assertSame( 'valid', $result->status );
		$this->assertSame( 'WAPI-TEST-1', $result->request_identifier );
		$this->assertSame( 'ACME GmbH', $result->registered_name );
	}

	public function test_invalid_number() {
		$this->vies = 'invalid';
		$this->assertSame( 'invalid', ViesClient::check( 'DE', '000000000' )->status );
	}

	public function test_timeout_is_unreachable_never_invalid() {
		$result = ViesClient::check( 'DE', '123456789' );

		$this->assertSame( 'unreachable', $result->status );
		$this->assertNotEmpty( $result->error );
	}

	public function test_unknown_body_and_server_error_are_unreachable() {
		$this->vies = 'shape';
		$this->assertSame( 'unreachable', ViesClient::check( 'DE', '1' )->status );
		$this->vies = 'error503';
		$this->assertSame( 'unreachable', ViesClient::check( 'DE', '2' )->status );
	}

	public function test_a_valid_answer_is_cached_for_24_hours() {
		$this->vies = 'valid';
		ViesClient::check( 'DE', '123456789' );
		$second = ViesClient::check( 'DE', '123456789' );

		$this->assertSame( 1, $this->requests );
		$this->assertSame( 'valid', $second->status );
		$this->assertSame( 'WAPI-TEST-1', $second->request_identifier );

		$timeout = (int) get_option( '_transient_timeout_' . ViesClient::cache_key( 'DE', '123456789' ) );
		$this->assertEqualsWithDelta( time() + DAY_IN_SECONDS, $timeout, 10 );
	}

	public function test_invalid_and_unreachable_answers_are_never_cached() {
		$this->vies = 'invalid';
		ViesClient::check( 'DE', '000000000' );
		ViesClient::check( 'DE', '000000000' );
		$this->assertSame( 2, $this->requests );

		$this->vies = 'unreachable';
		ViesClient::check( 'DE', '111111111' );
		ViesClient::check( 'DE', '111111111' );
		$this->assertSame( 4, $this->requests );
	}

	public function test_a_cached_answer_keeps_its_own_time() {
		$this->cache_valid( 'DE', '123456789' );
		$result = ViesClient::check( 'DE', '123456789' );

		$this->assertEqualsWithDelta( time() - 3600, $result->checked_at, 5 );
	}

	public function test_no_remote_call_when_not_allowed() {
		$result = ViesClient::check( 'DE', '123456789', false );

		$this->assertSame( 'unreachable', $result->status );
		$this->assertSame( 0, $this->requests );
	}

	public function test_the_sellers_own_number_is_the_requester() {
		update_option( Settings::OWN_NUMBER, 'DK 12 34 56 78' );
		$this->vies = 'valid';
		ViesClient::check( 'DE', '123456789' );

		$this->assertSame( 'DK', $this->bodies[0]['requesterMemberStateCode'] );
		$this->assertSame( '12345678', $this->bodies[0]['requesterNumber'] );
		$this->assertSame( 'DE', $this->bodies[0]['countryCode'] );
	}

	public function test_without_an_own_number_no_requester_is_sent() {
		$this->vies = 'valid';
		ViesClient::check( 'DE', '123456789' );

		$this->assertArrayNotHasKey( 'requesterNumber', $this->bodies[0] );
	}
}
