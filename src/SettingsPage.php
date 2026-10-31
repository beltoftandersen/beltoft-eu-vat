<?php
/**
 * The EU VAT section under WooCommerce > Settings > Tax.
 *
 * @package BeltoftEuVat
 */

namespace BeltoftEuVat;

defined( 'ABSPATH' ) || exit;

/**
 * Settings screen, built with the WooCommerce settings API.
 */
final class SettingsPage {

	public const SECTION = 'beltoft-eu-vat';

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_filter( 'woocommerce_get_sections_tax', array( __CLASS__, 'sections' ) );
		add_filter( 'woocommerce_get_settings_tax', array( __CLASS__, 'settings' ), 10, 2 );
		add_filter( 'woocommerce_admin_settings_sanitize_option_' . Settings::SELLER_COUNTRY, array( __CLASS__, 'sanitize_country' ) );
		add_filter( 'woocommerce_admin_settings_sanitize_option_' . Settings::OWN_NUMBER, array( __CLASS__, 'sanitize_number' ) );
		add_filter( 'woocommerce_admin_settings_sanitize_option_' . Settings::ON_UNREACHABLE, array( __CLASS__, 'sanitize_behaviour' ) );
	}

	/**
	 * Adds the section tab.
	 *
	 * @param array $sections Sections.
	 * @return array
	 */
	public static function sections( $sections ) {
		$sections            = is_array( $sections ) ? $sections : array();
		$sections[ self::SECTION ] = __( 'EU VAT', 'beltoft-eu-vat' );

		return $sections;
	}

	/**
	 * The fields of the section.
	 *
	 * @param array  $settings Settings of another section.
	 * @param string $section  Current section.
	 * @return array
	 */
	public static function settings( $settings, $section = '' ) {
		if ( self::SECTION !== $section ) {
			return $settings;
		}

		$countries = array( '' => __( 'Store base country', 'beltoft-eu-vat' ) ) + Countries::options();

		return array(
			array(
				'title' => __( 'EU VAT number', 'beltoft-eu-vat' ),
				'type'  => 'title',
				'desc'  => __( 'An optional VAT number field at checkout. A valid number from another EU country is checked with VIES and VAT is removed (reverse charge).', 'beltoft-eu-vat' ),
				'id'    => 'beltoft_eu_vat_options',
			),
			array(
				'title'    => __( 'Seller country', 'beltoft-eu-vat' ),
				'desc'     => __( 'Numbers from this country are domestic sales: VAT is charged and VIES is not asked.', 'beltoft-eu-vat' ),
				'id'       => Settings::SELLER_COUNTRY,
				'type'     => 'select',
				'class'    => 'wc-enhanced-select',
				'css'      => 'min-width: 300px;',
				'default'  => '',
				'options'  => $countries,
				'desc_tip' => true,
			),
			array(
				'title'    => __( 'Your own VAT number', 'beltoft-eu-vat' ),
				'desc'     => __( 'With the country prefix, for example DK12345678. Sent to VIES as the requester so that VIES returns a consultation number, which is stored as evidence on the order.', 'beltoft-eu-vat' ),
				'id'       => Settings::OWN_NUMBER,
				'type'     => 'text',
				'default'  => '',
				'css'      => 'min-width: 300px;',
				'desc_tip' => true,
			),
			array(
				'title'    => __( 'Field label', 'beltoft-eu-vat' ),
				'desc'     => __( 'Leave empty for "EU VAT number".', 'beltoft-eu-vat' ),
				'id'       => Settings::LABEL,
				'type'     => 'text',
				'default'  => '',
				'css'      => 'min-width: 300px;',
				'desc_tip' => true,
			),
			array(
				'title'    => __( 'When VIES does not answer', 'beltoft-eu-vat' ),
				'desc'     => __( 'VIES goes offline now and then. An unreachable service never counts as an invalid number.', 'beltoft-eu-vat' ),
				'id'       => Settings::ON_UNREACHABLE,
				'type'     => 'select',
				'class'    => 'wc-enhanced-select',
				'css'      => 'min-width: 300px;',
				'default'  => Settings::FLAG,
				'options'  => array(
					Settings::FLAG  => __( 'Charge VAT and flag the order for review', 'beltoft-eu-vat' ),
					Settings::BLOCK => __( 'Block the checkout', 'beltoft-eu-vat' ),
				),
				'desc_tip' => true,
			),
			array(
				'title'    => __( 'Review email', 'beltoft-eu-vat' ),
				'desc'     => __( 'Receives a mail when an order is flagged. Leave empty to use the site administrator email.', 'beltoft-eu-vat' ),
				'id'       => Settings::ADMIN_EMAIL,
				'type'     => 'email',
				'default'  => '',
				'css'      => 'min-width: 300px;',
				'desc_tip' => true,
			),
			array(
				'type' => 'sectionend',
				'id'   => 'beltoft_eu_vat_options',
			),
		);
	}

	/**
	 * Keeps only an EU country (or empty for the store base country).
	 *
	 * @param mixed $value Submitted value.
	 */
	public static function sanitize_country( $value ): string {
		$value = strtoupper( sanitize_text_field( (string) $value ) );

		return isset( Countries::options()[ $value ] ) ? $value : '';
	}

	/**
	 * Stores the number without spaces or punctuation.
	 *
	 * @param mixed $value Submitted value.
	 */
	public static function sanitize_number( $value ): string {
		return Settings::normalise( sanitize_text_field( (string) $value ) );
	}

	/**
	 * Keeps one of the two behaviours.
	 *
	 * @param mixed $value Submitted value.
	 */
	public static function sanitize_behaviour( $value ): string {
		return Settings::BLOCK === $value ? Settings::BLOCK : Settings::FLAG;
	}
}
