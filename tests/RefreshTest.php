<?php
namespace BeltoftEuVat\Tests;

use BeltoftEuVat\Checkout;
use BeltoftEuVat\Decisions;
use BeltoftEuVat\Exemption;

/**
 * The checkout refresh never calls VIES.
 */
class RefreshTest extends TestCase {

	private function refresh( string $number, string $country ): string {
		do_action( 'woocommerce_checkout_update_order_review', 'billing_eu_vat_number=' . rawurlencode( $number ) . '&billing_country=' . $country );
		$fragments = apply_filters( 'woocommerce_update_order_review_fragments', array() );

		return $fragments['.beltoft-eu-vat-message'] ?? '';
	}

	public function test_a_refresh_with_nothing_cached_makes_no_http_call_and_charges_vat_with_a_pending_note() {
		$this->vies = 'valid';
		$html       = $this->refresh( 'DE123456789', 'DE' );

		$this->assertSame( 0, $this->requests );
		$this->assertStringContainsString( 'VAT is charged until your VAT number has been checked.', $html );
		$this->assertFalse( Exemption::is_exempt() );
	}

	public function test_a_refresh_uses_a_cached_valid_answer_without_http() {
		$this->cache_valid( 'DE', '123456789' );
		$html = $this->refresh( 'DE123456789', 'DE' );

		$this->assertSame( 0, $this->requests );
		$this->assertStringContainsString( 'VAT number confirmed', $html );
		$this->assertTrue( Exemption::is_exempt() );
	}

	public function test_repeated_refreshes_never_wait_on_vies_even_when_it_is_down() {
		for ( $i = 0; $i < 3; $i++ ) {
			Checkout::reset();
			Decisions::reset();
			$this->refresh( 'DE123456789', 'DE' );
		}

		$this->assertSame( 0, $this->requests );
	}

	public function test_a_refresh_with_a_domestic_number_makes_no_http_call() {
		$this->refresh( 'DK12345678', 'DK' );

		$this->assertSame( 0, $this->requests );
	}

	public function test_a_cached_invalid_answer_still_shows_the_error_after_a_refresh() {
		$this->vies = 'invalid';
		\BeltoftEuVat\ViesClient::check( 'DE', '000000000' );
		$this->requests = 0;

		$this->assertStringContainsString( 'could not be validated', $this->refresh( 'DE000000000', 'DE' ) );
		$this->assertSame( 0, $this->requests );
	}

	public function test_place_order_uses_the_cache_and_is_instant() {
		$this->cache_valid( 'DE', '123456789' );
		$_POST = array( 'billing_eu_vat_number' => 'DE123456789', 'billing_country' => 'DE' );

		do_action( 'woocommerce_checkout_process' );

		$this->assertSame( 0, $this->requests );
		$this->assertTrue( Exemption::is_exempt() );
	}

	public function test_place_order_asks_vies_only_when_nothing_is_cached() {
		$this->vies = 'valid';
		$_POST      = array( 'billing_eu_vat_number' => 'DE123456789', 'billing_country' => 'DE' );

		do_action( 'woocommerce_checkout_process' );

		$this->assertSame( 1, $this->requests );
		$this->assertTrue( Exemption::is_exempt() );
	}

	public function test_place_order_with_vies_down_and_no_grace_is_flagged_as_before() {
		$_POST = array( 'billing_eu_vat_number' => 'DE123456789', 'billing_country' => 'DE' );
		do_action( 'woocommerce_checkout_process' );

		$order = new \WC_Order();
		$order->save();
		\BeltoftEuVat\OrderEvidence::attach( $order, array( 'billing_eu_vat_number' => 'DE123456789', 'billing_country' => 'DE' ) );

		$this->assertSame( 'unverified', $order->get_meta( '_beltoft_eu_vat_status' ) );
		$this->assertSame( 'yes', $order->get_meta( '_beltoft_eu_vat_review' ) );
		$this->assertFalse( Exemption::is_exempt() );
	}
}
