<?php
namespace BeltoftEuVat\Tests;

use BeltoftEuVat\Decisions;
use BeltoftEuVat\Evidence;
use BeltoftEuVat\Grace;
use BeltoftEuVat\OrderEvidence;
use BeltoftEuVat\Settings;
use BeltoftEuVat\Validator;
use BeltoftEuVat\ViesResult;

class GraceTest extends TestCase {

	public function tear_down(): void {
		remove_all_filters( 'beltoft_eu_vat_grace_days' );
		parent::tear_down();
	}

	private function confirmed_days_ago( int $days ): void {
		Grace::remember( 'DE', '123456789', new ViesResult( 'valid', 'WAPI-OLD-9', 'ACME GmbH', '', time() - $days * DAY_IN_SECONDS ) );
	}

	private function order_for( string $number = 'DE123456789' ): \WC_Order {
		$order = new \WC_Order();
		$order->save();
		OrderEvidence::attach( $order, array( 'billing_eu_vat_number' => $number, 'billing_country' => 'DE' ) );
		$order->save();

		return $order;
	}

	public function test_default_is_30_days_and_a_filter_or_the_setting_changes_it() {
		$this->assertSame( 30, Settings::grace_days() );

		update_option( Settings::GRACE_DAYS, '10' );
		$this->assertSame( 10, Settings::grace_days() );

		add_filter( 'beltoft_eu_vat_grace_days', static fn() => 5 );
		$this->assertSame( 5, Settings::grace_days() );
	}

	public function test_within_30_days_a_down_vies_is_accepted_as_reverse_charge() {
		$this->confirmed_days_ago( 29 );
		$decision = Validator::decide( 'DE123456789', 'DE' );

		$this->assertSame( 'valid', $decision->status );
		$this->assertTrue( $decision->exempt );
		$this->assertTrue( $decision->grace );
		$this->assertSame( 'WAPI-OLD-9', $decision->request_identifier );
		$this->assertEqualsWithDelta( time() - 29 * DAY_IN_SECONDS, $decision->checked_at, 5 );
	}

	public function test_the_order_records_the_confirmation_used_and_is_not_flagged() {
		$this->confirmed_days_ago( 12 );
		Decisions::get( 'DE123456789', 'DE', true );
		$order = $this->order_for();

		$this->assertSame( 'valid', $order->get_meta( '_beltoft_eu_vat_status' ) );
		$this->assertSame( 'WAPI-OLD-9', $order->get_meta( '_beltoft_eu_vat_request_id' ) );
		$this->assertSame( 'yes', $order->get_meta( '_beltoft_eu_vat_grace' ) );
		$this->assertSame( gmdate( 'Y-m-d', time() - 12 * DAY_IN_SECONDS ), substr( $order->get_meta( '_beltoft_eu_vat_checked_at' ), 0, 10 ) );
		$this->assertSame( '', $order->get_meta( '_beltoft_eu_vat_review' ) );

		$notes = wp_list_pluck( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), 'content' );
		$this->assertContains( 'Accepted on the confirmation of ' . wp_date( 'j M Y', time() - 12 * DAY_IN_SECONDS ) . ' (VIES unreachable).', $notes );
	}

	public function test_beyond_30_days_it_is_unverified_and_flagged_as_before() {
		$this->confirmed_days_ago( 31 );
		$decision = Validator::decide( 'DE123456789', 'DE' );

		$this->assertSame( 'unverified', $decision->status );
		$this->assertFalse( $decision->exempt );
		$this->assertFalse( $decision->grace );

		Decisions::get( 'DE123456789', 'DE', true );
		$this->assertSame( 'yes', $this->order_for()->get_meta( '_beltoft_eu_vat_review' ) );
	}

	public function test_zero_days_turns_the_grace_off() {
		$this->confirmed_days_ago( 1 );
		add_filter( 'beltoft_eu_vat_grace_days', static fn() => 0 );

		$decision = Validator::decide( 'DE123456789', 'DE' );

		$this->assertSame( 'unverified', $decision->status );
		$this->assertFalse( $decision->grace );
	}

	public function test_an_invalid_answer_is_never_graced_and_drops_the_record() {
		$this->confirmed_days_ago( 2 );
		$this->vies = 'invalid';

		$this->assertSame( 'invalid', Validator::decide( 'DE123456789', 'DE', true, true )->status );

		$this->vies = 'unreachable';
		Decisions::reset();
		\BeltoftEuVat\ViesClient::forget_unreachable( 'DE', '123456789' );
		delete_transient( \BeltoftEuVat\ViesClient::cache_key( 'DE', '123456789' ) );
		$this->assertSame( 'unverified', Validator::decide( 'DE123456789', 'DE' )->status );
	}

	public function test_a_valid_answer_from_vies_is_remembered_for_the_grace_period() {
		$this->vies = 'valid';
		Validator::decide( 'DE123456789', 'DE' );

		$this->assertNotNull( Grace::find( 'DE', '123456789' ) );
		$timeout = (int) get_option( '_transient_timeout_beltoft_eu_vat_last_valid_DE_123456789' );
		$this->assertEqualsWithDelta( time() + 30 * DAY_IN_SECONDS, $timeout, 10 );
	}

	public function test_the_cache_is_used_first_so_a_cached_valid_answer_is_not_grace() {
		$this->cache_valid( 'DE', '123456789' );
		$decision = Validator::decide( 'DE123456789', 'DE' );

		$this->assertTrue( $decision->exempt );
		$this->assertFalse( $decision->grace );
		$this->assertSame( 0, $this->requests );
	}

	public function test_the_stored_account_evidence_counts_too() {
		$user = self::factory()->user->create();
		update_user_meta( $user, 'billing_eu_vat_number', 'DE123456789' );
		update_user_meta( $user, Evidence::STATUS, 'valid' );
		update_user_meta( $user, Evidence::REQUEST_ID, 'WAPI-USER-3' );
		update_user_meta( $user, Evidence::CHECKED_AT, gmdate( 'c', time() - 20 * DAY_IN_SECONDS ) );

		$decision = Validator::decide( 'DE123456789', 'DE' );

		$this->assertTrue( $decision->grace );
		$this->assertSame( 'WAPI-USER-3', $decision->request_identifier );
	}

	public function test_evidence_of_another_number_or_a_flagged_account_is_not_used() {
		$user = self::factory()->user->create();
		update_user_meta( $user, 'billing_eu_vat_number', 'DE999999999' );
		update_user_meta( $user, Evidence::STATUS, 'valid' );
		update_user_meta( $user, Evidence::CHECKED_AT, gmdate( 'c', time() - DAY_IN_SECONDS ) );
		$this->assertSame( 'unverified', Validator::decide( 'DE123456789', 'DE' )->status );

		update_user_meta( $user, 'billing_eu_vat_number', 'DE123456789' );
		update_user_meta( $user, Evidence::FLAG, 'invalid' );
		Decisions::reset();
		delete_transient( \BeltoftEuVat\ViesClient::cache_key( 'DE', '123456789' ) );
		$this->assertSame( 'unverified', Validator::decide( 'DE123456789', 'DE' )->status );
	}

	public function test_a_pending_lookup_is_not_a_grace_case() {
		$this->confirmed_days_ago( 2 );
		delete_transient( \BeltoftEuVat\ViesClient::cache_key( 'DE', '123456789' ) );

		$this->assertSame( 'pending', Validator::decide( 'DE123456789', 'DE', false )->status );
	}

	public function test_a_domestic_number_is_unaffected() {
		$decision = Validator::decide( 'DK12345678', 'DK' );

		$this->assertSame( 'domestic', $decision->status );
		$this->assertFalse( $decision->grace );
	}
}
