<?php
/**
 * PHPUnit bootstrap. Run with: BELTOFT_TEST_DB=zz_bev_tests /root/plugin-tests/run.sh <path-to-this-plugin>
 *
 * WooCommerce is loaded from the staging plugin tree by path (SITE_PLUGINS, set by run.sh).
 *
 * @package BeltoftEuVat
 */

if ( 'cli' !== PHP_SAPI ) {
	exit;
}

$beltoft_eu_vat_tests_dir = getenv( 'WP_TESTS_DIR' ) ? getenv( 'WP_TESTS_DIR' ) : '/tmp/wordpress-tests-lib';
$beltoft_eu_vat_plugins   = getenv( 'SITE_PLUGINS' ) ? getenv( 'SITE_PLUGINS' ) : '/var/www/wordpress/staging.beltoft.net/wp-content/plugins';

require_once $beltoft_eu_vat_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $beltoft_eu_vat_plugins ) {
		require $beltoft_eu_vat_plugins . '/woocommerce/woocommerce.php';
		require dirname( __DIR__ ) . '/beltoft-eu-vat.php';
	}
);

// WooCommerce needs its schema in the throwaway test database.
tests_add_filter(
	'setup_theme',
	static function () {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) && class_exists( 'WC_Install' ) ) {
			WC_Install::install();

			if ( is_callable( array( 'WC', 'instance' ) ) ) {
				WC();
			}
		}
	}
);

require $beltoft_eu_vat_tests_dir . '/includes/bootstrap.php';
require __DIR__ . '/TestCase.php';
