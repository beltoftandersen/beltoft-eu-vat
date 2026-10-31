<?php
namespace BeltoftEuVat\Tests;

use BeltoftEuVat\Account;
use BeltoftEuVat\AccountUpdate;
use BeltoftEuVat\VatDecision;

class AccountUpdateTest extends TestCase {

	private function result( string $status, string $number = 'X', string $submitted = 'X' ): array {
		return AccountUpdate::from_decision( new VatDecision( $status, $number ), $submitted );
	}

	public function test_empty_and_whitespace_clear_the_number() {
		$this->assertSame( AccountUpdate::CLEARED, $this->result( '', '', '' )['outcome'] );
		$this->assertSame( AccountUpdate::CLEARED, $this->result( '', '', "  \t " )['outcome'] );
	}

	public function test_refused_statuses() {
		foreach ( array( 'invalid', 'country_mismatch', 'invalid_format' ) as $status ) {
			$r = $this->result( $status );
			$this->assertSame( AccountUpdate::REFUSED, $r['outcome'], $status );
			$this->assertSame( $status, $r['reason'] );
		}
	}

	public function test_stored_statuses() {
		foreach ( array( 'valid', 'domestic', 'unverified' ) as $status ) {
			$r = $this->result( $status );
			$this->assertSame( AccountUpdate::STORED, $r['outcome'], $status );
			$this->assertSame( $status, $r['status'] );
		}
	}

	public function test_billing_outside_the_eu_is_stored_without_a_status() {
		$r = $this->result( 'ignored' );

		$this->assertSame( AccountUpdate::STORED, $r['outcome'] );
		$this->assertSame( '', $r['status'] );
	}

	public function test_junk_that_normalises_to_nothing_is_refused() {
		foreach ( array( '-', '  -- ', '!!!' ) as $junk ) {
			$r = AccountUpdate::from_decision( new VatDecision( '', '' ), $junk );
			$this->assertSame( AccountUpdate::REFUSED, $r['outcome'] );
			$this->assertSame( 'unreadable', $r['reason'] );
		}
	}

	public function test_refusal_messages_are_plain_and_distinct() {
		$this->assertStringContainsString( 'billing country', AccountUpdate::refusal_message( 'country_mismatch' ) );
		$this->assertStringContainsString( 'could not be validated', AccountUpdate::refusal_message( 'invalid' ) );
		$this->assertStringContainsString( 'not valid', AccountUpdate::refusal_message( 'invalid_format' ) );
	}

	public function test_saving_the_address_refuses_a_number_from_another_country() {
		$customer = new \WC_Customer( self::factory()->user->create() );
		$customer->set_billing_country( 'DK' );
		$customer->update_meta_data( 'billing_eu_vat_number', 'DE123456789' );

		Account::validate( $customer->get_id(), 'billing', array(), $customer );

		$this->assertSame( 1, wc_notice_count( 'error' ) );
		$this->assertSame( 0, $this->requests );
	}

	public function test_saving_the_address_refuses_an_invalid_number_and_stores_nothing_else() {
		$this->vies = 'invalid';
		$customer   = new \WC_Customer( self::factory()->user->create() );
		$customer->set_billing_country( 'DE' );
		$customer->update_meta_data( 'billing_eu_vat_number', 'DE000000000' );

		Account::validate( $customer->get_id(), 'billing', array(), $customer );

		$this->assertSame( 1, wc_notice_count( 'error' ) );
	}

	public function test_a_valid_number_saves_with_its_proof() {
		$this->vies = 'valid';
		$user       = self::factory()->user->create();
		$customer   = new \WC_Customer( $user );
		$customer->set_billing_country( 'DE' );
		$customer->update_meta_data( 'billing_eu_vat_number', 'DE123456789' );

		Account::validate( $user, 'billing', array(), $customer );
		Account::store( $user, 'billing', array(), $customer );

		$this->assertSame( 0, wc_notice_count( 'error' ) );
		$this->assertSame( 'valid', get_user_meta( $user, '_beltoft_eu_vat_status', true ) );
		$this->assertSame( 'WAPI-TEST-1', get_user_meta( $user, '_beltoft_eu_vat_request_id', true ) );
		$this->assertSame( 1, $this->requests );
	}

	public function test_shipping_address_saves_are_ignored_and_the_value_is_normalised() {
		$customer = new \WC_Customer( self::factory()->user->create() );
		$customer->update_meta_data( 'billing_eu_vat_number', 'DE000000000' );

		Account::validate( $customer->get_id(), 'shipping', array(), $customer );
		$this->assertSame( 0, wc_notice_count( 'error' ) );

		$this->assertSame( 'DE123456789', apply_filters( 'woocommerce_process_myaccount_field_billing_eu_vat_number', 'de 123-456 789' ) );
	}
}
