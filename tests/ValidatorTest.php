<?php
namespace BeltoftEuVat\Tests;

use BeltoftEuVat\Settings;
use BeltoftEuVat\Validator;

class ValidatorTest extends TestCase {

	public function test_empty_field_is_a_consumer_sale() {
		$d = Validator::decide( '', 'DE' );

		$this->assertSame( '', $d->status );
		$this->assertFalse( $d->exempt );
		$this->assertFalse( $d->blocks_checkout );
	}

	public function test_a_number_of_the_sellers_country_is_domestic_and_never_asks_vies() {
		$this->vies = 'valid';
		$d          = Validator::decide( 'DK12345678', 'DK' );

		$this->assertSame( 'domestic', $d->status );
		$this->assertFalse( $d->exempt );
		$this->assertSame( 0, $this->requests );
	}

	public function test_seller_country_defaults_to_the_store_base_country() {
		delete_option( Settings::SELLER_COUNTRY );
		update_option( 'woocommerce_default_country', 'SE' );

		$this->assertSame( 'SE', Settings::seller_country() );
		$this->assertSame( 'domestic', Validator::decide( 'SE556000016701', 'SE' )->status );
	}

	public function test_other_eu_number_with_another_billing_country_is_blocked_without_vies() {
		$this->vies = 'valid';
		$d          = Validator::decide( 'DE123456789', 'DK' );

		$this->assertSame( 'country_mismatch', $d->status );
		$this->assertTrue( $d->blocks_checkout );
		$this->assertSame( 0, $this->requests );
	}

	public function test_an_eu_prefix_with_no_billing_country_is_a_mismatch() {
		$this->assertSame( 'country_mismatch', Validator::decide( 'DE123456789', '' )->status );
	}

	public function test_valid_number_is_reverse_charge_with_evidence() {
		$this->vies = 'valid';
		$d          = Validator::decide( 'de 123 456 789', 'DE' );

		$this->assertSame( 'valid', $d->status );
		$this->assertTrue( $d->exempt );
		$this->assertSame( 'DE123456789', $d->vat_number );
		$this->assertSame( 'WAPI-TEST-1', $d->request_identifier );
		$this->assertSame( 'ACME GmbH', $d->registered_name );
	}

	public function test_invalid_number_blocks() {
		$this->vies = 'invalid';
		$d          = Validator::decide( 'DE000000000', 'DE' );

		$this->assertSame( 'invalid', $d->status );
		$this->assertTrue( $d->blocks_checkout );
		$this->assertFalse( $d->exempt );
	}

	public function test_unreachable_vies_is_unverified_and_not_blocked() {
		$d = Validator::decide( 'DE123456789', 'DE' );

		$this->assertSame( 'unverified', $d->status );
		$this->assertFalse( $d->blocks_checkout );
		$this->assertFalse( $d->exempt );
	}

	public function test_non_eu_billing_country_ignores_the_field() {
		$this->vies = 'valid';
		$d          = Validator::decide( 'DE123456789', 'US' );

		$this->assertSame( 'ignored', $d->status );
		$this->assertFalse( $d->exempt );
		$this->assertFalse( $d->blocks_checkout );
		$this->assertSame( 0, $this->requests );
	}

	public function test_greece_uses_el_for_vies_and_gr_for_billing() {
		$this->vies = 'valid';
		$d          = Validator::decide( 'EL123456789', 'GR' );

		$this->assertSame( 'valid', $d->status );
		$this->assertSame( 'EL', $this->bodies[0]['countryCode'] );
		$this->assertSame( '123456789', $this->bodies[0]['vatNumber'] );
		$this->assertSame( 'valid', Validator::decide( 'GR987654321', 'GR' )->status );
	}

	public function test_northern_ireland_xi_number_is_billed_in_gb() {
		$this->vies = 'valid';

		$this->assertSame( 'valid', Validator::decide( 'XI123456789', 'GB' )->status );
		$this->assertSame( 'ignored', Validator::decide( 'GB123456789', 'GB' )->status );
	}

	public function test_local_check_filter_can_refuse_before_vies() {
		$this->vies = 'valid';
		add_filter(
			'beltoft_eu_vat_local_check',
			static function ( $result, $country ) {
				return 'DE' === $country ? false : $result;
			},
			10,
			2
		);

		$d = Validator::decide( 'DE123456789', 'DE' );

		$this->assertSame( 'invalid_format', $d->status );
		$this->assertTrue( $d->blocks_checkout );
		$this->assertSame( 0, $this->requests );
	}

	public function test_local_check_applies_to_the_domestic_number() {
		add_filter( 'beltoft_eu_vat_local_check', '__return_false' );

		$this->assertSame( 'invalid_format', Validator::decide( 'DK1234', 'DK' )->status );
	}

	public function test_built_in_portuguese_nif_check() {
		update_option( Settings::SELLER_COUNTRY, 'PT' );

		$this->assertSame( 'domestic', Validator::decide( 'PT500000000', 'PT' )->status );
		$this->assertSame( 'invalid_format', Validator::decide( 'PT500000001', 'PT' )->status );
		$this->assertSame( 'invalid_format', Validator::decide( 'PT1', 'PT' )->status );
	}
}
