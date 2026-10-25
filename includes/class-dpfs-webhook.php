<?php
/**
 * Stripe webhook receiver.
 *
 * @package Demfilz_Payment_Forms_for_Stripe
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and handles the REST webhook endpoint.
 */
class DPFS_Webhook {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_route' ) );
	}

	/**
	 * Register the REST route.
	 */
	public function register_route() {
		register_rest_route(
			'dpfs/v1',
			'/webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle' ),
				// Public on purpose: Stripe is not a WordPress user, so this endpoint is
				// authenticated by the Stripe webhook signature inside handle(), where a
				// valid signature is mandatory — unsigned requests are rejected.
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Public webhook URL.
	 *
	 * @return string
	 */
	public static function url() {
		return rest_url( 'dpfs/v1/webhook' );
	}

	/**
	 * Handle an incoming Stripe event.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle( WP_REST_Request $request ) {
		$payload    = $request->get_body();
		$sig_header = $request->get_header( 'stripe_signature' );
		$secret     = trim( (string) dpfs_get_option( 'webhook_secret' ) );

		// The Stripe signature is the only authentication for this public endpoint,
		// so it is mandatory: with no configured secret, or an invalid/again-used
		// signature, we reject the request and change nothing. This stops forged
		// payment-status changes from an unauthenticated caller.
		if ( '' === $secret ) {
			return new WP_REST_Response( array( 'error' => 'webhook not configured' ), 401 );
		}
		if ( ! DPFS_Stripe::verify_webhook( $payload, $sig_header, $secret ) ) {
			return new WP_REST_Response( array( 'error' => 'invalid signature' ), 401 );
		}

		$event = json_decode( $payload, true );
		if ( ! is_array( $event ) || empty( $event['type'] ) ) {
			return new WP_REST_Response( array( 'error' => 'invalid payload' ), 400 );
		}

		$session = ( isset( $event['data']['object'] ) && is_array( $event['data']['object'] ) ) ? $event['data']['object'] : array();

		switch ( $event['type'] ) {
			case 'checkout.session.completed':
			case 'checkout.session.async_payment_succeeded':
				dpfs_record_completed_session( $session );
				break;

			case 'checkout.session.async_payment_failed':
			case 'checkout.session.expired':
				if ( ! empty( $session['id'] ) ) {
					$record = DPFS_DB::get_by_session( $session['id'] );
					if ( $record && 'paid' !== $record->status ) {
						DPFS_DB::update( $record->id, array( 'status' => 'failed' ) );
					}
				}
				break;
		}

		return new WP_REST_Response( array( 'received' => true ), 200 );
	}
}
