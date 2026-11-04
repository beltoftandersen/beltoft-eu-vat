<?php
/**
 * Removes the plugin's options. Customer and order data are left alone.
 *
 * @package BeltoftEuVat
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

foreach ( array( 'seller_country', 'own_number', 'label', 'on_unreachable', 'admin_email', 'check_domestic', 'grace_days', 'recheck_queue' ) as $beltoft_eu_vat_key ) {
	delete_option( 'beltoft_eu_vat_' . $beltoft_eu_vat_key );
}

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'beltoft_eu_vat_recheck_start', array(), 'beltoft-eu-vat' );
	as_unschedule_all_actions( 'beltoft_eu_vat_recheck_batch', array(), 'beltoft-eu-vat' );
}

wp_clear_scheduled_hook( 'beltoft_eu_vat_recheck_start' );
wp_clear_scheduled_hook( 'beltoft_eu_vat_recheck_batch' );
