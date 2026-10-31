<?php
namespace BeltoftEuVat\Tests;

use BeltoftEuVat\Evidence;
use BeltoftEuVat\Validator;
use BeltoftEuVat\VatDecision;

class EvidenceTest extends TestCase {

	public function test_a_valid_check_stores_number_and_proof_together() {
		$this->vies = 'valid';
		$user       = self::factory()->user->create();
		$decision   = Validator::decide( 'DE123456789', 'DE' );

		Evidence::store( $user, 'DE123456789', $decision );

		$this->assertSame( 'DE123456789', get_user_meta( $user, 'billing_eu_vat_number', true ) );
		$this->assertSame( 'valid', get_user_meta( $user, '_beltoft_eu_vat_status', true ) );
		$this->assertSame( 'WAPI-TEST-1', get_user_meta( $user, '_beltoft_eu_vat_request_id', true ) );
		$this->assertSame( 'ACME GmbH', get_user_meta( $user, '_beltoft_eu_vat_name', true ) );
		$this->assertNotSame( '', get_user_meta( $user, '_beltoft_eu_vat_checked_at', true ) );
	}

	public function test_a_domestic_number_has_no_consultation_date() {
		$user     = self::factory()->user->create();
		$decision = Validator::decide( 'DK12345678', 'DK' );

		Evidence::store( $user, 'DK12345678', $decision );

		$this->assertSame( 'domestic', get_user_meta( $user, '_beltoft_eu_vat_status', true ) );
		$this->assertSame( '', get_user_meta( $user, '_beltoft_eu_vat_checked_at', true ) );
		$this->assertSame( '', get_user_meta( $user, '_beltoft_eu_vat_request_id', true ) );
	}

	public function test_clearing_the_number_clears_the_proof() {
		$this->vies = 'valid';
		$user       = self::factory()->user->create();
		Evidence::store( $user, 'DE123456789', Validator::decide( 'DE123456789', 'DE' ) );

		Evidence::store( $user, '', new VatDecision() );

		$this->assertSame( '', get_user_meta( $user, 'billing_eu_vat_number', true ) );
		$this->assertSame( '', get_user_meta( $user, '_beltoft_eu_vat_status', true ) );
		$this->assertSame( '', get_user_meta( $user, '_beltoft_eu_vat_request_id', true ) );
	}

	public function test_guests_are_ignored() {
		Evidence::store( 0, 'DE123456789', new VatDecision( 'valid', 'DE123456789' ) );
		$this->assertSame( '', Evidence::stored_number( 0 ) );
	}

	public function test_old_plugin_meta_is_a_read_fallback() {
		$user = self::factory()->user->create();
		update_user_meta( $user, '_wc_other/alg_eu_vat/billing_eu_vat_number', 'FR12345678901' );

		$this->assertSame( 'FR12345678901', Evidence::stored_number( $user ) );

		update_user_meta( $user, 'billing_eu_vat_number', 'DE123456789' );
		$this->assertSame( 'DE123456789', Evidence::stored_number( $user ) );
	}
}
