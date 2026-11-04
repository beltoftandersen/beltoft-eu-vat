<?php
namespace BeltoftEuVat\Tests;

use BeltoftEuVat\Inline;

class InlineTest extends TestCase {

	private function ask( array $extra = array(), bool $nonce = true ): array {
		\BeltoftEuVat\Decisions::reset(); // Each real request starts empty.

		return Inline::respond(
			array_merge(
				array(
					'security' => $nonce ? wp_create_nonce( Inline::NONCE ) : '',
					'vat'      => 'DE 123 456 789',
					'country'  => 'DE',
				),
				$extra
			)
		);
	}

	public function tear_down(): void {
		remove_all_filters( 'beltoft_eu_vat_check_rate_limit' );
		parent::tear_down();
	}

	public function test_the_endpoint_is_a_wc_ajax_action() {
		$this->assertNotFalse( has_action( 'wc_ajax_beltoft_eu_vat_check' ) );
	}

	public function test_a_request_without_a_valid_nonce_is_refused_before_any_work() {
		$this->vies = 'valid';
		$reply      = $this->ask( array(), false );

		$this->assertSame( 403, $reply['status'] );
		$this->assertSame( 0, $this->requests );

		$reply = $this->ask( array( 'security' => 'nope' ) );
		$this->assertSame( 403, $reply['status'] );
	}

	public function test_valid_names_the_registered_company() {
		$this->vies = 'valid';
		$reply      = $this->ask();

		$this->assertSame( 200, $reply['status'] );
		$this->assertSame( 'valid', $reply['body']['state'] );
		$this->assertSame( 'Confirmed: ACME GmbH. No VAT is charged (reverse charge).', $reply['body']['text'] );
		$this->assertFalse( $reply['body']['retry'] );
		$this->assertSame( 1, $this->requests );
	}

	public function test_valid_without_a_name_omits_it() {
		set_transient( \BeltoftEuVat\ViesClient::cache_key( 'DE', '123456789' ), array( 'status' => 'valid', 'request_identifier' => 'X', 'registered_name' => '', 'checked_at' => time() ), DAY_IN_SECONDS );

		$this->assertSame( 'Confirmed. No VAT is charged (reverse charge).', $this->ask()['body']['text'] );
		$this->assertSame( 0, $this->requests );
	}

	public function test_invalid_says_not_found() {
		$this->vies = 'invalid';
		$reply      = $this->ask();

		$this->assertSame( 'notfound', $reply['body']['state'] );
		$this->assertSame( 'This VAT number was not found. Check it, or continue and VAT is charged.', $reply['body']['text'] );
		$this->assertFalse( $reply['body']['retry'] );
	}

	public function test_unreachable_says_so_and_offers_check_again() {
		$reply = $this->ask();

		$this->assertSame( 'unreachable', $reply['body']['state'] );
		$this->assertSame( 'The EU register is not answering right now. You can continue; VAT is charged and we check again later.', $reply['body']['text'] );
		$this->assertTrue( $reply['body']['retry'] );
	}

	public function test_unreachable_with_a_recent_confirmation_uses_the_grace_wording() {
		\BeltoftEuVat\Grace::remember( 'DE', '123456789', new \BeltoftEuVat\ViesResult( 'valid', 'REF-1', 'ACME GmbH', '', time() - 5 * DAY_IN_SECONDS ) );
		$reply = $this->ask();

		$this->assertSame( 'valid', $reply['body']['state'] );
		$this->assertStringContainsString( 'The EU register is not answering right now. Your VAT number was confirmed on ' . wp_date( 'j M Y', time() - 5 * DAY_IN_SECONDS ), $reply['body']['text'] );
		$this->assertFalse( $reply['body']['retry'] );
	}

	public function test_check_again_asks_vies_again_past_the_short_unreachable_memory() {
		$this->ask();
		$this->assertSame( 1, $this->requests );

		$this->vies = 'valid';
		$this->assertSame( 'unreachable', $this->ask()['body']['state'], 'a plain repeat uses the memory' );
		$this->assertSame( 1, $this->requests );

		$reply = $this->ask( array( 'retry' => '1' ) );
		$this->assertSame( 'valid', $reply['body']['state'] );
		$this->assertSame( 2, $this->requests );
	}

	public function test_a_format_or_country_problem_is_answered_without_vies() {
		$reply = $this->ask( array( 'country' => 'DK' ) );

		$this->assertSame( 'error', $reply['body']['state'] );
		$this->assertStringContainsString( 'different country', $reply['body']['text'] );
		$this->assertSame( 0, $this->requests );
	}

	public function test_an_empty_number_is_answered_empty() {
		$this->assertSame( 'empty', $this->ask( array( 'vat' => '  ' ) )['body']['state'] );
		$this->assertSame( 0, $this->requests );
	}

	public function test_it_is_rate_limited() {
		add_filter( 'beltoft_eu_vat_check_rate_limit', static fn() => array( 2, 60 ) );
		$this->vies = 'valid';

		$this->assertSame( 200, $this->ask()['status'] );
		$this->assertSame( 200, $this->ask()['status'] );

		$third = $this->ask();
		$this->assertSame( 429, $third['status'] );
		$this->assertSame( 'Too many checks. Please wait a minute and try again.', $third['body']['text'] );
		$this->assertSame( 1, $this->requests, 'the repeat came from the cache, the third made no call' );
	}

	public function test_the_limit_counts_per_ip_and_per_customer() {
		add_filter( 'beltoft_eu_vat_check_rate_limit', static fn() => array( 1, 60 ) );
		$a = self::factory()->user->create();
		$b = self::factory()->user->create();

		wp_set_current_user( $a );
		$this->assertSame( 200, $this->ask()['status'] );
		$this->assertSame( 429, $this->ask()['status'], 'same customer' );

		wp_set_current_user( $b );
		$this->assertSame( 429, $this->ask()['status'], 'another customer on the same IP' );

		$_SERVER['REMOTE_ADDR'] = '203.0.113.77';
		wp_set_current_user( self::factory()->user->create() );
		$this->assertSame( 200, $this->ask()['status'], 'another customer on another IP' );
	}
}
