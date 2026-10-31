<?php
namespace BeltoftEuVat\Tests;

use BeltoftEuVat\AddressFormat;
use BeltoftEuVat\ReverseChargeNote;

class PresentationTest extends TestCase {

	private function order( string $number = '', string $status = '', string $exempt = 'no' ): \WC_Order {
		$order = new \WC_Order();
		$order->set_billing_first_name( 'Anna' );
		$order->set_billing_last_name( 'Test' );
		$order->set_billing_address_1( 'Main Street 1' );
		$order->set_billing_postcode( '8000' );
		$order->set_billing_city( 'Aarhus' );
		$order->set_billing_country( 'DK' );
		$order->set_billing_email( 'christian.beltoft@gmail.com' );
		$order->update_meta_data( '_billing_eu_vat_number', $number );
		$order->update_meta_data( '_beltoft_eu_vat_status', $status );
		$order->update_meta_data( 'is_vat_exempt', $exempt );
		$order->save();

		return $order;
	}

	public function test_every_format_gets_the_placeholder_once() {
		$formats = apply_filters( 'woocommerce_localisation_address_formats', WC()->countries->get_address_formats() );
		$again   = AddressFormat::formats( $formats );

		$this->assertSame( $formats, $again );

		foreach ( $formats as $format ) {
			$this->assertSame( 1, substr_count( $format, '{vat_number}' ) );
		}
	}

	public function test_order_billing_address_shows_the_number() {
		$html = $this->order( 'DK40817921' )->get_formatted_billing_address();

		$this->assertStringContainsString( 'VAT DK40817921', $html );
		$this->assertStringContainsString( 'Aarhus', $html );
	}

	public function test_order_billing_address_without_number_has_no_blank_line() {
		$html = $this->order()->get_formatted_billing_address();

		$this->assertStringNotContainsString( 'VAT', $html );
		$this->assertDoesNotMatchRegularExpression( '#<br/>\s*(<br/>|$)#', $html );
		$this->assertStringEndsWith( 'Denmark', trim( $html ) === $html ? $html : trim( $html ) );
	}

	public function test_shipping_address_never_shows_the_number() {
		$order = $this->order( 'DK40817921' );
		$order->set_shipping_address_1( 'Other Street 2' );
		$order->set_shipping_city( 'Aarhus' );
		$order->set_shipping_country( 'DK' );

		$this->assertStringNotContainsString( 'VAT', $order->get_formatted_shipping_address() );
	}

	public function test_the_label_is_filterable_and_the_value_escaped() {
		add_filter( 'beltoft_eu_vat_address_label', fn() => 'Moms' );

		$this->assertStringContainsString( 'Moms DK40817921', $this->order( 'DK40817921' )->get_formatted_billing_address() );

		remove_all_filters( 'beltoft_eu_vat_address_label' );
		add_filter( 'beltoft_eu_vat_address_label', fn() => '<b>x</b>' );
		$this->assertStringNotContainsString( '<b>x</b>', $this->order( 'DK40817921' )->get_formatted_billing_address() );
		remove_all_filters( 'beltoft_eu_vat_address_label' );
	}

	public function test_my_account_billing_address_shows_the_number() {
		$user = self::factory()->user->create();
		update_user_meta( $user, 'billing_country', 'DK' );
		update_user_meta( $user, 'billing_address_1', 'Main Street 1' );
		update_user_meta( $user, 'billing_city', 'Aarhus' );
		update_user_meta( $user, 'billing_eu_vat_number', 'DK40817921' );
		$address = apply_filters( 'woocommerce_my_account_my_address_formatted_address', array( 'address_1' => 'Main Street 1', 'city' => 'Aarhus', 'country' => 'DK' ), $user, 'billing' );

		$this->assertStringContainsString( 'VAT DK40817921', WC()->countries->get_formatted_address( $address ) );

		$shipping = apply_filters( 'woocommerce_my_account_my_address_formatted_address', array( 'address_1' => 'Main Street 1', 'city' => 'Aarhus', 'country' => 'DK' ), $user, 'shipping' );
		$this->assertStringNotContainsString( 'VAT', WC()->countries->get_formatted_address( $shipping ) );

		delete_user_meta( $user, 'billing_eu_vat_number' );
		$address = apply_filters( 'woocommerce_my_account_my_address_formatted_address', array( 'address_1' => 'Main Street 1', 'city' => 'Aarhus', 'country' => 'DK' ), $user, 'billing' );
		$this->assertStringNotContainsString( 'VAT', WC()->countries->get_formatted_address( $address ) );
	}

	private function email_html( \WC_Order $order, bool $plain ): string {
		ob_start();
		do_action( 'woocommerce_email_after_order_table', $order, false, $plain, null );

		return (string) ob_get_clean();
	}

	public function test_note_only_on_exempt_orders_in_html_and_plain_emails() {
		$exempt = $this->order( 'DE123456789', 'valid', 'yes' );
		$plain  = $this->email_html( $exempt, true );
		$html   = $this->email_html( $exempt, false );

		$this->assertStringContainsString( 'Reverse charge: VAT is to be accounted for by the customer (Article 196, Council Directive 2006/112/EC).', $plain );
		$this->assertStringNotContainsString( '<p', $plain );
		$this->assertStringContainsString( '<p', $html );
		$this->assertStringContainsString( 'Article 196', $html );

		foreach ( array( $this->order(), $this->order( 'DK40817921', 'domestic', 'no' ), $this->order( 'DE123456789', 'valid', 'no' ), $this->order( 'DE123456789', 'unverified', 'no' ), $this->order( 'DE123456789', '', 'yes' ) ) as $other ) {
			$this->assertSame( '', $this->email_html( $other, true ) );
			$this->assertSame( '', $this->email_html( $other, false ) );
		}
	}

	public function test_note_on_order_pages() {
		ob_start();
		do_action( 'woocommerce_order_details_after_order_table', $this->order( 'DE123456789', 'valid', 'yes' ) );
		$this->assertStringContainsString( 'Article 196', (string) ob_get_clean() );

		ob_start();
		do_action( 'woocommerce_order_details_after_order_table', $this->order( 'DK40817921', 'domestic', 'no' ) );
		$this->assertSame( '', (string) ob_get_clean() );
	}

	public function test_note_text_is_filterable_and_can_be_emptied() {
		add_filter( 'beltoft_eu_vat_reverse_charge_text', fn() => 'Omvendt betalingspligt' );
		$this->assertStringContainsString( 'Omvendt betalingspligt', $this->email_html( $this->order( 'DE123456789', 'valid', 'yes' ), true ) );

		remove_all_filters( 'beltoft_eu_vat_reverse_charge_text' );
		add_filter( 'beltoft_eu_vat_reverse_charge_text', '__return_empty_string' );
		$this->assertSame( '', $this->email_html( $this->order( 'DE123456789', 'valid', 'yes' ), true ) );
		remove_all_filters( 'beltoft_eu_vat_reverse_charge_text' );
	}
}
