<?php
/**
 * Lightweight Stripe API client (no SDK required).
 *
 * @package Demfilz_Payment_Forms_for_Stripe
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Talks to the Stripe REST API using wp_remote_request().
 */
class DPFS_Stripe {

	const API_BASE = 'https://api.stripe.com/v1/';

	/**
	 * Configured secret key.
	 *
	 * @return string
	 */
	public static function secret_key() {
		return trim( (string) dpfs_get_option( 'secret_key' ) );
	}

	/**
	 * Whether a secret key is present.
	 *
	 * @return bool
	 */
	public static function is_configured() {
		return '' !== self::secret_key();
	}

	/**
	 * Whether the configured key is a test-mode key.
	 *
	 * @return bool
	 */
	public static function is_test_mode() {
		$key = self::secret_key();
		return 0 === strpos( $key, 'sk_test' ) || 0 === strpos( $key, 'rk_test' );
	}

	/**
	 * Perform a request against the Stripe API.
	 *
	 * @param string $endpoint API endpoint, e.g. "checkout/sessions".
	 * @param array  $params   Request parameters.
	 * @param string $method   HTTP method.
	 * @return array|WP_Error Decoded body on success, WP_Error on failure.
	 */
	public static function request( $endpoint, $params = array(), $method = 'POST' ) {
		$key = self::secret_key();
		if ( '' === $key ) {
			return new WP_Error( 'dpfs_no_key', __( 'Stripe secret key is not configured.', 'demfilz-payment-forms-for-stripe' ) );
		}

		$url  = self::API_BASE . ltrim( $endpoint, '/' );
		$args = array(
			'method'  => $method,
			'timeout' => 30,
			'headers' => array(
				'Authorization'  => 'Bearer ' . $key,
				'Stripe-Version' => '2024-06-20',
			),
		);

		if ( 'GET' === $method ) {
			if ( ! empty( $params ) ) {
				$url = add_query_arg( $params, $url );
			}
		} else {
			$args['headers']['Content-Type'] = 'application/x-www-form-urlencoded';
			$args['body']                    = http_build_query( $params, '', '&' );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code >= 200 && $code < 300 ) {
			return is_array( $body ) ? $body : array();
		}

		$message = isset( $body['error']['message'] ) ? $body['error']['message'] : __( 'Unknown Stripe error.', 'demfilz-payment-forms-for-stripe' );
		return new WP_Error( 'dpfs_stripe_error', $message, array( 'status' => $code ) );
	}

	/**
	 * Create a Checkout Session.
	 *
	 * @param array $args Session arguments.
	 * @return array|WP_Error
	 */
	public static function create_checkout_session( $args ) {
		$params = array(
			'mode'                => 'payment',
			'success_url'         => $args['success_url'],
			'cancel_url'          => $args['cancel_url'],
			'client_reference_id' => substr( (string) $args['reference'], 0, 200 ),
			'line_items'          => array(
				array(
					'quantity'   => 1,
					'price_data' => array(
						'currency'     => $args['currency'],
						'unit_amount'  => $args['amount'],
						'product_data' => array(
							'name' => $args['product_name'],
						),
					),
				),
			),
			'metadata'            => $args['metadata'],
			'payment_intent_data' => array(
				'metadata' => $args['metadata'],
			),
		);

		if ( ! empty( $args['email'] ) ) {
			$params['customer_email'] = $args['email'];
		}
		if ( ! empty( $args['description'] ) ) {
			$params['payment_intent_data']['description'] = $args['description'];
		}

		return self::request( 'checkout/sessions', $params, 'POST' );
	}

	/**
	 * Retrieve a Checkout Session (with the payment intent expanded).
	 *
	 * @param string $session_id Session id.
	 * @return array|WP_Error
	 */
	public static function retrieve_session( $session_id ) {
		return self::request(
			'checkout/sessions/' . rawurlencode( $session_id ),
			array( 'expand' => array( 'payment_intent' ) ),
			'GET'
		);
	}

	/**
	 * Verify a Stripe webhook signature.
	 *
	 * @param string $payload    Raw request body.
	 * @param string $sig_header Stripe-Signature header.
	 * @param string $secret     Webhook signing secret.
	 * @param int    $tolerance  Allowed clock skew in seconds (0 to disable).
	 * @return bool
	 */
	public static function verify_webhook( $payload, $sig_header, $secret, $tolerance = 300 ) {
		if ( empty( $sig_header ) || empty( $secret ) ) {
			return false;
		}

		$timestamp  = 0;
		$signatures = array();
		foreach ( explode( ',', $sig_header ) as $part ) {
			$pieces = explode( '=', trim( $part ), 2 );
			if ( count( $pieces ) !== 2 ) {
				continue;
			}
			if ( 't' === $pieces[0] ) {
				$timestamp = (int) $pieces[1];
			} elseif ( 'v1' === $pieces[0] ) {
				$signatures[] = $pieces[1];
			}
		}

		if ( ! $timestamp || empty( $signatures ) ) {
			return false;
		}

		// Replay protection.
		if ( $tolerance > 0 && abs( time() - $timestamp ) > $tolerance ) {
			return false;
		}

		$expected = hash_hmac( 'sha256', $timestamp . '.' . $payload, $secret );
		foreach ( $signatures as $sig ) {
			if ( hash_equals( $expected, $sig ) ) {
				return true;
			}
		}
		return false;
	}
}
