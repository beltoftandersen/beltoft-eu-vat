<?php
namespace BeltoftEuVat\Tests;

use BeltoftEuVat\Exemption;

class ExemptionTest extends TestCase {

	/** @var mixed */
	private $customer;

	public function set_up(): void {
		parent::set_up();
		$this->customer = WC()->customer;
		WC()->customer  = new \WC_Customer( 0, true );
	}

	public function tear_down(): void {
		WC()->customer = $this->customer;
		remove_all_filters( 'beltoft_eu_vat_destination' );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	private function post( string $number, string $country ): void {
		$_POST['billing_eu_vat_number'] = $number;
		$_POST['billing_country']       = $country;
	}

	public function test_valid_cached_number_in_its_own_country_is_exempt() {
		$this->cache_valid( 'DE', '123456789' );
		$this->post( 'DE123456789', 'DE' );

		$this->assertTrue( Exemption::is_exempt() );
		Exemption::apply();
		$this->assertTrue( WC()->customer->get_is_vat_exempt() );
		$this->assertSame( 0, $this->requests, 'totals never call VIES' );
	}

	public function test_fail_closed_without_a_cached_answer() {
		$this->post( 'DE123456789', 'DE' );

		$this->assertFalse( Exemption::is_exempt() );
		$this->assertSame( 0, $this->requests );
	}

	public function test_unreachable_vies_is_not_exempt() {
		$this->post( 'DE123456789', 'DE' );
		\BeltoftEuVat\Decisions::get( 'DE123456789', 'DE', true );

		$this->assertFalse( Exemption::is_exempt() );
	}

	public function test_a_number_of_the_sellers_country_is_never_exempt() {
		$this->post( 'DK12345678', 'DK' );
		$this->assertFalse( Exemption::is_exempt() );
	}

	public function test_a_mismatched_country_is_not_exempt() {
		$this->cache_valid( 'DE', '123456789' );
		$this->post( 'DE123456789', 'DK' );

		$this->assertFalse( Exemption::is_exempt() );
	}

	public function test_goods_leaving_the_eu_are_not_reverse_charged() {
		$this->cache_valid( 'DE', '123456789' );
		$this->post( 'DE123456789', 'DE' );
		add_filter( 'beltoft_eu_vat_destination', static fn() => 'CH' );

		$this->assertFalse( Exemption::is_exempt() );
	}

	public function test_goods_delivered_to_the_sellers_country_are_not_reverse_charged() {
		$this->cache_valid( 'DE', '123456789' );
		$this->post( 'DE123456789', 'DE' );
		add_filter( 'beltoft_eu_vat_destination', static fn() => 'DK' );

		$this->assertFalse( Exemption::is_exempt() );
	}

	public function test_an_emptied_field_beats_the_account_number() {
		$user = self::factory()->user->create();
		update_user_meta( $user, 'billing_eu_vat_number', 'DE123456789' );
		$this->cache_valid( 'DE', '123456789' );
		WC()->customer = new \WC_Customer( $user );
		WC()->customer->set_billing_country( 'DE' );

		$this->assertTrue( Exemption::is_exempt(), 'account number is used when nothing was posted' );

		$this->post( '', 'DE' );
		$this->assertFalse( Exemption::is_exempt() );
	}

	public function test_apply_switches_off_only_what_it_switched_on() {
		$this->cache_valid( 'DE', '123456789' );
		$this->post( 'DE123456789', 'DE' );
		Exemption::apply();
		$this->assertTrue( WC()->customer->get_is_vat_exempt() );

		$this->post( '', 'DE' );
		Exemption::apply();
		$this->assertFalse( WC()->customer->get_is_vat_exempt() );

		// A flag set by other code stays.
		WC()->customer->set_is_vat_exempt( true );
		Exemption::apply();
		$this->assertTrue( WC()->customer->get_is_vat_exempt() );
	}

	public function test_the_checkout_refresh_applies_the_exemption_before_totals() {
		$this->vies = 'valid';

		do_action( 'woocommerce_checkout_update_order_review', 'billing_eu_vat_number=DE123456789&billing_country=DE' );

		$this->assertTrue( WC()->customer->get_is_vat_exempt() );
		$this->assertSame( 1, $this->requests );
	}
}
