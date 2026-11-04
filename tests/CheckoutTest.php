<?php
namespace BeltoftEuVat\Tests;

use BeltoftEuVat\Checkout;
use BeltoftEuVat\Settings;

class CheckoutTest extends TestCase {

	/**
	 * Puts what the inline check would have cached in the cache, then refreshes.
	 */
	private function refresh_after_check( string $number, string $country ): string {
		\BeltoftEuVat\Decisions::get( $number, $country, true );
		\BeltoftEuVat\Decisions::reset();
		$this->requests = 0;

		return $this->refresh( $number, $country );
	}

	private function refresh( string $number, string $country ): string {
		do_action( 'woocommerce_checkout_update_order_review', 'billing_eu_vat_number=' . rawurlencode( $number ) . '&billing_country=' . $country );
		$fragments = apply_filters( 'woocommerce_update_order_review_fragments', array() );

		return $fragments['.beltoft-eu-vat-message'] ?? '';
	}

	private function errors( array $data ): \WP_Error {
		$errors = new \WP_Error();
		do_action_ref_array( 'woocommerce_after_checkout_validation', array( $data, &$errors ) );

		return $errors;
	}

	public function test_valid_number_is_confirmed_under_the_field() {
		$this->vies = 'valid';
		$html       = $this->refresh_after_check( 'DE123456789', 'DE' );

		$this->assertStringContainsString( 'VAT number confirmed. VAT is not charged (reverse charge).', $html );
		$this->assertStringContainsString( 'class="beltoft-eu-vat-message is-valid"', $html );
	}

	public function test_domestic_number_message_names_the_seller_country() {
		$html = $this->refresh( 'DK12345678', 'DK' );

		$this->assertStringContainsString( 'Danish VAT is charged on domestic sales.', $html );
		$this->assertStringNotContainsString( 'confirmed', $html );
	}

	public function test_a_confirmed_domestic_number_says_so() {
		$this->vies = 'valid';
		$html       = $this->refresh_after_check( 'DK12345678', 'DK' );

		$this->assertStringContainsString( 'VAT number confirmed. Danish VAT is charged on domestic sales.', $html );
	}

	public function test_an_invalid_domestic_number_shows_the_invalid_message() {
		$this->vies = 'invalid';
		$html       = $this->refresh_after_check( 'DK12345678', 'DK' );

		$this->assertStringContainsString( 'could not be validated', $html );
	}

	public function test_mismatch_and_invalid_show_the_error() {
		$this->assertStringContainsString( 'different country than your billing address', $this->refresh( 'DE123456789', 'DK' ) );

		Checkout::reset();
		$this->vies = 'invalid';
		$this->assertStringContainsString( 'could not be validated', $this->refresh_after_check( 'DE000000000', 'DE' ) );
	}

	public function test_an_empty_field_clears_the_message() {
		$html = $this->refresh( '', 'DE' );

		$this->assertStringContainsString( '<span class="beltoft-eu-vat-message" role="status" aria-live="polite"></span>', $html );
	}

	public function test_no_message_for_a_non_eu_billing_country() {
		$this->assertStringNotContainsString( 'VAT', $this->refresh( 'DE123456789', 'US' ) );
	}

	public function test_a_refresh_without_the_field_changes_nothing() {
		do_action( 'woocommerce_checkout_update_order_review', 'billing_city=Aarhus' );

		$this->assertSame( array( 'a' => 'b' ), apply_filters( 'woocommerce_update_order_review_fragments', array( 'a' => 'b' ) ) );
	}

	public function test_one_vies_call_per_number_across_refresh_validation_and_evidence() {
		$this->vies = 'invalid';
		$this->refresh( 'DE000000000', 'DE' );
		$errors = $this->errors( array( 'billing_eu_vat_number' => 'DE000000000', 'billing_country' => 'DE' ) );

		$this->assertSame( 1, $this->requests );
		$this->assertSame( array( 'beltoft_eu_vat_number' ), $errors->get_error_codes() );
	}

	public function test_submit_blocks_invalid_and_mismatch() {
		$this->vies = 'invalid';
		$this->assertNotEmpty( $this->errors( array( 'billing_eu_vat_number' => 'DE000000000', 'billing_country' => 'DE' ) )->get_error_codes() );

		$this->assertNotEmpty( $this->errors( array( 'billing_eu_vat_number' => 'DE123456789', 'billing_country' => 'DK' ) )->get_error_codes() );
	}

	public function test_submit_accepts_valid_empty_and_domestic() {
		$this->vies = 'valid';
		$this->assertEmpty( $this->errors( array( 'billing_eu_vat_number' => 'DE123456789', 'billing_country' => 'DE' ) )->get_error_codes() );
		$this->assertEmpty( $this->errors( array( 'billing_eu_vat_number' => '', 'billing_country' => 'DE' ) )->get_error_codes() );
		$this->assertEmpty( $this->errors( array( 'billing_eu_vat_number' => 'DK12345678', 'billing_country' => 'DK' ) )->get_error_codes() );
	}

	public function test_unreachable_vies_proceeds_by_default() {
		$errors = $this->errors( array( 'billing_eu_vat_number' => 'DE123456789', 'billing_country' => 'DE' ) );

		$this->assertEmpty( $errors->get_error_codes() );
		$this->assertStringContainsString( 'order will be reviewed', $this->refresh( 'DE123456789', 'DE' ) );
	}

	public function test_unreachable_vies_blocks_when_the_setting_says_so() {
		update_option( Settings::ON_UNREACHABLE, 'block' );
		$data   = array( 'billing_eu_vat_number' => 'DE123456789', 'billing_country' => 'DE' );
		$errors = $this->errors( $data );

		$this->assertSame( array( 'beltoft_eu_vat_number' ), $errors->get_error_codes() );
		$this->assertStringContainsString( 'could not be checked right now', $errors->get_error_message() );
	}

	public function test_posted_data_is_normalised_and_dropped_for_non_eu_billing() {
		$data = apply_filters( 'woocommerce_checkout_posted_data', array( 'billing_eu_vat_number' => ' de 123-456 789 ', 'billing_country' => 'DE' ) );
		$this->assertSame( 'DE123456789', $data['billing_eu_vat_number'] );

		$data = apply_filters( 'woocommerce_checkout_posted_data', array( 'billing_eu_vat_number' => 'DE123456789', 'billing_country' => 'US' ) );
		$this->assertSame( '', $data['billing_eu_vat_number'] );

		$data = apply_filters( 'woocommerce_checkout_posted_data', array( 'billing_city' => 'x' ) );
		$this->assertArrayNotHasKey( 'billing_eu_vat_number', $data );
	}

	public function test_submission_asks_vies_before_totals() {
		$this->vies                     = 'valid';
		$_POST['billing_eu_vat_number'] = 'DE123456789';
		$_POST['billing_country']       = 'DE';

		do_action( 'woocommerce_checkout_process' );

		$this->assertSame( 1, $this->requests );
		$this->assertSame( 'valid', \BeltoftEuVat\Decisions::get( 'DE123456789', 'DE' )->status );
	}

	public function test_customer_evidence_follows_the_saved_number() {
		$this->vies = 'valid';
		$user       = self::factory()->user->create();
		$customer   = new \WC_Customer( $user );
		$data       = array( 'billing_eu_vat_number' => 'DE123456789', 'billing_country' => 'DE' );
		\BeltoftEuVat\Decisions::get( 'DE123456789', 'DE', true );

		do_action( 'woocommerce_checkout_update_customer', $customer, $data );

		$this->assertSame( 'valid', get_user_meta( $user, '_beltoft_eu_vat_status', true ) );
		$this->assertSame( 'WAPI-TEST-1', get_user_meta( $user, '_beltoft_eu_vat_request_id', true ) );
	}
}
