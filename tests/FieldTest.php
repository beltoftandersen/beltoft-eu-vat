<?php
namespace BeltoftEuVat\Tests;

use BeltoftEuVat\Field;
use BeltoftEuVat\Settings;

class FieldTest extends TestCase {

	public function test_field_is_a_billing_field_after_company() {
		$fields = WC()->countries->get_address_fields( 'DK', 'billing_' );

		$this->assertArrayHasKey( 'billing_eu_vat_number', $fields );
		$this->assertFalse( $fields['billing_eu_vat_number']['required'] );
		$this->assertSame( 'EU VAT number', $fields['billing_eu_vat_number']['label'] );
		$this->assertGreaterThan( $fields['billing_company']['priority'], $fields['billing_eu_vat_number']['priority'] );
		$this->assertLessThan( $fields['billing_address_1']['priority'], $fields['billing_eu_vat_number']['priority'] );
	}

	public function test_label_comes_from_the_setting_and_a_filter() {
		update_option( Settings::LABEL, 'VAT ID' );
		$this->assertSame( 'VAT ID', WC()->countries->get_address_fields( 'DK', 'billing_' )['billing_eu_vat_number']['label'] );

		add_filter( 'beltoft_eu_vat_label', static fn() => 'Moms' );
		$this->assertSame( 'Moms', Settings::label() );
		remove_all_filters( 'beltoft_eu_vat_label' );
	}

	public function test_rendered_markup_keeps_the_wrapper_id_and_has_a_result_slot_inside() {
		$fields = WC()->countries->get_address_fields( 'DK', 'billing_' );
		$args   = array_merge( $fields['billing_eu_vat_number'], array( 'return' => true ) );
		$html   = woocommerce_form_field( 'billing_eu_vat_number', $args, '' );

		$this->assertStringContainsString( 'id="billing_eu_vat_number_field"', $html );
		$this->assertStringContainsString( 'name="billing_eu_vat_number"', $html );
		$this->assertStringContainsString( 'id="billing_eu_vat_number"', $html );
		$this->assertMatchesRegularExpression( '#<span class="beltoft-eu-vat-message"[^>]*></span></p>$#', trim( $html ) );
	}

	public function test_checkout_prefills_from_account_and_old_plugin_meta() {
		$user = self::factory()->user->create();
		wp_set_current_user( $user );
		update_user_meta( $user, '_wc_other/alg_eu_vat/billing_eu_vat_number', 'FR12345678901' );

		$this->assertSame( 'FR12345678901', apply_filters( 'woocommerce_checkout_get_value', null, 'billing_eu_vat_number' ) );

		update_user_meta( $user, 'billing_eu_vat_number', 'DE123456789' );
		$this->assertSame( 'DE123456789', apply_filters( 'woocommerce_checkout_get_value', null, 'billing_eu_vat_number' ) );
		$this->assertNull( apply_filters( 'woocommerce_checkout_get_value', null, 'billing_city' ) );
		wp_set_current_user( 0 );
	}

	public function test_account_form_falls_back_to_old_meta() {
		$user = self::factory()->user->create();
		wp_set_current_user( $user );
		update_user_meta( $user, '_wc_other/alg_eu_vat/billing_eu_vat_number', 'FR12345678901' );

		$this->assertSame( 'FR12345678901', apply_filters( 'woocommerce_my_account_edit_address_field_value', '', 'billing_eu_vat_number', 'billing' ) );
		wp_set_current_user( 0 );
	}

	public function test_admin_order_screen_shows_the_number() {
		$this->assertArrayHasKey( 'eu_vat_number', apply_filters( 'woocommerce_admin_billing_fields', array() ) );
	}
}
