<?php
namespace BeltoftEuVat\Tests;

use BeltoftEuVat\Evidence;
use BeltoftEuVat\Field;
use BeltoftEuVat\OrderEvidence;

/**
 * The storage keys the live site already holds. Changing any of these breaks existing data.
 */
class CompatibilityTest extends TestCase {

	public function test_keys_are_exactly_the_old_ones() {
		$this->assertSame( 'billing_eu_vat_number', Field::KEY );
		$this->assertSame( 'billing_eu_vat_number_field', Field::WRAPPER );
		$this->assertSame( '_wc_other/alg_eu_vat/billing_eu_vat_number', Evidence::LEGACY_USER_META );
	}

	public function test_evidence_keys_use_the_new_prefix() {
		foreach ( array( OrderEvidence::STATUS, OrderEvidence::REQUEST_ID, OrderEvidence::NAME, OrderEvidence::CHECKED_AT, OrderEvidence::COUNTRY, OrderEvidence::REVIEW, Evidence::STATUS, Evidence::REQUEST_ID, Evidence::NAME, Evidence::CHECKED_AT ) as $key ) {
			$this->assertStringStartsWith( '_beltoft_eu_vat_', $key );
		}
	}

	public function test_woocommerce_saves_the_field_as_user_meta_billing_eu_vat_number() {
		$user     = self::factory()->user->create();
		$customer = new \WC_Customer( $user );
		$customer->update_meta_data( 'billing_eu_vat_number', 'DE123456789' );
		$customer->save();

		$this->assertSame( 'DE123456789', get_user_meta( $user, 'billing_eu_vat_number', true ) );
	}

	public function test_the_checkout_data_key_reaches_the_order_as_underscore_billing_eu_vat_number() {
		$checkout = WC()->checkout();
		$method   = new \ReflectionMethod( $checkout, 'create_order' );
		$method->setAccessible( true );

		if ( ! WC()->cart ) {
			$this->markTestSkipped( 'No cart in this environment.' );
		}

		$product = new \WC_Product_Simple();
		$product->set_name( 'Test' );
		$product->set_regular_price( '10' );
		$product->save();
		WC()->cart->empty_cart();
		WC()->cart->add_to_cart( $product->get_id() );

		$data     = array_merge(
			$checkout->get_posted_data() ?: array(),
			array(
				'billing_eu_vat_number' => 'DE123456789',
				'billing_country'       => 'DE',
				'billing_email'         => 'christian.beltoft@gmail.com',
				'payment_method'        => 'cod',
			)
		);
		$order_id = $method->invoke( $checkout, $data );
		$this->assertIsInt( $order_id );

		$order = wc_get_order( $order_id );
		$this->assertSame( 'DE123456789', $order->get_meta( '_billing_eu_vat_number' ) );
		$this->assertContains( $order->get_meta( 'is_vat_exempt' ), array( 'yes', 'no' ) );
		$this->assertSame( 'unverified', $order->get_meta( '_beltoft_eu_vat_status' ), 'evidence written during create_order' );
		WC()->cart->empty_cart();
	}
}
