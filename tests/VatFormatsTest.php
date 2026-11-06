<?php
namespace BeltoftEuVat\Tests;

use BeltoftEuVat\VatFormats;
use BeltoftEuVat\LocalChecks;
use BeltoftEuVat\Countries;

/**
 * A malformed number is refused locally; VIES is never asked about a partial one.
 */
class VatFormatsTest extends TestCase {

	public function test_every_member_state_has_a_format_and_its_example_matches() {
		$all = VatFormats::all();

		foreach ( Countries::eu() as $country ) {
			$this->assertArrayHasKey( $country, $all, $country );
			$this->assertTrue( VatFormats::matches( $country, $all[ $country ][1] ), $country );
		}
	}

	public function test_known_good_and_partial_numbers() {
		$this->assertTrue( VatFormats::matches( 'DK', '12345678' ) );
		$this->assertFalse( VatFormats::matches( 'DK', '1234' ) );
		$this->assertFalse( VatFormats::matches( 'DK', '123456789' ) );
		$this->assertTrue( VatFormats::matches( 'DE', '123456789' ) );
		$this->assertFalse( VatFormats::matches( 'DE', '12345678' ) );
		$this->assertTrue( VatFormats::matches( 'NL', '123456789B01' ) );
		$this->assertFalse( VatFormats::matches( 'NL', '123456789B' ) );
		$this->assertTrue( VatFormats::matches( 'FR', 'AB123456789' ) );
		$this->assertTrue( VatFormats::matches( 'FR', '12345678901' ) );
		$this->assertFalse( VatFormats::matches( 'FR', '1234567890' ) );
		$this->assertTrue( VatFormats::matches( 'GR', '123456789' ) );
		$this->assertNull( VatFormats::matches( 'CH', '123' ) );
	}

	public function test_local_check_refuses_a_partial_number_and_accepts_a_complete_one() {
		$this->assertFalse( LocalChecks::run( 'DK', '1234', 'DK1234' ) );
		$this->assertNotFalse( LocalChecks::run( 'DK', '12345678', 'DK12345678' ) );
		$this->assertNotFalse( LocalChecks::run( 'CH', '1234', 'CH1234' ) );
	}

	public function test_the_script_gets_the_formats() {
		$all = VatFormats::for_script();

		$this->assertSame( 'EL', $all['GR']['prefix'] );
		$this->assertSame( 'Danish', $all['DK']['name'] );
		$this->assertSame( '\d{8}', $all['DK']['pattern'] );
	}

	public function test_the_filter_can_change_a_format() {
		add_filter(
			'beltoft_eu_vat_formats',
			function ( $formats ) {
				$formats['DK'] = array( '\d{9}', '123456789' );
				return $formats;
			}
		);

		$this->assertTrue( VatFormats::matches( 'DK', '123456789' ) );
		$this->assertFalse( VatFormats::matches( 'DK', '12345678' ) );
	}
}
