<?php
namespace BeltoftEuVat\Tests;

use BeltoftEuVat\Decisions;
use BeltoftEuVat\OrderEvidence;

class OrderEvidenceTest extends TestCase {

	private function order(): \WC_Order {
		$order = new \WC_Order();
		$order->save();

		return $order;
	}

	public function test_valid_number_stores_evidence_under_the_new_keys() {
		$this->vies = 'valid';
		Decisions::get( 'DE123456789', 'DE', true );
		$order = $this->order();

		OrderEvidence::attach( $order, array( 'billing_eu_vat_number' => 'DE123456789', 'billing_country' => 'DE' ) );

		$this->assertSame( 'valid', $order->get_meta( '_beltoft_eu_vat_status' ) );
		$this->assertSame( 'WAPI-TEST-1', $order->get_meta( '_beltoft_eu_vat_request_id' ) );
		$this->assertSame( 'ACME GmbH', $order->get_meta( '_beltoft_eu_vat_name' ) );
		$this->assertSame( 'DE', $order->get_meta( '_beltoft_eu_vat_country' ) );
		$this->assertNotSame( '', $order->get_meta( '_beltoft_eu_vat_checked_at' ) );
		$this->assertSame( '', $order->get_meta( '_beltoft_eu_vat_review' ) );
		$this->assertSame( 1, $this->requests, 'evidence reuses the checkout answer' );
	}

	public function test_attach_does_not_call_vies_itself() {
		$order = $this->order();

		OrderEvidence::attach( $order, array( 'billing_eu_vat_number' => 'DE123456789', 'billing_country' => 'DE' ) );

		$this->assertSame( 0, $this->requests );
		$this->assertSame( 'unverified', $order->get_meta( '_beltoft_eu_vat_status' ) );
		$this->assertSame( 'yes', $order->get_meta( '_beltoft_eu_vat_review' ) );
	}

	public function test_unreachable_vies_flags_the_order_and_leaves_a_note() {
		Decisions::get( 'DE123456789', 'DE', true );
		$order = $this->order();

		OrderEvidence::attach( $order, array( 'billing_eu_vat_number' => 'DE123456789', 'billing_country' => 'DE' ) );
		$order->save();

		$this->assertSame( 'yes', $order->get_meta( '_beltoft_eu_vat_review' ) );
		$this->assertSame( '', $order->get_meta( '_beltoft_eu_vat_request_id' ) );
		$notes = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );
		$this->assertStringContainsString( 'could not be verified', $notes[0]->content );
	}

	public function test_domestic_number_has_no_consultation_and_no_flag() {
		$order = $this->order();

		OrderEvidence::attach( $order, array( 'billing_eu_vat_number' => 'DK12345678', 'billing_country' => 'DK' ) );

		$this->assertSame( 'domestic', $order->get_meta( '_beltoft_eu_vat_status' ) );
		$this->assertSame( '', $order->get_meta( '_beltoft_eu_vat_checked_at' ) );
		$this->assertSame( '', $order->get_meta( '_beltoft_eu_vat_review' ) );
	}

	public function test_no_number_writes_nothing() {
		$order = $this->order();

		OrderEvidence::attach( $order, array( 'billing_eu_vat_number' => '', 'billing_country' => 'DE' ) );

		$this->assertSame( '', $order->get_meta( '_beltoft_eu_vat_status' ) );
	}
}
