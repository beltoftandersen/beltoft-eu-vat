<?php
/**
 * Wires the plugin.
 *
 * @package BeltoftEuVat
 */

namespace BeltoftEuVat;

defined( 'ABSPATH' ) || exit;

/**
 * Boot.
 */
final class Plugin {

	/**
	 * Whether the hooks are in place.
	 *
	 * @var bool
	 */
	private static bool $booted = false;

	/**
	 * Registers everything, when WooCommerce is there.
	 */
	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}

		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'missing_notice' ) );

			return;
		}

		self::$booted = true;

		LocalChecks::register();
		SettingsPage::register();
		Field::register();
		Checkout::register();
		Exemption::register();
		OrderEvidence::register();
		Account::register();
		ReviewQueue::register();
	}

	/**
	 * Admin notice when WooCommerce is missing.
	 */
	public static function missing_notice(): void {
		if ( current_user_can( 'activate_plugins' ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Beltoft EU VAT for WooCommerce needs WooCommerce to be active.', 'beltoft-eu-vat' ) . '</p></div>';
		}
	}
}
