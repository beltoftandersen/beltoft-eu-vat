<?php
namespace BeltoftEuVat\Tests;

use BeltoftEuVat\Evidence;
use BeltoftEuVat\Field;

/**
 * My Account > Addresses: the inline line and the stored status under the field.
 */
class AccountStatusTest extends TestCase {

	private function form_field( int $user ): string {
		wp_set_current_user( $user );
		$GLOBALS['wp']->query_vars['edit-address'] = 'billing';

		return woocommerce_form_field( 'billing_eu_vat_number', array( 'type' => 'text', 'label' => 'EU VAT number', 'return' => true ), '' );
	}

	public function tear_down(): void {
		unset( $GLOBALS['wp']->query_vars['edit-address'] );
		parent::tear_down();
	}

	public function test_the_stored_confirmation_shows_date_and_reference() {
		$user = self::factory()->user->create();
		update_user_meta( $user, 'billing_eu_vat_number', 'DE123456789' );
		update_user_meta( $user, Evidence::STATUS, 'valid' );
		update_user_meta( $user, Evidence::REQUEST_ID, 'WAPI-777' );
		update_user_meta( $user, Evidence::CHECKED_AT, '2026-11-04T09:30:00+00:00' );

		$html = $this->form_field( $user );

		$this->assertStringContainsString( '<span class="beltoft-eu-vat-stored">Confirmed on 4 Nov 2026, reference WAPI-777</span>', $html );
		$this->assertStringContainsString( 'class="beltoft-eu-vat-status"', $html );
		$this->assertStringContainsString( 'aria-live="polite"', $html );
	}

	public function test_a_confirmation_without_a_reference_shows_only_the_date() {
		$user = self::factory()->user->create();
		update_user_meta( $user, 'billing_eu_vat_number', 'DE123456789' );
		update_user_meta( $user, Evidence::STATUS, 'valid' );
		update_user_meta( $user, Evidence::CHECKED_AT, '2026-11-04T09:30:00+00:00' );

		$this->assertStringContainsString( 'Confirmed on 4 Nov 2026</span>', $this->form_field( $user ) );
	}

	public function test_anything_else_is_not_confirmed() {
		foreach ( array( 'unverified', 'invalid', '' ) as $status ) {
			$user = self::factory()->user->create();
			update_user_meta( $user, 'billing_eu_vat_number', 'DE123456789' );
			update_user_meta( $user, Evidence::STATUS, $status );
			update_user_meta( $user, Evidence::CHECKED_AT, '2026-11-04T09:30:00+00:00' );

			$this->assertStringContainsString( '<span class="beltoft-eu-vat-stored">Not confirmed</span>', $this->form_field( $user ), $status );
		}
	}

	public function test_a_number_the_nightly_check_flagged_is_not_confirmed() {
		$user = self::factory()->user->create();
		update_user_meta( $user, 'billing_eu_vat_number', 'DE123456789' );
		update_user_meta( $user, Evidence::STATUS, 'valid' );
		update_user_meta( $user, Evidence::CHECKED_AT, '2026-11-04T09:30:00+00:00' );
		update_user_meta( $user, Evidence::FLAG, 'invalid' );

		$this->assertStringContainsString( 'Not confirmed', $this->form_field( $user ) );
	}

	public function test_no_stored_line_without_a_stored_number_or_outside_my_account() {
		$user = self::factory()->user->create();
		$this->assertStringNotContainsString( 'beltoft-eu-vat-stored', $this->form_field( $user ) );

		update_user_meta( $user, 'billing_eu_vat_number', 'DE123456789' );
		unset( $GLOBALS['wp']->query_vars['edit-address'] );
		wp_set_current_user( $user );
		$html = woocommerce_form_field( 'billing_eu_vat_number', array( 'type' => 'text', 'label' => 'x', 'return' => true ), '' );
		$this->assertStringNotContainsString( 'beltoft-eu-vat-stored', $html );
	}

	public function test_the_inline_check_works_from_my_account_too() {
		$this->vies = 'valid';
		$reply      = \BeltoftEuVat\Inline::respond( array( 'security' => wp_create_nonce( \BeltoftEuVat\Inline::NONCE ), 'vat' => 'DE123456789', 'country' => 'DE' ) );

		$this->assertSame( 'valid', $reply['body']['state'] );
	}

	public function test_the_script_is_also_loaded_on_the_address_form_with_the_account_context() {
		wp_dequeue_script( 'beltoft-eu-vat' );
		wp_deregister_script( 'beltoft-eu-vat' );
		$GLOBALS['wp']->query_vars['edit-address'] = 'billing';

		Field::assets();

		$this->assertTrue( wp_script_is( 'beltoft-eu-vat', 'enqueued' ) );
		$data = wp_scripts()->get_data( 'beltoft-eu-vat', 'data' );
		$this->assertStringContainsString( '"context":"account"', $data );
		$this->assertStringContainsString( 'wc-ajax=beltoft_eu_vat_check', str_replace( '\\/', '/', $data ) );
	}
}
