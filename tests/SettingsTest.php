<?php
namespace BeltoftEuVat\Tests;

use BeltoftEuVat\Settings;
use BeltoftEuVat\SettingsPage;

class SettingsTest extends TestCase {

	public function test_there_is_an_eu_vat_section_in_the_tax_tab() {
		$this->assertArrayHasKey( 'beltoft-eu-vat', apply_filters( 'woocommerce_get_sections_tax', array() ) );

		$settings = apply_filters( 'woocommerce_get_settings_tax', array(), 'beltoft-eu-vat' );
		$ids      = array_column( $settings, 'id' );

		foreach ( array( Settings::SELLER_COUNTRY, Settings::OWN_NUMBER, Settings::LABEL, Settings::ON_UNREACHABLE, Settings::ADMIN_EMAIL ) as $id ) {
			$this->assertContains( $id, $ids );
		}

		$this->assertSame( array( 'x' ), apply_filters( 'woocommerce_get_settings_tax', array( 'x' ), 'standard' ) );
	}

	public function test_saving_through_the_woocommerce_settings_api() {
		$_POST[ Settings::SELLER_COUNTRY ] = 'SE';
		$_POST[ Settings::OWN_NUMBER ]     = 'se 5560-00016701';
		$_POST[ Settings::ON_UNREACHABLE ] = 'block';
		$_POST[ Settings::LABEL ]          = 'VAT ID';
		$_POST[ Settings::ADMIN_EMAIL ]    = 'christian.beltoft@gmail.com';

		\WC_Admin_Settings::save_fields( SettingsPage::settings( array(), 'beltoft-eu-vat' ) );

		$this->assertSame( 'SE', Settings::seller_country() );
		$this->assertSame( 'SE556000016701', Settings::own_number() );
		$this->assertSame( 'block', Settings::on_unreachable() );
		$this->assertSame( 'VAT ID', Settings::label() );
	}

	public function test_domestic_check_setting_defaults_to_yes_and_saves() {
		$this->assertTrue( Settings::check_domestic() );
		$this->assertContains( Settings::CHECK_DOMESTIC, array_column( SettingsPage::settings( array(), 'beltoft-eu-vat' ), 'id' ) );

		$_POST[ Settings::SELLER_COUNTRY ] = 'DK';
		\WC_Admin_Settings::save_fields( SettingsPage::settings( array(), 'beltoft-eu-vat' ) );
		$this->assertFalse( Settings::check_domestic(), 'an unticked box saves no' );

		update_option( Settings::CHECK_DOMESTIC, 'yes' );
		$this->assertTrue( Settings::check_domestic() );
	}

	public function test_sanitizers_reject_unknown_values() {
		$this->assertSame( '', SettingsPage::sanitize_country( 'US' ) );
		$this->assertSame( 'DK', SettingsPage::sanitize_country( 'dk' ) );
		$this->assertSame( 'flag', SettingsPage::sanitize_behaviour( 'whatever' ) );
		$this->assertSame( 'flag', Settings::on_unreachable() );
	}

	public function test_admin_email_falls_back_to_the_site_admin() {
		delete_option( Settings::ADMIN_EMAIL );

		$this->assertSame( get_option( 'admin_email' ), Settings::admin_email() );
	}

	public function test_uninstall_removes_exactly_the_options_and_nothing_else() {
		$code = (string) file_get_contents( dirname( __DIR__ ) . '/uninstall.php' );

		foreach ( array( Settings::SELLER_COUNTRY, Settings::OWN_NUMBER, Settings::LABEL, Settings::ON_UNREACHABLE, Settings::ADMIN_EMAIL ) as $option ) {
			$this->assertStringContainsString( "'" . substr( $option, strlen( 'beltoft_eu_vat_' ) ) . "'", $code );
		}

		$this->assertStringNotContainsString( 'meta', $code );
		$this->assertStringNotContainsString( 'wpdb', $code );
	}

	public function test_a_country_name_comes_from_woocommerce() {
		$this->assertSame( 'Denmark', \BeltoftEuVat\Countries::name( 'DK' ) );
		$this->assertSame( 'Northern Ireland', \BeltoftEuVat\Countries::name( 'XI' ) );
	}
}
