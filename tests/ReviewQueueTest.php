<?php
namespace BeltoftEuVat\Tests;

use BeltoftEuVat\OrderEvidence;
use BeltoftEuVat\ReviewQueue;
use BeltoftEuVat\Settings;
use BeltoftEuVat\VatDecision;

class ReviewQueueTest extends TestCase {

	private function flagged(): \WC_Order {
		$order = new \WC_Order();
		$order->set_billing_email( 'christian.beltoft@gmail.com' );
		$order->update_meta_data( '_billing_eu_vat_number', 'DE123456789' );
		$decision        = new VatDecision( 'unverified', 'DE123456789' );
		$decision->error = 'timed out';
		OrderEvidence::write( $order, $decision );
		$order->save();

		return $order;
	}

	public function set_up(): void {
		parent::set_up();
		reset_phpmailer_instance();
	}

	public function test_a_flagged_order_mails_the_shop_admin_once() {
		$order = $this->flagged();

		do_action( 'woocommerce_checkout_order_created', $order );
		do_action( 'woocommerce_checkout_order_created', $order );

		$mailer = tests_retrieve_phpmailer_instance();
		$this->assertCount( 1, $mailer->mock_sent );
		$this->assertSame( 'christian.beltoft@gmail.com', $mailer->mock_sent[0]['to'][0][0] );
		$this->assertStringContainsString( 'could not be checked for order', $mailer->mock_sent[0]['subject'] );
		$this->assertStringContainsString( 'DE123456789', $mailer->mock_sent[0]['body'] );
	}

	public function test_the_mail_goes_to_the_configured_address() {
		update_option( Settings::ADMIN_EMAIL, 'owner@example.org' );
		do_action( 'woocommerce_checkout_order_created', $this->flagged() );

		$this->assertSame( 'owner@example.org', tests_retrieve_phpmailer_instance()->mock_sent[0]['to'][0][0] );
	}

	public function test_an_order_that_is_not_flagged_sends_nothing() {
		$order = new \WC_Order();
		$order->save();
		do_action( 'woocommerce_checkout_order_created', $order );

		$this->assertEmpty( tests_retrieve_phpmailer_instance()->mock_sent );
	}

	public function test_pending_lists_flagged_orders_only() {
		$flagged = $this->flagged();
		$other   = new \WC_Order();
		$other->save();

		$ids = array_map( static fn( $o ) => $o->get_id(), ReviewQueue::pending() );

		$this->assertContains( $flagged->get_id(), $ids );
		$this->assertNotContains( $other->get_id(), $ids );
	}

	public function test_column_shows_needs_review() {
		ob_start();
		ReviewQueue::render_column( 'beltoft_eu_vat', $this->flagged() );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'Needs review', $html );
		$this->assertArrayHasKey( 'beltoft_eu_vat', ReviewQueue::add_column( array() ) );
	}

	public function test_order_screen_panel_shows_evidence_and_the_review_link() {
		ob_start();
		ReviewQueue::order_panel( $this->flagged() );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'Needs review', $html );
		$this->assertStringContainsString( 'beltoft_eu_vat_reviewed', $html );
		$this->assertStringContainsString( '_wpnonce', $html );
	}
}
