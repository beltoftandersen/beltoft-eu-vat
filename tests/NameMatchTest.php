<?php
namespace BeltoftEuVat\Tests;

use BeltoftEuVat\NameMatch;

class NameMatchTest extends \WP_UnitTestCase {

	public function tear_down(): void {
		remove_all_filters( 'beltoft_eu_vat_name_matches' );
		remove_all_filters( 'beltoft_eu_vat_name_similarity' );
		parent::tear_down();
	}

	/**
	 * @return array<string,array{0:string,1:string,2:string,3:array}>
	 */
	public static function cases(): array {
		return array(
			'exact'                         => array( 'ACME GmbH', 'ACME GmbH', 'equal', array() ),
			'case and legal form'           => array( 'Arrotti ApS', 'ARROTTI APS', 'equal', array() ),
			'legal form only on one side'   => array( 'Arrotti', 'ARROTTI APS', 'equal', array() ),
			'german umlaut and und'         => array( 'Müller GmbH & Co. KG', 'Mueller GmbH und Co KG', 'equal', array() ),
			'danish letters and IVS'        => array( 'Tapas til Døren', 'TAPAS TIL DOEREN IVS', 'equal', array() ),
			'dotted legal forms'            => array( 'Nowak sp. z o.o.', 'NOWAK', 'equal', array() ),
			'slash legal form'              => array( 'Hansen A/S', 'HANSEN', 'equal', array() ),
			'leading the'                   => array( 'The Coffee Shop Ltd', 'COFFEE SHOP', 'equal', array() ),
			'accents'                       => array( 'Café Société', 'CAFE SOCIETE SARL', 'equal', array() ),
			'registered contains entered'   => array( 'Nordlys', 'NORDLYS BYGGERI OG HANDEL APS', 'contains', array() ),
			'entered contains registered'   => array( 'Nordlys Byggeri Aarhus', 'NORDLYS BYGGERI', 'contains', array() ),
			'same words other order'        => array( 'Handel Nordlys', 'NORDLYS BYGGERI HANDEL', 'tokens', array() ),
			'typo'                          => array( 'Nordlyz Byggeri', 'NORDLYS BYGGERI APS', 'similarity 93', array() ),
			'person, register comma form'   => array( 'Ignored Consulting', 'Jensen, Hans', 'person name', array( 'Hans Jensen' ) ),
			'person, register upper'        => array( '', 'HANS JENSEN', 'person name', array( 'Hans Jensen' ) ),
			'person, reversed'              => array( '', 'JENSEN HANS', 'person name', array( 'Hans Jensen' ) ),
			'person with middle name'       => array( '', 'HANS PETER JENSEN', 'person name', array( 'Hans Jensen' ) ),
			'person entered as company'     => array( 'Hans Jensen', 'JENSEN, HANS', 'tokens', array() ),
		);
	}

	/**
	 * @dataProvider cases
	 */
	public function test_names_that_match( string $entered, string $registered, string $reason, array $people ) {
		$result = NameMatch::compare( $entered, $registered, $people );

		$this->assertTrue( $result['matched'], "$entered vs $registered" );
		$this->assertSame( $reason, $result['reason'] );
	}

	/**
	 * @return array<string,array{0:string,1:string,2:array}>
	 */
	public static function refusals(): array {
		return array(
			'different companies'        => array( 'Acme ApS', 'Globex ApS', array() ),
			'different companies, long'  => array( 'Nordlys Byggeri ApS', 'Sydlys Rengoering ApS', array() ),
			'nothing entered'            => array( '', 'ACME GMBH', array() ),
			'only a legal form entered'  => array( 'ApS', 'ACME APS', array() ),
			'too short to be contained'  => array( 'Ab', 'AB NORDLYS BYGGERI', array() ),
			'short word, not 4 letters'  => array( 'Hos Ole', 'HOS PEDER HANSEN', array() ),
			'first name only'            => array( '', 'HANS JENSEN', array( 'Hans' ) ),
			'other person'               => array( '', 'HANS JENSEN', array( 'Peter Larsen' ) ),
			'company vs other person'    => array( 'Larsen Consulting', 'Jensen, Hans', array( 'Peter Larsen' ) ),
			'empty registered'           => array( 'Acme', '', array() ),
			'dashes registered'          => array( 'Acme', '---', array() ),
			'half a word is not a word'  => array( 'Nord', 'NORDLYS BYGGERI', array() ),
		);
	}

	/**
	 * @dataProvider refusals
	 */
	public function test_names_that_do_not_match( string $entered, string $registered, array $people ) {
		$result = NameMatch::compare( $entered, $registered, $people );

		$this->assertFalse( $result['matched'], "$entered vs $registered" );
		$this->assertSame( 'mismatch', $result['reason'] );
	}

	public function test_normalise() {
		$this->assertSame( 'mueller co', NameMatch::normalise( 'Müller GmbH & Co. KG' ) );
		$this->assertSame( 'tapas til doeren', NameMatch::normalise( 'Tapas til Døren IVS' ) );
		$this->assertSame( 'strasse', NameMatch::normalise( 'Straße' ) );
		$this->assertSame( 'aaberg', NameMatch::normalise( 'Åberg' ) );
		$this->assertSame( 'ab', NameMatch::normalise( 'AB' ), 'a name made only of a legal form keeps it' );
	}

	public function test_the_similarity_threshold_is_a_filter() {
		$this->assertFalse( NameMatch::matches( 'Nordlyz Bygg', 'NORDLYS BYGGERI', array() )['matched'] );

		add_filter( 'beltoft_eu_vat_name_similarity', static fn() => 70 );
		$this->assertTrue( NameMatch::matches( 'Nordlyz Bygg', 'NORDLYS BYGGERI', array() )['matched'] );
	}

	public function test_the_site_filter_has_the_last_word() {
		add_filter( 'beltoft_eu_vat_name_matches', static fn() => true );
		$this->assertTrue( NameMatch::matches( 'Acme', 'Globex', array() )['matched'] );

		remove_all_filters( 'beltoft_eu_vat_name_matches' );
		add_filter( 'beltoft_eu_vat_name_matches', static fn() => false );
		$this->assertFalse( NameMatch::matches( 'Acme', 'Acme', array() )['matched'] );
	}

	public function test_the_filter_gets_both_names_and_the_context() {
		$seen = array();
		add_filter(
			'beltoft_eu_vat_name_matches',
			static function ( $matches, $entered, $registered, $context ) use ( &$seen ) {
				$seen = array( $entered, $registered, $context['first_name'] );

				return $matches;
			},
			10,
			4
		);

		NameMatch::matches( 'Acme', 'Acme ApS', array( 'first_name' => 'Hans', 'last_name' => 'Jensen' ) );
		$this->assertSame( array( 'Acme', 'Acme ApS', 'Hans' ), $seen );
	}
}
