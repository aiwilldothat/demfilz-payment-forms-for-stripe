<?php
/**
 * Uninstall handler.
 *
 * Removes plugin settings. Payment records are intentionally preserved so you do
 * not lose your transaction history — to remove them as well, drop the
 * {prefix}dpfs_payments table manually.
 *
 * @package Demfilz_Payment_Forms_for_Stripe
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'dpfs_settings' );
