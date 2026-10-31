<?php
/**
 * Removes the plugin's options. Customer and order data are left alone.
 *
 * @package BeltoftEuVat
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

foreach ( array( 'seller_country', 'own_number', 'label', 'on_unreachable', 'admin_email' ) as $beltoft_eu_vat_key ) {
	delete_option( 'beltoft_eu_vat_' . $beltoft_eu_vat_key );
}
