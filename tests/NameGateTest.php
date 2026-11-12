<?php
namespace BeltoftEuVat\Tests;

use BeltoftEuVat\Decisions;
use BeltoftEuVat\Exemption;
use BeltoftEuVat\Inline;
use BeltoftEuVat\OrderEvidence;
use BeltoftEuVat\ReviewQueue;
use BeltoftEuVat\Settings;

/**
 * The company name must match the name the VAT register holds before VAT is removed.
 */
class NameGateTest extends TestCase {

	/** @var mixed */
	private $customer;

	public function set_up(): void {
		parent::set_up();
		$this->customer = WC()->customer;
		WC()->customer  = new \WC_Customer( 0, true );
		delete_option( Settings::NAME_MATCH );
		delete_option( Settings::NAME_MISSING );
		delete_option( Settings::NAME_DISCLOSE );
	}

	public function tear_down(): void {
		WC()->customer = $this->customer;
		delete_option( Settings::NAME_MATCH );
		delete_option( Settings::NAME_MISSING );
		delete_option( Settings::NAME_DISCLOSE );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	private function cache_name( string $name ): void {
		set_transient(
			\BeltoftEuVat\ViesClient::cache_key( 'DE', '123456789' ),
			array(
				'status'             => 'valid',
				'request_identifier' => 'WAPI-CACHED',
				'registered_name'    => $name,
				'checked_at'         => time() - 3600,
			),
			DAY_IN_SECONDS
		);
		Decisions::reset();
	}

	private function post( string $company, string $first = '', string $last = '' ): void {
		$_POST = array(
			'billing_eu_vat_number' => 'DE123456789',
			'billing_country'       => 'DE',
			'billing_company'       => $company,
			'billing_first_name'    => $first,
			'billing_last_name'     => $last,
		);
	}

	private function ask( string $company = 'ACME GmbH' ): array {
		Decisions::reset();

		return Inline::respond(
			array(
				'security' => wp_create_nonce( Inline::NONCE ),
				'vat'      => 'DE123456789',
				'country'  => 'DE',
				'company'  => $company,
			)
		)['body'];
	}

	public function test_a_matching_company_gets_reverse_charge() {
		$this->cache_name( 'ACME GMBH' );
		$this->post( 'Acme GmbH' );

		$this->assertTrue( Exemption::is_exempt() );
	}

	public function test_another_company_is_charged_vat() {
		$this->cache_name( 'ACME GMBH' );
		$this->post( 'Globex GmbH' );

		$this->assertFalse( Exemption::is_exempt() );
		Exemption::apply();
		$this->assertFalse( WC()->customer->get_is_vat_exempt() );
	}

	public function test_no_company_at_all_is_charged_vat() {
		$this->cache_name( 'ACME GMBH' );
		$this->post( '' );

		$this->assertFalse( Exemption::is_exempt() );
	}

	public function test_a_sole_trader_matches_on_the_billing_name() {
		$this->cache_name( 'Jensen, Hans' );
		$this->post( '', 'Hans', 'Jensen' );

		$this->assertTrue( Exemption::is_exempt() );
	}

	public function test_a_mismatch_does_not_block_the_checkout() {
		$this->cache_name( 'ACME GMBH' );
		$this->post( 'Globex GmbH' );
		$errors = new \WP_Error();

		\BeltoftEuVat\Checkout::validate( array( 'billing_eu_vat_number' => 'DE123456789', 'billing_country' => 'DE' ), $errors );

		$this->assertSame( array(), $errors->get_error_codes() );
	}

	public function test_the_refresh_tells_the_customer_the_registered_name() {
		$this->cache_name( 'ACME GMBH' );
		do_action( 'woocommerce_checkout_update_order_review', 'billing_eu_vat_number=DE123456789&billing_country=DE&billing_company=Globex+GmbH' );
		$html = apply_filters( 'woocommerce_update_order_review_fragments', array() )['.beltoft-eu-vat-message'];

		$this->assertStringContainsString( 'This VAT number belongs to ACME GMBH. Enter that company name to buy without VAT, or continue with VAT.', $html );
		$this->assertStringNotContainsString( 'confirmed', $html );
	}

	public function test_the_refresh_confirms_when_the_name_matches() {
		$this->cache_name( 'ACME GMBH' );
		do_action( 'woocommerce_checkout_update_order_review', 'billing_eu_vat_number=DE123456789&billing_country=DE&billing_company=Acme' );
		$html = apply_filters( 'woocommerce_update_order_review_fragments', array() )['.beltoft-eu-vat-message'];

		$this->assertStringContainsString( 'VAT number confirmed. VAT is not charged (reverse charge).', $html );
	}

	public function test_the_inline_answer_shows_the_name_by_default_and_asks_for_that_company() {
		$this->cache_name( 'ACME GMBH' );
		$body = $this->ask( 'Globex' );

		$this->assertSame( 'name', $body['state'] );
		$this->assertSame( 'This VAT number belongs to ACME GMBH. Enter that company name to buy without VAT, or continue with VAT.', $body['text'] );
		$this->assertFalse( $body['retry'] );
	}

	public function test_the_inline_answer_does_not_leak_the_name_when_disclosure_is_off() {
		update_option( Settings::NAME_DISCLOSE, 'no' );
		$this->cache_name( 'ACME GMBH' );
		$body = $this->ask( 'Globex' );

		$this->assertSame( 'name', $body['state'] );
		$this->assertStringNotContainsString( 'ACME', wp_json_encode( $body ) );
		$this->assertSame( 'The company name does not match this VAT number in the EU register. Check the company name to buy without VAT, or continue with VAT.', $body['text'] );

		do_action( 'woocommerce_checkout_update_order_review', 'billing_eu_vat_number=DE123456789&billing_country=DE&billing_company=Globex' );
		$this->assertStringNotContainsString( 'ACME', apply_filters( 'woocommerce_update_order_review_fragments', array() )['.beltoft-eu-vat-message'] );
	}

	public function test_the_inline_answer_with_a_match_confirms() {
		$this->cache_name( 'ACME GMBH' );
		$body = $this->ask( 'ACME' );

		$this->assertSame( 'valid', $body['state'] );
		$this->assertSame( 'Confirmed: ACME GMBH. No VAT is charged (reverse charge).', $body['text'] );
	}

	public function test_a_register_without_a_name_keeps_todays_behaviour_by_default() {
		foreach ( array( '', '---', '  ' ) as $name ) {
			$this->cache_name( $name );
			$this->post( 'Globex GmbH' );

			$this->assertTrue( Exemption::is_exempt(), "name '$name'" );
		}

		$this->assertSame( 'Confirmed. No VAT is charged (reverse charge).', $this->ask( 'Globex' )['text'] );
	}

	public function test_a_register_without_a_name_can_charge_vat_by_setting() {
		update_option( Settings::NAME_MISSING, Settings::CHARGE );
		$this->cache_name( '---' );
		$this->post( 'Globex GmbH' );

		$this->assertFalse( Exemption::is_exempt() );
		$this->assertSame( 'The EU register shows no company name for this VAT number, so VAT is charged.', $this->ask( 'Globex' )['text'] );
	}

	public function test_with_the_check_off_everything_is_as_before() {
		update_option( Settings::NAME_MATCH, 'no' );
		$this->cache_name( 'ACME GMBH' );
		$this->post( 'Globex GmbH' );

		$this->assertTrue( Exemption::is_exempt() );
		$this->assertSame( 'valid', $this->ask( 'Globex' )['state'] );

		$order = new \WC_Order();
		OrderEvidence::attach( $order, array( 'billing_eu_vat_number' => 'DE123456789', 'billing_country' => 'DE', 'billing_company' => 'Globex' ) );
		$this->assertSame( '', $order->get_meta( OrderEvidence::NAME_MATCH ) );
	}

	public function test_a_site_rule_can_accept_a_name() {
		$this->cache_name( 'ACME GMBH' );
		$this->post( 'Globex GmbH' );
		add_filter( 'beltoft_eu_vat_name_matches', static fn( $m, $entered ) => 'Globex GmbH' === $entered, 10, 2 );

		$this->assertTrue( Exemption::is_exempt() );
		remove_all_filters( 'beltoft_eu_vat_name_matches' );
	}

	public function test_evidence_of_a_match_is_stored() {
		$this->cache_name( 'ACME GMBH' );
		Decisions::get( 'DE123456789', 'DE', true );
		$order = new \WC_Order();
		$order->save();

		OrderEvidence::attach( $order, array( 'billing_eu_vat_number' => 'DE123456789', 'billing_country' => 'DE', 'billing_company' => 'Acme GmbH' ) );

		$this->assertSame( 'ACME GMBH', $order->get_meta( OrderEvidence::NAME ) );
		$this->assertSame( 'Acme GmbH', $order->get_meta( OrderEvidence::ENTERED_NAME ) );
		$this->assertSame( 'yes', $order->get_meta( OrderEvidence::NAME_MATCH ) );
		$this->assertSame( 'equal', $order->get_meta( OrderEvidence::NAME_REASON ) );
	}

	public function test_evidence_of_a_mismatch_is_stored_with_a_note_and_no_review_flag() {
		$this->cache_name( 'ACME GMBH' );
		Decisions::get( 'DE123456789', 'DE', true );
		$order = new \WC_Order();
		$order->save();

		OrderEvidence::attach( $order, array( 'billing_eu_vat_number' => 'DE123456789', 'billing_country' => 'DE', 'billing_company' => 'Globex' ) );
		$order->save();

		$this->assertSame( 'no', $order->get_meta( OrderEvidence::NAME_MATCH ) );
		$this->assertSame( 'mismatch', $order->get_meta( OrderEvidence::NAME_REASON ) );
		$this->assertSame( 'Globex', $order->get_meta( OrderEvidence::ENTERED_NAME ) );
		$this->assertSame( '', $order->get_meta( OrderEvidence::REVIEW ), 'a mismatch is not a review case' );
		$this->assertSame( array(), ReviewQueue::pending() );
		$notes = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );
		$this->assertStringContainsString( 'does not match the VAT register', $notes[0]->content );
	}

	public function test_the_missing_name_is_recorded_as_such() {
		$this->cache_name( '' );
		Decisions::get( 'DE123456789', 'DE', true );
		$order = new \WC_Order();

		OrderEvidence::attach( $order, array( 'billing_eu_vat_number' => 'DE123456789', 'billing_country' => 'DE', 'billing_company' => 'Globex' ) );

		$this->assertSame( 'yes', $order->get_meta( OrderEvidence::NAME_MATCH ) );
		$this->assertSame( 'no name available', $order->get_meta( OrderEvidence::NAME_REASON ) );
	}

	public function test_a_person_match_records_the_person_as_entered() {
		$this->cache_name( 'JENSEN, HANS' );
		Decisions::get( 'DE123456789', 'DE', true );
		$order = new \WC_Order();

		OrderEvidence::attach( $order, array( 'billing_eu_vat_number' => 'DE123456789', 'billing_country' => 'DE', 'billing_first_name' => 'Hans', 'billing_last_name' => 'Jensen' ) );

		$this->assertSame( 'Hans Jensen', $order->get_meta( OrderEvidence::ENTERED_NAME ) );
		$this->assertSame( 'person name', $order->get_meta( OrderEvidence::NAME_REASON ) );
	}

	public function test_the_admin_order_box_shows_the_name_evidence() {
		$order = new \WC_Order();
		$order->update_meta_data( OrderEvidence::STATUS, 'valid' );
		$order->update_meta_data( OrderEvidence::NAME, 'ACME GMBH' );
		$order->update_meta_data( OrderEvidence::ENTERED_NAME, 'Globex' );
		$order->update_meta_data( OrderEvidence::NAME_MATCH, 'no' );
		$order->update_meta_data( OrderEvidence::NAME_REASON, 'mismatch' );

		ob_start();
		ReviewQueue::order_panel( $order );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Company entered:</strong> Globex', $html );
		$this->assertStringContainsString( 'Name matches register:</strong> No (mismatch)', $html );
	}

	public function test_a_renewal_is_not_judged_again() {
		// The plugin has no renewal hook: a renewal keeps what the subscription carried. Nothing here
		// runs on woocommerce_checkout_create_order for an order that is not a checkout, and the
		// nightly re-check only refreshes the account's evidence.
		$this->cache_name( 'ACME GMBH' );
		$user = self::factory()->user->create();
		update_user_meta( $user, 'billing_eu_vat_number', 'DE123456789' );
		update_user_meta( $user, \BeltoftEuVat\Evidence::STATUS, 'valid' );

		\BeltoftEuVat\Recheck::check_user( $user );

		$this->assertSame( 'valid', get_user_meta( $user, \BeltoftEuVat\Evidence::STATUS, true ) );
		$this->assertSame( '', get_user_meta( $user, \BeltoftEuVat\Evidence::FLAG, true ) );

		$order = new \WC_Order();
		$order->update_meta_data( 'is_vat_exempt', 'yes' );
		$order->update_meta_data( OrderEvidence::STATUS, 'valid' );
		$order->save();
		$this->assertTrue( \BeltoftEuVat\ReverseChargeNote::applies( wc_get_order( $order->get_id() ) ) );
	}

	public function test_the_defaults_give_the_owners_rule() {
		$this->assertTrue( Settings::name_match() );
		$this->assertSame( Settings::ALLOW, Settings::name_missing() );
		$this->assertTrue( Settings::name_disclose() );
	}

	public function test_the_settings_are_on_the_settings_screen() {
		$ids = wp_list_pluck( \BeltoftEuVat\SettingsPage::settings( array(), \BeltoftEuVat\SettingsPage::SECTION ), 'id' );

		foreach ( array( Settings::NAME_MATCH, Settings::NAME_MISSING, Settings::NAME_DISCLOSE ) as $id ) {
			$this->assertContains( $id, $ids );
		}

		$this->assertSame( Settings::ALLOW, \BeltoftEuVat\SettingsPage::sanitize_name_missing( 'junk' ) );
		$this->assertSame( Settings::CHARGE, \BeltoftEuVat\SettingsPage::sanitize_name_missing( 'charge' ) );
	}
}
