<?php
/**
 * Plugin Name:       Demfilz Payment Forms for Stripe
 * Description:       Modern, embeddable Stripe payment forms. Drop a shortcode onto any page to collect Name, Email, Phone, Reference Number, Message and a payment amount, then send the customer to Stripe's secure checkout. Includes an admin dashboard with a payment summary, webhook confirmation and email notifications.
 * Version:           1.0.0
 * Requires at least: 6.2
 * Requires PHP:      7.2
 * Author:            Demfilz
 * Author URI:        https://demfilz.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       demfilz-payment-forms-for-stripe
 *
 * @package Demfilz_Payment_Forms_for_Stripe
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DPFS_VERSION', '1.0.0' );
define( 'DPFS_FILE', __FILE__ );
define( 'DPFS_PATH', plugin_dir_path( __FILE__ ) );
define( 'DPFS_URL', plugin_dir_url( __FILE__ ) );
define( 'DPFS_OPTION_KEY', 'dpfs_settings' );

require_once DPFS_PATH . 'includes/class-dpfs-db.php';
require_once DPFS_PATH . 'includes/class-dpfs-stripe.php';
require_once DPFS_PATH . 'includes/class-dpfs-admin.php';
require_once DPFS_PATH . 'includes/class-dpfs-shortcode.php';
require_once DPFS_PATH . 'includes/class-dpfs-webhook.php';

/**
 * Get a single plugin setting (or the whole settings array when $key is null).
 *
 * @param string|null $key      Setting key.
 * @param mixed       $fallback Fallback value.
 * @return mixed
 */
function dpfs_get_option( $key = null, $fallback = '' ) {
	$options = get_option( DPFS_OPTION_KEY, array() );
	if ( ! is_array( $options ) ) {
		$options = array();
	}
	if ( null === $key ) {
		return $options;
	}
	return ( isset( $options[ $key ] ) && '' !== $options[ $key ] ) ? $options[ $key ] : $fallback;
}

/**
 * Currencies that Stripe treats as zero-decimal.
 *
 * @return string[]
 */
function dpfs_zero_decimal_currencies() {
	return array( 'bif', 'clp', 'djf', 'gnf', 'jpy', 'kmf', 'krw', 'mga', 'pyg', 'rwf', 'ugx', 'vnd', 'vuv', 'xaf', 'xof', 'xpf' );
}

/**
 * Convert a decimal amount (e.g. 49.95) into the smallest currency unit Stripe expects.
 *
 * @param float  $amount   Decimal amount.
 * @param string $currency Currency code.
 * @return int
 */
function dpfs_amount_to_minor( $amount, $currency ) {
	$currency = strtolower( $currency );
	$amount   = (float) $amount;
	if ( in_array( $currency, dpfs_zero_decimal_currencies(), true ) ) {
		return (int) round( $amount );
	}
	return (int) round( $amount * 100 );
}

/**
 * Format a minor-unit amount back into a human string, e.g. 4995 -> "49.95".
 *
 * @param int    $minor    Amount in the smallest currency unit.
 * @param string $currency Currency code.
 * @return string
 */
function dpfs_format_minor( $minor, $currency ) {
	$currency = strtolower( $currency );
	if ( in_array( $currency, dpfs_zero_decimal_currencies(), true ) ) {
		return number_format_i18n( (int) $minor );
	}
	return number_format_i18n( ( (int) $minor ) / 100, 2 );
}

/**
 * A small map of currency symbols for nicer display.
 *
 * @param string $currency Currency code.
 * @return string
 */
function dpfs_currency_symbol( $currency ) {
	$symbols  = array(
		'usd' => '$',
		'aud' => '$',
		'cad' => '$',
		'nzd' => '$',
		'sgd' => '$',
		'hkd' => '$',
		'eur' => '€',
		'gbp' => '£',
		'jpy' => '¥',
		'inr' => '₹',
	);
	$currency = strtolower( $currency );
	return isset( $symbols[ $currency ] ) ? $symbols[ $currency ] : '';
}

/**
 * Central handler: record a completed Stripe Checkout Session.
 *
 * Safe to call more than once — it only transitions a record to "paid" a single
 * time, so the webhook and the on-page success fallback can both call it.
 *
 * @param array $session Stripe Checkout Session object as an associative array.
 */
function dpfs_record_completed_session( $session ) {
	if ( empty( $session['id'] ) ) {
		return;
	}

	$metadata   = ( isset( $session['metadata'] ) && is_array( $session['metadata'] ) ) ? $session['metadata'] : array();
	$payment_id = isset( $metadata['dpfs_payment_id'] ) ? absint( $metadata['dpfs_payment_id'] ) : 0;

	$record = $payment_id ? DPFS_DB::get( $payment_id ) : DPFS_DB::get_by_session( $session['id'] );
	if ( ! $record ) {
		return;
	}

	$paid = isset( $session['payment_status'] ) && 'paid' === $session['payment_status'];

	// Resolve the payment intent id whether expanded or not.
	$pi = '';
	if ( isset( $session['payment_intent'] ) ) {
		$pi = is_array( $session['payment_intent'] ) ? ( isset( $session['payment_intent']['id'] ) ? $session['payment_intent']['id'] : '' ) : $session['payment_intent'];
	}

	DPFS_DB::update(
		$record->id,
		array(
			'session_id'     => $session['id'],
			'payment_intent' => $pi,
			'amount'         => isset( $session['amount_total'] ) ? (int) $session['amount_total'] : $record->amount,
			'currency'       => isset( $session['currency'] ) ? sanitize_text_field( $session['currency'] ) : $record->currency,
			'status'         => $paid ? 'paid' : $record->status,
		)
	);

	// Only notify once, on the transition into "paid".
	if ( $paid && 'paid' !== $record->status ) {
		dpfs_send_notification( DPFS_DB::get( $record->id ) );
		do_action( 'dpfs_payment_completed', $record->id, $session );
	}
}

/**
 * Email the site's notification address about a successful payment.
 *
 * @param object $record Payment record row.
 */
function dpfs_send_notification( $record ) {
	if ( ! $record ) {
		return;
	}

	$to = dpfs_get_option( 'notification_email', get_option( 'admin_email' ) );
	if ( ! is_email( $to ) ) {
		$to = get_option( 'admin_email' );
	}

	$amount = dpfs_currency_symbol( $record->currency ) . dpfs_format_minor( $record->amount, $record->currency ) . ' ' . strtoupper( $record->currency );

	$subject = sprintf(
		/* translators: 1: site name, 2: amount */
		__( '[%1$s] Payment received — %2$s', 'demfilz-payment-forms-for-stripe' ),
		wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
		$amount
	);

	$rows = array(
		__( 'Amount', 'demfilz-payment-forms-for-stripe' ) => $amount,
		__( 'Name', 'demfilz-payment-forms-for-stripe' )   => $record->name,
		__( 'Email', 'demfilz-payment-forms-for-stripe' )  => $record->email,
		__( 'Phone', 'demfilz-payment-forms-for-stripe' )  => $record->phone,
		__( 'Reference Number', 'demfilz-payment-forms-for-stripe' ) => $record->reference,
		__( 'Message', 'demfilz-payment-forms-for-stripe' ) => $record->message,
		__( 'Status', 'demfilz-payment-forms-for-stripe' ) => ucfirst( $record->status ),
		__( 'Date', 'demfilz-payment-forms-for-stripe' )   => $record->updated_at,
		__( 'Stripe Session', 'demfilz-payment-forms-for-stripe' ) => $record->session_id,
	);

	$body  = '<div style="font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;max-width:560px;margin:0 auto;">';
	$body .= '<h2 style="color:#635bff;margin:0 0 16px;">' . esc_html__( 'New payment received', 'demfilz-payment-forms-for-stripe' ) . '</h2>';
	$body .= '<table style="width:100%;border-collapse:collapse;font-size:14px;">';
	foreach ( $rows as $label => $value ) {
		if ( '' === trim( (string) $value ) ) {
			continue;
		}
		$body .= '<tr>';
		$body .= '<td style="padding:8px 12px;border-bottom:1px solid #eef0f5;color:#64748b;white-space:nowrap;vertical-align:top;font-weight:600;">' . esc_html( $label ) . '</td>';
		$body .= '<td style="padding:8px 12px;border-bottom:1px solid #eef0f5;color:#0f172a;">' . nl2br( esc_html( $value ) ) . '</td>';
		$body .= '</tr>';
	}
	$body .= '</table>';
	$body .= '<p style="color:#94a3b8;font-size:12px;margin-top:20px;">' . esc_html__( 'Sent by Demfilz Payment Forms for Stripe', 'demfilz-payment-forms-for-stripe' ) . ' · ' . esc_html( get_bloginfo( 'name' ) ) . '</p>';
	$body .= '</div>';

	$headers = array( 'Content-Type: text/html; charset=UTF-8' );

	wp_mail( $to, $subject, $body, $headers );
}

/**
 * Boot the plugin.
 */
function dpfs_init() {
	new DPFS_Admin();
	new DPFS_Shortcode();
	new DPFS_Webhook();
}
add_action( 'plugins_loaded', 'dpfs_init' );

/**
 * Activation — create the payments table.
 */
register_activation_hook( __FILE__, array( 'DPFS_DB', 'create_table' ) );

/**
 * Settings link on the Plugins screen.
 */
add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	function ( $links ) {
		$url = admin_url( 'admin.php?page=dpfs-payments&tab=settings' );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'demfilz-payment-forms-for-stripe' ) . '</a>' );
		return $links;
	}
);
