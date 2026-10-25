<?php
/**
 * Front-end shortcode, asset loading and the AJAX session creator.
 *
 * @package Demfilz_Payment_Forms_for_Stripe
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders [dpfs_payment_form] and creates Checkout Sessions.
 */
class DPFS_Shortcode {

	/**
	 * Instance counter so multiple forms on one page get unique ids.
	 *
	 * @var int
	 */
	private static $instances = 0;

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_shortcode( 'dpfs_payment_form', array( $this, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
		add_action( 'wp_ajax_dpfs_create_session', array( $this, 'ajax_create_session' ) );
		add_action( 'wp_ajax_nopriv_dpfs_create_session', array( $this, 'ajax_create_session' ) );
	}

	/**
	 * Register (but do not yet enqueue) front-end assets.
	 */
	public function register_assets() {
		// Rounded, modern UI font (Nunito) — self-hosted, no external requests.
		wp_register_style( 'dpfs-font', DPFS_URL . 'assets/css/spf-fonts.css', array(), DPFS_VERSION );
		wp_register_style( 'dpfs-form', DPFS_URL . 'assets/css/spf-form.css', array( 'dpfs-font' ), DPFS_VERSION );
		wp_register_script( 'dpfs-form', DPFS_URL . 'assets/js/spf-form.js', array(), DPFS_VERSION, true );
		wp_localize_script(
			'dpfs-form',
			'DPFS_FORM',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'dpfs_create_session' ),
				'strings'  => array(
					'processing' => __( 'Processing…', 'demfilz-payment-forms-for-stripe' ),
					'error'      => __( 'Something went wrong. Please try again.', 'demfilz-payment-forms-for-stripe' ),
					'invalid'    => __( 'Please complete all required fields with a valid amount.', 'demfilz-payment-forms-for-stripe' ),
				),
			)
		);
	}

	/**
	 * Render the shortcode.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function render( $atts ) {
		$atts = shortcode_atts(
			array(
				'title'        => __( 'Make a Payment', 'demfilz-payment-forms-for-stripe' ),
				'description'  => '',
				'button'       => dpfs_get_option( 'button_text', __( 'Pay Now', 'demfilz-payment-forms-for-stripe' ) ),
				'amount'       => '',
				'currency'     => dpfs_get_option( 'currency', 'aud' ),
				'amount_label' => __( 'Amount', 'demfilz-payment-forms-for-stripe' ),
				'theme'        => 'light',
			),
			$atts,
			'dpfs_payment_form'
		);

		wp_enqueue_style( 'dpfs-form' );
		wp_enqueue_script( 'dpfs-form' );

		$theme      = in_array( $atts['theme'], array( 'dark', 'auto' ), true ) ? $atts['theme'] : 'light';
		$wrap_class = 'spf-wrap' . ( 'dark' === $theme ? ' spf-dark' : ( 'auto' === $theme ? ' spf-auto' : '' ) );

		// Success / cancel display state (the visitor is returning from Stripe's hosted checkout; no nonce is possible).
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display state; the value is just matched against known strings.
		$state = isset( $_GET['dpfs_payment'] ) ? sanitize_key( wp_unslash( $_GET['dpfs_payment'] ) ) : '';
		if ( 'success' === $state ) {
			return $this->render_success( $wrap_class );
		}
		if ( 'cancel' === $state ) {
			return $this->render_cancel( $wrap_class );
		}

		++self::$instances;
		$uid        = 'spf-' . self::$instances;
		$currency   = strtolower( preg_replace( '/[^a-zA-Z]/', '', $atts['currency'] ) );
		$symbol     = dpfs_currency_symbol( $currency );
		$fixed      = ( '' !== $atts['amount'] ) ? (float) $atts['amount'] : '';
		$configured = DPFS_Stripe::is_configured();

		ob_start();
		?>
		<div class="<?php echo esc_attr( $wrap_class ); ?>">
			<form class="spf-form" id="<?php echo esc_attr( $uid ); ?>" novalidate>
				<?php if ( $atts['title'] || $atts['description'] ) : ?>
					<div class="spf-head">
						<span class="spf-head-icon" aria-hidden="true">
							<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
						</span>
						<div>
							<?php
							if ( $atts['title'] ) :
								?>
								<h3 class="spf-title"><?php echo esc_html( $atts['title'] ); ?></h3><?php endif; ?>
							<?php
							if ( $atts['description'] ) :
								?>
								<p class="spf-desc"><?php echo esc_html( $atts['description'] ); ?></p><?php endif; ?>
						</div>
					</div>
				<?php endif; ?>

				<?php if ( ! $configured ) : ?>
					<div class="spf-alert spf-alert-error"><?php esc_html_e( 'Payments are not yet configured. Please add your Stripe key in the admin.', 'demfilz-payment-forms-for-stripe' ); ?></div>
				<?php endif; ?>

				<div class="spf-field">
					<div class="spf-input">
						<svg class="spf-ic" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
						<input type="text" id="<?php echo esc_attr( $uid ); ?>-name" name="name" required autocomplete="name" aria-label="<?php esc_attr_e( 'Name', 'demfilz-payment-forms-for-stripe' ); ?>" placeholder="<?php esc_attr_e( 'Full name *', 'demfilz-payment-forms-for-stripe' ); ?>">
					</div>
				</div>

				<div class="spf-row">
					<div class="spf-field">
						<div class="spf-input">
							<svg class="spf-ic" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 6L2 7"/></svg>
							<input type="email" id="<?php echo esc_attr( $uid ); ?>-email" name="email" required autocomplete="email" aria-label="<?php esc_attr_e( 'Email', 'demfilz-payment-forms-for-stripe' ); ?>" placeholder="<?php esc_attr_e( 'Email address *', 'demfilz-payment-forms-for-stripe' ); ?>">
						</div>
					</div>
					<div class="spf-field">
						<div class="spf-input">
							<svg class="spf-ic" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
							<input type="tel" id="<?php echo esc_attr( $uid ); ?>-phone" name="phone" autocomplete="tel" aria-label="<?php esc_attr_e( 'Phone', 'demfilz-payment-forms-for-stripe' ); ?>" placeholder="<?php esc_attr_e( 'Phone number', 'demfilz-payment-forms-for-stripe' ); ?>">
						</div>
					</div>
				</div>

				<div class="spf-field">
					<div class="spf-input">
						<svg class="spf-ic" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><line x1="4" y1="9" x2="20" y2="9"/><line x1="4" y1="15" x2="20" y2="15"/><line x1="10" y1="3" x2="8" y2="21"/><line x1="16" y1="3" x2="14" y2="21"/></svg>
						<input type="text" id="<?php echo esc_attr( $uid ); ?>-reference" name="reference" aria-label="<?php esc_attr_e( 'Reference Number', 'demfilz-payment-forms-for-stripe' ); ?>" placeholder="<?php esc_attr_e( 'Reference number', 'demfilz-payment-forms-for-stripe' ); ?>">
					</div>
				</div>

				<div class="spf-field">
					<div class="spf-input spf-input--area">
						<svg class="spf-ic" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
						<textarea id="<?php echo esc_attr( $uid ); ?>-message" name="message" rows="3" aria-label="<?php esc_attr_e( 'Message', 'demfilz-payment-forms-for-stripe' ); ?>" placeholder="<?php esc_attr_e( 'Message (optional)', 'demfilz-payment-forms-for-stripe' ); ?>"></textarea>
					</div>
				</div>

				<div class="spf-field">
					<div class="spf-amount">
						<?php
						if ( $symbol ) :
							?>
							<span class="spf-amount-symbol"><?php echo esc_html( $symbol ); ?></span><?php endif; ?>
						<input type="number" id="<?php echo esc_attr( $uid ); ?>-amount" name="amount" step="0.01" min="1" inputmode="decimal" aria-label="<?php echo esc_attr( $atts['amount_label'] ); ?>" placeholder="0.00"<?php echo ( '' !== $fixed ) ? ' value="' . esc_attr( $fixed ) . '" readonly' : ''; ?> required>
						<span class="spf-amount-cur"><?php echo esc_html( strtoupper( $currency ) ); ?></span>
					</div>
				</div>

				<input type="hidden" name="currency" value="<?php echo esc_attr( $currency ); ?>">
				<input type="hidden" name="return_url" value="<?php echo esc_url( $this->current_url() ); ?>">

				<div class="spf-alert spf-alert-error spf-error" hidden></div>

				<button type="submit" class="spf-btn"<?php disabled( ! $configured ); ?>>
					<span class="spf-btn-label"><?php echo esc_html( $atts['button'] ); ?></span>
					<svg class="spf-btn-arrow" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
					<span class="spf-spinner" hidden></span>
				</button>

				<div class="spf-trust">
					<div class="spf-cards" aria-hidden="true">
						<?php echo $this->card_marks(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- trusted static SVG markup. ?>
					</div>
					<p class="spf-secure">
						<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
						<?php esc_html_e( 'Secured by Stripe', 'demfilz-payment-forms-for-stripe' ); ?>
					</p>
				</div>
			</form>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Success confirmation view.
	 *
	 * @param string $wrap_class Wrapper CSS classes (light/dark theme).
	 * @return string
	 */
	private function render_success( $wrap_class = 'spf-wrap' ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only; the session id is validated against the Stripe API below.
		$session_id = isset( $_GET['dpfs_session'] ) ? sanitize_text_field( wp_unslash( $_GET['dpfs_session'] ) ) : '';
		$ref        = '';
		$amount_str = '';

		if ( $session_id && DPFS_Stripe::is_configured() ) {
			$session = DPFS_Stripe::retrieve_session( $session_id );
			if ( ! is_wp_error( $session ) && ! empty( $session['id'] ) ) {
				// Fallback recording in case the webhook has not arrived yet.
				dpfs_record_completed_session( $session );
				if ( isset( $session['amount_total'], $session['currency'] ) ) {
					$amount_str = dpfs_currency_symbol( $session['currency'] ) . dpfs_format_minor( $session['amount_total'], $session['currency'] ) . ' ' . strtoupper( $session['currency'] );
				}
				if ( ! empty( $session['client_reference_id'] ) ) {
					$ref = $session['client_reference_id'];
				}
			}
		}

		ob_start();
		?>
		<div class="<?php echo esc_attr( $wrap_class ); ?>">
			<div class="spf-result spf-result-success">
				<div class="spf-result-icon">
					<svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>
				</div>
				<h3><?php esc_html_e( 'Payment successful', 'demfilz-payment-forms-for-stripe' ); ?></h3>
				<p><?php esc_html_e( 'Thank you — your payment has been received.', 'demfilz-payment-forms-for-stripe' ); ?></p>
				<?php
				if ( $amount_str ) :
					?>
					<p class="spf-result-amount"><?php echo esc_html( $amount_str ); ?></p><?php endif; ?>
				<?php
				if ( $ref ) :
					?>
					<p class="spf-result-ref"><?php printf( /* translators: %s: reference number */ esc_html__( 'Reference: %s', 'demfilz-payment-forms-for-stripe' ), esc_html( $ref ) ); ?></p><?php endif; ?>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Cancelled view.
	 *
	 * @param string $wrap_class Wrapper CSS classes (light/dark theme).
	 * @return string
	 */
	private function render_cancel( $wrap_class = 'spf-wrap' ) {
		ob_start();
		?>
		<div class="<?php echo esc_attr( $wrap_class ); ?>">
			<div class="spf-result spf-result-cancel">
				<div class="spf-result-icon">
					<svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6L6 18M6 6l12 12"/></svg>
				</div>
				<h3><?php esc_html_e( 'Payment cancelled', 'demfilz-payment-forms-for-stripe' ); ?></h3>
				<p><?php esc_html_e( 'Your payment was not completed. You can try again below.', 'demfilz-payment-forms-for-stripe' ); ?></p>
				<a class="spf-btn spf-btn-inline" href="<?php echo esc_url( $this->current_url() ); ?>"><?php esc_html_e( 'Try again', 'demfilz-payment-forms-for-stripe' ); ?></a>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Absolute URL of the current page with our own query args removed.
	 *
	 * Stripe requires fully-qualified success_url / cancel_url values, so this
	 * must return https://host/path — never a bare relative path (which is what
	 * remove_query_arg() alone returns and which Stripe rejects as "Not a valid URL").
	 *
	 * @return string
	 */
	private function current_url() {
		$home   = wp_parse_url( home_url() );
		$scheme = ! empty( $home['scheme'] ) ? $home['scheme'] : ( is_ssl() ? 'https' : 'http' );
		$host   = isset( $home['host'] ) ? $home['host'] : '';
		$port   = isset( $home['port'] ) ? ':' . $home['port'] : '';
		$uri    = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

		// Canonical host (avoids Host-header spoofing) + the real request path.
		$url = $scheme . '://' . $host . $port . $uri;

		return remove_query_arg( array( 'dpfs_payment', 'dpfs_session' ), esc_url_raw( $url ) );
	}

	/**
	 * Compact, recognisable card-brand marks for the trust row.
	 *
	 * @return string Static SVG markup.
	 */
	private function card_marks() {
		$visa = '<svg width="34" height="22" viewBox="0 0 34 22" xmlns="http://www.w3.org/2000/svg"><rect width="34" height="22" rx="4" fill="#fff" stroke="#e6e8ee"/><text x="17" y="15" text-anchor="middle" font-family="Arial,Helvetica,sans-serif" font-weight="bold" font-style="italic" font-size="10" letter-spacing="0.5" fill="#1a1f71">VISA</text></svg>';
		$mc   = '<svg width="34" height="22" viewBox="0 0 34 22" xmlns="http://www.w3.org/2000/svg"><rect width="34" height="22" rx="4" fill="#fff" stroke="#e6e8ee"/><circle cx="14" cy="11" r="6" fill="#eb001b"/><circle cx="20" cy="11" r="6" fill="#f79e1b" fill-opacity="0.9"/></svg>';
		$amex = '<svg width="34" height="22" viewBox="0 0 34 22" xmlns="http://www.w3.org/2000/svg"><rect width="34" height="22" rx="4" fill="#1f72cf"/><text x="17" y="14" text-anchor="middle" font-family="Arial,Helvetica,sans-serif" font-weight="bold" font-size="7" letter-spacing="0.3" fill="#fff">AMEX</text></svg>';
		return $visa . $mc . $amex;
	}

	/**
	 * AJAX: validate the form, store a pending record and create a Checkout Session.
	 */
	public function ajax_create_session() {
		check_ajax_referer( 'dpfs_create_session', 'nonce' );

		if ( ! DPFS_Stripe::is_configured() ) {
			wp_send_json_error( array( 'message' => __( 'Payments are not configured.', 'demfilz-payment-forms-for-stripe' ) ) );
		}

		$name      = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$email     = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$phone     = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
		$reference = isset( $_POST['reference'] ) ? sanitize_text_field( wp_unslash( $_POST['reference'] ) ) : '';
		$message   = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';
		$currency  = isset( $_POST['currency'] ) ? sanitize_text_field( wp_unslash( $_POST['currency'] ) ) : 'usd';
		$currency  = strtolower( preg_replace( '/[^a-zA-Z]/', '', $currency ) );
		$amount    = isset( $_POST['amount'] ) ? (float) sanitize_text_field( wp_unslash( $_POST['amount'] ) ) : 0;
		$return    = isset( $_POST['return_url'] ) ? esc_url_raw( wp_unslash( $_POST['return_url'] ) ) : '';

		// Stripe needs an absolute URL; fall back to the site home if anything else slipped through.
		if ( ! $return || ! preg_match( '#^https?://#i', $return ) ) {
			$return = home_url( '/' );
		}

		if ( '' === $name || ! is_email( $email ) ) {
			wp_send_json_error( array( 'message' => __( 'Please enter your name and a valid email.', 'demfilz-payment-forms-for-stripe' ) ) );
		}

		if ( '' === $currency ) {
			$currency = 'usd';
		}

		$min = (float) dpfs_get_option( 'min_amount', 1 );
		if ( $amount < $min ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: %s: minimum amount */
						__( 'Minimum amount is %s.', 'demfilz-payment-forms-for-stripe' ),
						dpfs_currency_symbol( $currency ) . number_format( $min, 2 )
					),
				)
			);
		}

		$minor = dpfs_amount_to_minor( $amount, $currency );
		if ( $minor < 1 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid amount.', 'demfilz-payment-forms-for-stripe' ) ) );
		}

		// Persist a pending record first.
		$payment_id = DPFS_DB::insert(
			array(
				'name'      => $name,
				'email'     => $email,
				'phone'     => $phone,
				'reference' => $reference,
				'message'   => $message,
				'amount'    => $minor,
				'currency'  => $currency,
				'status'    => 'pending',
			)
		);

		// Build the return URLs. The placeholder must reach Stripe un-encoded by
		// add_query_arg(), so it is appended manually.
		$success_url  = add_query_arg( array( 'dpfs_payment' => 'success' ), $return );
		$success_url .= ( false === strpos( $success_url, '?' ) ? '?' : '&' ) . 'dpfs_session={CHECKOUT_SESSION_ID}';
		$cancel_url   = add_query_arg( array( 'dpfs_payment' => 'cancel' ), $return );

		$product_name = $reference
			/* translators: %s: reference number */
			? sprintf( __( 'Payment — Ref %s', 'demfilz-payment-forms-for-stripe' ), $reference )
			: __( 'Payment', 'demfilz-payment-forms-for-stripe' );

		$session = DPFS_Stripe::create_checkout_session(
			array(
				'success_url'  => $success_url,
				'cancel_url'   => $cancel_url,
				'amount'       => $minor,
				'currency'     => $currency,
				'email'        => $email,
				'reference'    => $reference,
				'product_name' => $product_name,
				'description'  => $message,
				'metadata'     => array(
					'dpfs_payment_id' => (string) $payment_id,
					'name'            => $name,
					'phone'           => $phone,
					'reference'       => $reference,
					'message'         => mb_substr( $message, 0, 450 ),
				),
			)
		);

		if ( is_wp_error( $session ) ) {
			DPFS_DB::update( $payment_id, array( 'status' => 'failed' ) );
			wp_send_json_error( array( 'message' => $session->get_error_message() ) );
		}

		DPFS_DB::update( $payment_id, array( 'session_id' => $session['id'] ) );

		wp_send_json_success( array( 'url' => $session['url'] ) );
	}
}
