<?php
/**
 * Plugin Name:       Beltoft EU VAT for WooCommerce
 * Plugin URI:        https://beltoft.net/
 * Description:       An optional EU VAT number field at checkout, checked against VIES. A valid number from another EU country removes VAT (reverse charge) and the evidence is stored on the order.
 * Version:           0.4.1
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Requires Plugins:  woocommerce
 * Author:            beltoft.net
 * Author URI:        https://beltoft.net
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       beltoft-eu-vat
 * Domain Path:       /languages
 *
 * Works with the classic WooCommerce checkout (shortcode). The block checkout is not supported.
 *
 * @package BeltoftEuVat
 */

defined( 'ABSPATH' ) || exit;

define( 'BELTOFT_EU_VAT_FILE', __FILE__ );
define( 'BELTOFT_EU_VAT_PATH', plugin_dir_path( __FILE__ ) );
define( 'BELTOFT_EU_VAT_URL', plugin_dir_url( __FILE__ ) );
define( 'BELTOFT_EU_VAT_VERSION', '0.4.1' );

spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'BeltoftEuVat\\';

		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}

		$relative = str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) );
		$file     = BELTOFT_EU_VAT_PATH . 'src/' . $relative . '.php';

		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', BELTOFT_EU_VAT_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', BELTOFT_EU_VAT_FILE, false );
		}
	}
);

/**
 * Loads the plugin's bundled translations (front end and admin).
 *
 * The plugin is not hosted on wordpress.org, so WordPress does not look in its own languages folder by itself.
 */
function beltoft_eu_vat_load_textdomain() {
	// phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound
	load_plugin_textdomain( 'beltoft-eu-vat', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
add_action( 'init', 'beltoft_eu_vat_load_textdomain', 0 );

add_action( 'plugins_loaded', array( \BeltoftEuVat\Plugin::class, 'boot' ), 20 );

register_deactivation_hook(
	__FILE__,
	static function () {
		if ( class_exists( '\BeltoftEuVat\Recheck' ) ) {
			\BeltoftEuVat\Recheck::unschedule();
		}
	}
);
