<?php
namespace BeltoftEuVat\Tests;

use BeltoftEuVat\Evidence;
use BeltoftEuVat\Exemption;
use BeltoftEuVat\Recheck;
use BeltoftEuVat\Validator;

class RecheckTest extends TestCase {

	/** Pauses asked for. @var int[] */
	private array $pauses = array();

	public function set_up(): void {
		parent::set_up();
		delete_option( Recheck::QUEUE );
		$this->pauses = array();
		add_filter(
			'beltoft_eu_vat_recheck_pause_ms',
			function ( $ms ) {
				$this->pauses[] = $ms;

				return 0;
			}
		);
		reset_phpmailer_instance();
	}

	public function tear_down(): void {
		remove_all_filters( 'beltoft_eu_vat_recheck_pause_ms' );
		remove_all_filters( 'beltoft_eu_vat_recheck_eligible' );
		remove_all_filters( 'beltoft_eu_vat_recheck_batch_size' );
		delete_option( Recheck::QUEUE );
		parent::tear_down();
	}

	private function customer( string $number = 'DE123456789', ?int $order_days_ago = 30, string $status = 'valid' ): int {
		$user = self::factory()->user->create();

		update_user_meta( $user, 'billing_eu_vat_number', $number );
		update_user_meta( $user, Evidence::STATUS, $status );
		update_user_meta( $user, Evidence::REQUEST_ID, 'WAPI-OLD' );
		update_user_meta( $user, Evidence::NAME, 'Old Name' );
		update_user_meta( $user, Evidence::CHECKED_AT, '2026-10-01T10:00:00+00:00' );

		if ( null !== $order_days_ago ) {
			$order = wc_create_order( array( 'customer_id' => $user ) );
			$order->set_date_created( time() - $order_days_ago * DAY_IN_SECONDS );
			$order->set_status( 'completed' );
			$order->save();
		}

		return $user;
	}

	public function test_selection_takes_a_number_with_an_active_subscription_or_a_paid_order_in_12_months() {
		$recent   = $this->customer( 'DE123456789', 30 );
		$old      = $this->customer( 'DE123456789', 400 );
		$no_order = $this->customer( 'DE123456789', null );
		$sub      = $this->customer( 'DE123456789', null );
		$no_num   = self::factory()->user->create();
		add_filter( 'beltoft_eu_vat_recheck_eligible', static fn( $yes, $id ) => $id === $sub ? true : $yes, 10, 2 );

		$queued = Recheck::start();

		$this->assertSame( 4, $queued, 'everyone holding a number is queued' );
		$this->assertSame( 0, $this->requests );

		$this->vies = 'valid';
		$this->assertSame( 2, Recheck::run_batch(), 'only the eligible are asked' );
		$this->assertSame( 2, $this->requests );
		$this->assertTrue( Recheck::eligible( $recent ) );
		$this->assertFalse( Recheck::eligible( $old ) );
		$this->assertFalse( Recheck::eligible( $no_order ) );
		$this->assertFalse( Recheck::eligible( $no_num ) );
	}

	public function test_a_batch_is_20_numbers_with_a_pause_between_calls_and_the_rest_is_scheduled_later() {
		for ( $i = 0; $i < 22; $i++ ) {
			$this->customer();
		}

		$this->vies = 'valid';
		Recheck::start();

		$this->assertSame( 20, Recheck::run_batch() );
		$this->assertCount( 19, $this->pauses, 'a pause between calls, not before the first' );
		$this->assertSame( 1500, $this->pauses[0] );
		$this->assertCount( 2, get_option( Recheck::QUEUE ) );
		$latest = 0;
		foreach ( as_get_scheduled_actions( array( 'hook' => Recheck::BATCH, 'group' => Recheck::GROUP, 'status' => \ActionScheduler_Store::STATUS_PENDING, 'per_page' => 50 ) ) as $action ) {
			$latest = max( $latest, $action->get_schedule()->get_date()->getTimestamp() );
		}
		$this->assertEqualsWithDelta( time() + 300, $latest, 10, 'the next batch is five minutes away' );

		$this->assertSame( 2, Recheck::run_batch() );
		$this->assertFalse( get_option( Recheck::QUEUE ) );
	}

	public function test_it_asks_vies_even_when_the_cache_holds_an_answer_and_stores_the_evidence() {
		$user = $this->customer();
		$this->cache_valid( 'DE', '123456789' );
		$this->vies = 'valid';

		$this->assertSame( 'valid', Recheck::check_user( $user ) );

		$this->assertSame( 1, $this->requests );
		$this->assertSame( 'valid', get_user_meta( $user, Evidence::STATUS, true ) );
		$this->assertSame( 'WAPI-TEST-1', get_user_meta( $user, Evidence::REQUEST_ID, true ) );
		$this->assertSame( 'ACME GmbH', get_user_meta( $user, Evidence::NAME, true ) );
		$this->assertSame( gmdate( 'Y-m-d' ), substr( get_user_meta( $user, Evidence::CHECKED_AT, true ), 0, 10 ) );
		$this->assertSame( '', get_user_meta( $user, Evidence::FLAG, true ) );
	}

	public function test_a_number_that_was_valid_and_is_now_invalid_marks_the_customer_and_mails_once() {
		$user       = $this->customer();
		$this->vies = 'invalid';

		$this->assertSame( 'invalid', Recheck::check_user( $user ) );

		$this->assertSame( 'invalid', get_user_meta( $user, Evidence::STATUS, true ) );
		$this->assertSame( 'invalid', get_user_meta( $user, Evidence::FLAG, true ) );
		$mailer = tests_retrieve_phpmailer_instance();
		$this->assertCount( 1, $mailer->mock_sent );
		$this->assertSame( 'christian.beltoft@gmail.com', $mailer->mock_sent[0]['to'][0][0] );
		$this->assertStringContainsString( 'DE123456789', $mailer->mock_sent[0]['body'] );
		$this->assertStringContainsString( 'next order is charged VAT', $mailer->mock_sent[0]['body'] );

		// Tomorrow night: still invalid, still one mail.
		\BeltoftEuVat\Decisions::reset();
		Recheck::check_user( $user );
		$this->assertCount( 1, $mailer->mock_sent );
	}

	public function test_the_next_order_of_that_customer_is_charged_vat() {
		$user = $this->customer();
		$this->cache_valid( 'DE', '123456789' );
		$this->vies = 'invalid';
		Recheck::check_user( $user );

		$this->vies = 'unreachable';
		\BeltoftEuVat\Decisions::reset();
		$_POST = array( 'billing_eu_vat_number' => 'DE123456789', 'billing_country' => 'DE' );

		$this->assertFalse( Exemption::is_exempt(), 'the cached valid answer was replaced' );
		$this->assertNotSame( 'valid', Validator::decide( 'DE123456789', 'DE' )->status, 'and no grace is given to a number found invalid' );
	}

	public function test_an_invalid_number_that_was_never_valid_is_marked_but_not_mailed() {
		$user       = $this->customer( 'DE123456789', 30, 'unverified' );
		$this->vies = 'invalid';

		Recheck::check_user( $user );

		$this->assertSame( 'invalid', get_user_meta( $user, Evidence::STATUS, true ) );
		$this->assertCount( 0, tests_retrieve_phpmailer_instance()->mock_sent );
	}

	public function test_unreachable_keeps_the_previous_status_and_mails_nothing() {
		$user = $this->customer();

		$this->assertSame( 'unreachable', Recheck::check_user( $user ) );

		$this->assertSame( 'valid', get_user_meta( $user, Evidence::STATUS, true ) );
		$this->assertSame( 'WAPI-OLD', get_user_meta( $user, Evidence::REQUEST_ID, true ) );
		$this->assertSame( '2026-10-01T10:00:00+00:00', get_user_meta( $user, Evidence::CHECKED_AT, true ) );
		$this->assertSame( '', get_user_meta( $user, Evidence::FLAG, true ) );
		$this->assertCount( 0, tests_retrieve_phpmailer_instance()->mock_sent );
	}

	public function test_a_night_with_vies_down_stops_after_three_misses_and_leaves_the_rest_for_tomorrow() {
		for ( $i = 0; $i < 6; $i++ ) {
			$this->customer();
		}

		Recheck::start();

		$this->assertSame( 3, Recheck::run_batch() );
		$this->assertSame( 3, $this->requests );
		$this->assertFalse( get_option( Recheck::QUEUE ) );
	}

	public function test_a_domestic_number_is_rechecked_too() {
		$user       = $this->customer( 'DK12345678', 30, 'domestic' );
		$this->vies = 'valid';

		$this->assertSame( 'valid', Recheck::check_user( $user ) );
		$this->assertSame( 'domestic', get_user_meta( $user, Evidence::STATUS, true ) );
	}

	public function test_a_night_start_is_scheduled_once_around_01_00_site_time() {
		as_unschedule_all_actions( Recheck::START, array(), Recheck::GROUP );

		Recheck::schedule();
		Recheck::schedule();

		$this->assertTrue( Recheck::uses_action_scheduler() );
		$when = as_next_scheduled_action( Recheck::START, array(), Recheck::GROUP );
		$this->assertSame( '01:00', ( new \DateTimeImmutable( '@' . $when ) )->setTimezone( wp_timezone() )->format( 'H:i' ) );
		$this->assertCount( 1, as_get_scheduled_actions( array( 'hook' => Recheck::START, 'group' => Recheck::GROUP, 'status' => \ActionScheduler_Store::STATUS_PENDING ), 'ids' ) );
	}
}
