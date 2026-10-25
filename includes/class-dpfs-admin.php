<?php
/**
 * Admin menu, settings and the payment-summary dashboard.
 *
 * @package Demfilz_Payment_Forms_for_Stripe
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the "Stripe Payments" admin screen.
 */
class DPFS_Admin {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_post_dpfs_export', array( $this, 'export_csv' ) );
	}

	/**
	 * Register the top-level menu.
	 */
	public function menu() {
		add_menu_page(
			__( 'Stripe Payments', 'demfilz-payment-forms-for-stripe' ),
			__( 'Stripe Payments', 'demfilz-payment-forms-for-stripe' ),
			'manage_options',
			'dpfs-payments',
			array( $this, 'render_page' ),
			'dashicons-money-alt',
			56
		);
	}

	/**
	 * Enqueue admin assets on our screen only.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function assets( $hook ) {
		if ( 'toplevel_page_dpfs-payments' !== $hook ) {
			return;
		}
		wp_enqueue_style( 'dpfs-admin', DPFS_URL . 'assets/css/spf-admin.css', array(), DPFS_VERSION );
		wp_enqueue_script( 'dpfs-admin', DPFS_URL . 'assets/js/spf-admin.js', array(), DPFS_VERSION, true );
	}

	/**
	 * Register the settings option and its sanitizer.
	 */
	public function register_settings() {
		register_setting(
			'dpfs_settings_group',
			DPFS_OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => array(),
			)
		);
	}

	/**
	 * Sanitize the settings array.
	 *
	 * @param array $input Raw input.
	 * @return array
	 */
	public function sanitize( $input ) {
		$out = array();

		$out['secret_key']         = isset( $input['secret_key'] ) ? trim( sanitize_text_field( $input['secret_key'] ) ) : '';
		$out['webhook_secret']     = isset( $input['webhook_secret'] ) ? trim( sanitize_text_field( $input['webhook_secret'] ) ) : '';
		$out['notification_email'] = isset( $input['notification_email'] ) ? sanitize_email( $input['notification_email'] ) : '';
		$out['currency']           = isset( $input['currency'] ) ? strtolower( preg_replace( '/[^a-zA-Z]/', '', $input['currency'] ) ) : 'aud';
		$out['button_text']        = isset( $input['button_text'] ) ? sanitize_text_field( $input['button_text'] ) : '';
		$out['min_amount']         = isset( $input['min_amount'] ) ? max( 0, (float) $input['min_amount'] ) : 1;

		if ( '' === $out['currency'] ) {
			$out['currency'] = 'aud';
		}
		if ( '' !== $out['notification_email'] && ! is_email( $out['notification_email'] ) ) {
			add_settings_error( 'dpfs_settings', 'bad_email', __( 'The notification email address is not valid.', 'demfilz-payment-forms-for-stripe' ) );
			$out['notification_email'] = '';
		}

		return $out;
	}

	/**
	 * Render the page shell with tabs.
	 */
	public function render_page() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab navigation; no state change.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'payments';
		?>
		<div class="wrap spf-admin">
			<h1 class="spf-admin-h1">
				<span class="spf-logo">$</span>
				<?php esc_html_e( 'Stripe Payments', 'demfilz-payment-forms-for-stripe' ); ?>
				<?php if ( DPFS_Stripe::is_configured() && DPFS_Stripe::is_test_mode() ) : ?>
					<span class="spf-badge spf-badge-test"><?php esc_html_e( 'Test mode', 'demfilz-payment-forms-for-stripe' ); ?></span>
				<?php elseif ( DPFS_Stripe::is_configured() ) : ?>
					<span class="spf-badge spf-badge-live"><?php esc_html_e( 'Live', 'demfilz-payment-forms-for-stripe' ); ?></span>
				<?php endif; ?>
			</h1>

			<nav class="nav-tab-wrapper spf-tabs">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=dpfs-payments&tab=payments' ) ); ?>" class="nav-tab <?php echo ( 'payments' === $tab ) ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Payment Summary', 'demfilz-payment-forms-for-stripe' ); ?></a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=dpfs-payments&tab=settings' ) ); ?>" class="nav-tab <?php echo ( 'settings' === $tab ) ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Settings', 'demfilz-payment-forms-for-stripe' ); ?></a>
			</nav>

			<?php
			if ( 'settings' === $tab ) {
				$this->render_settings();
			} else {
				$this->render_payments();
			}
			?>
		</div>
		<?php
	}

	/**
	 * Settings tab.
	 */
	private function render_settings() {
		$secret     = dpfs_get_option( 'secret_key' );
		$webhook    = dpfs_get_option( 'webhook_secret' );
		$email      = dpfs_get_option( 'notification_email', get_option( 'admin_email' ) );
		$currency   = dpfs_get_option( 'currency', 'aud' );
		$button     = dpfs_get_option( 'button_text', __( 'Pay Now', 'demfilz-payment-forms-for-stripe' ) );
		$min        = dpfs_get_option( 'min_amount', 1 );
		$currencies = array( 'aud', 'usd', 'gbp', 'eur', 'nzd', 'cad', 'sgd', 'hkd', 'jpy', 'inr' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Core adds this flag on the Settings API redirect; display-only.
		if ( ! empty( $_GET['settings-updated'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'demfilz-payment-forms-for-stripe' ) . '</p></div>';
		}
		settings_errors( 'dpfs_settings' );
		?>
		<form method="post" action="options.php" class="spf-settings-form">
			<?php settings_fields( 'dpfs_settings_group' ); ?>

			<div class="spf-card">
				<h2><?php esc_html_e( 'Stripe API', 'demfilz-payment-forms-for-stripe' ); ?></h2>
				<p class="spf-card-sub"><?php esc_html_e( 'Find these in your Stripe Dashboard → Developers.', 'demfilz-payment-forms-for-stripe' ); ?></p>

				<div class="spf-setting">
					<label for="spf-secret"><?php esc_html_e( 'Secret API Key', 'demfilz-payment-forms-for-stripe' ); ?></label>
					<div class="spf-key">
						<input type="password" id="spf-secret" name="dpfs_settings[secret_key]" value="<?php echo esc_attr( $secret ); ?>" placeholder="sk_live_…" autocomplete="off" spellcheck="false">
						<button type="button" class="spf-reveal" aria-label="<?php esc_attr_e( 'Show / hide', 'demfilz-payment-forms-for-stripe' ); ?>">&#128065;</button>
					</div>
					<p class="spf-hint"><?php esc_html_e( 'Used to create payments. Use a test key (sk_test_…) while testing.', 'demfilz-payment-forms-for-stripe' ); ?></p>
				</div>

				<div class="spf-setting">
					<label for="spf-webhook"><?php esc_html_e( 'Webhook Signing Secret', 'demfilz-payment-forms-for-stripe' ); ?></label>
					<div class="spf-key">
						<input type="password" id="spf-webhook" name="dpfs_settings[webhook_secret]" value="<?php echo esc_attr( $webhook ); ?>" placeholder="whsec_…" autocomplete="off" spellcheck="false">
						<button type="button" class="spf-reveal" aria-label="<?php esc_attr_e( 'Show / hide', 'demfilz-payment-forms-for-stripe' ); ?>">&#128065;</button>
					</div>
					<p class="spf-hint"><?php esc_html_e( 'Confirms payments securely. Add the endpoint below in Stripe, then paste its signing secret here.', 'demfilz-payment-forms-for-stripe' ); ?></p>
				</div>

				<div class="spf-setting">
					<label><?php esc_html_e( 'Webhook Endpoint URL', 'demfilz-payment-forms-for-stripe' ); ?></label>
					<div class="spf-copy">
						<input type="text" readonly value="<?php echo esc_attr( DPFS_Webhook::url() ); ?>" onclick="this.select();">
						<button type="button" class="button spf-copy-btn" data-copy="<?php echo esc_attr( DPFS_Webhook::url() ); ?>"><?php esc_html_e( 'Copy', 'demfilz-payment-forms-for-stripe' ); ?></button>
					</div>
					<p class="spf-hint"><?php esc_html_e( 'In Stripe: Developers → Webhooks → Add endpoint, and listen for the "checkout.session.completed" event.', 'demfilz-payment-forms-for-stripe' ); ?></p>
				</div>
			</div>

			<div class="spf-card">
				<h2><?php esc_html_e( 'Notifications & Defaults', 'demfilz-payment-forms-for-stripe' ); ?></h2>

				<div class="spf-setting">
					<label for="spf-email"><?php esc_html_e( 'Notification Email', 'demfilz-payment-forms-for-stripe' ); ?></label>
					<input type="email" id="spf-email" name="dpfs_settings[notification_email]" value="<?php echo esc_attr( $email ); ?>" placeholder="you@example.com">
					<p class="spf-hint"><?php esc_html_e( 'Where to send an alert each time a payment succeeds.', 'demfilz-payment-forms-for-stripe' ); ?></p>
				</div>

				<div class="spf-grid-2">
					<div class="spf-setting">
						<label for="spf-currency"><?php esc_html_e( 'Currency', 'demfilz-payment-forms-for-stripe' ); ?></label>
						<select id="spf-currency" name="dpfs_settings[currency]">
							<?php foreach ( $currencies as $c ) : ?>
								<option value="<?php echo esc_attr( $c ); ?>" <?php selected( $currency, $c ); ?>><?php echo esc_html( strtoupper( $c ) ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="spf-setting">
						<label for="spf-min"><?php esc_html_e( 'Minimum Amount', 'demfilz-payment-forms-for-stripe' ); ?></label>
						<input type="number" id="spf-min" step="0.01" min="0" name="dpfs_settings[min_amount]" value="<?php echo esc_attr( $min ); ?>">
					</div>
				</div>

				<div class="spf-setting">
					<label for="spf-button"><?php esc_html_e( 'Default Button Text', 'demfilz-payment-forms-for-stripe' ); ?></label>
					<input type="text" id="spf-button" name="dpfs_settings[button_text]" value="<?php echo esc_attr( $button ); ?>" placeholder="Pay Now">
				</div>
			</div>

			<div class="spf-card spf-card-muted">
				<h2><?php esc_html_e( 'Add the form to a page', 'demfilz-payment-forms-for-stripe' ); ?></h2>
				<p><?php esc_html_e( 'Paste this shortcode into any page or post:', 'demfilz-payment-forms-for-stripe' ); ?></p>
				<code class="spf-shortcode">[dpfs_payment_form]</code>
				<p class="spf-hint"><?php esc_html_e( 'Optional attributes: title, description, button, amount (fixed), currency. For example:', 'demfilz-payment-forms-for-stripe' ); ?></p>
				<code class="spf-shortcode">[dpfs_payment_form title="Pay your invoice" amount="150" currency="aud"]</code>
			</div>

			<?php submit_button( __( 'Save Settings', 'demfilz-payment-forms-for-stripe' ), 'primary spf-save' ); ?>
		</form>

		<div class="spf-card spf-cta-card">
			<div class="spf-cta-text">
				<h2><?php esc_html_e( 'Need something custom?', 'demfilz-payment-forms-for-stripe' ); ?></h2>
				<p><?php esc_html_e( 'Extra fields, custom branding, multi-currency rules, deeper integrations or a bespoke checkout flow — we can tailor this plugin to your business.', 'demfilz-payment-forms-for-stripe' ); ?></p>
			</div>
			<a class="spf-cta-btn" href="https://demfilz.com" target="_blank" rel="noopener noreferrer">
				<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>
				<?php esc_html_e( 'Request Custom Modifications', 'demfilz-payment-forms-for-stripe' ); ?>
			</a>
		</div>
		<?php
	}

	/**
	 * Payment-summary tab.
	 */
	private function render_payments() {
		$stats = DPFS_DB::stats();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filter; no state change.
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination; no state change.
		$paged    = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
		$per      = 20;
		$rows     = DPFS_DB::query(
			array(
				'status'   => $status,
				'page'     => $paged,
				'per_page' => $per,
			)
		);
		$total    = DPFS_DB::count( $status );
		$pages    = max( 1, (int) ceil( $total / $per ) );
		$fallback = dpfs_get_option( 'currency', 'aud' );

		$filters = array(
			''        => __( 'All', 'demfilz-payment-forms-for-stripe' ),
			'paid'    => __( 'Paid', 'demfilz-payment-forms-for-stripe' ),
			'pending' => __( 'Pending', 'demfilz-payment-forms-for-stripe' ),
			'failed'  => __( 'Failed', 'demfilz-payment-forms-for-stripe' ),
		);
		?>
		<div class="spf-stats">
			<div class="spf-stat">
				<span class="spf-stat-label"><?php esc_html_e( 'Total collected', 'demfilz-payment-forms-for-stripe' ); ?></span>
				<span class="spf-stat-num"><?php echo esc_html( $this->money_map( $stats['by_currency'], $fallback ) ); ?></span>
			</div>
			<div class="spf-stat">
				<span class="spf-stat-label"><?php esc_html_e( 'This month', 'demfilz-payment-forms-for-stripe' ); ?></span>
				<span class="spf-stat-num"><?php echo esc_html( $this->money_map( $stats['month_by_curr'], $fallback ) ); ?></span>
			</div>
			<div class="spf-stat">
				<span class="spf-stat-label"><?php esc_html_e( 'Paid', 'demfilz-payment-forms-for-stripe' ); ?></span>
				<span class="spf-stat-num"><?php echo esc_html( number_format_i18n( $stats['paid_count'] ) ); ?></span>
			</div>
			<div class="spf-stat">
				<span class="spf-stat-label"><?php esc_html_e( 'Pending', 'demfilz-payment-forms-for-stripe' ); ?></span>
				<span class="spf-stat-num"><?php echo esc_html( number_format_i18n( $stats['pending_count'] ) ); ?></span>
			</div>
		</div>

		<div class="spf-table-head">
			<div class="spf-filters">
				<?php
				foreach ( $filters as $key => $label ) :
					$url = admin_url( 'admin.php?page=dpfs-payments&tab=payments' . ( $key ? '&status=' . $key : '' ) );
					$cls = ( $status === $key ) ? 'active' : '';
					?>
					<a class="<?php echo esc_attr( $cls ); ?>" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</div>
			<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=dpfs_export' ), 'dpfs_export' ) ); ?>"><?php esc_html_e( 'Export CSV', 'demfilz-payment-forms-for-stripe' ); ?></a>
		</div>

		<table class="spf-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Date', 'demfilz-payment-forms-for-stripe' ); ?></th>
					<th><?php esc_html_e( 'Customer', 'demfilz-payment-forms-for-stripe' ); ?></th>
					<th><?php esc_html_e( 'Contact', 'demfilz-payment-forms-for-stripe' ); ?></th>
					<th><?php esc_html_e( 'Reference', 'demfilz-payment-forms-for-stripe' ); ?></th>
					<th><?php esc_html_e( 'Amount', 'demfilz-payment-forms-for-stripe' ); ?></th>
					<th><?php esc_html_e( 'Status', 'demfilz-payment-forms-for-stripe' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="6" class="spf-empty"><?php esc_html_e( 'No payments to show yet.', 'demfilz-payment-forms-for-stripe' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $rows as $r ) : ?>
						<tr>
							<td data-label="<?php esc_attr_e( 'Date', 'demfilz-payment-forms-for-stripe' ); ?>"><?php echo esc_html( mysql2date( 'M j, Y · g:ia', $r->created_at ) ); ?></td>
							<td data-label="<?php esc_attr_e( 'Customer', 'demfilz-payment-forms-for-stripe' ); ?>">
								<strong><?php echo esc_html( $r->name ? $r->name : '—' ); ?></strong>
								<?php
								if ( $r->message ) :
									?>
									<div class="spf-msg"><?php echo esc_html( wp_trim_words( $r->message, 12 ) ); ?></div><?php endif; ?>
							</td>
							<td data-label="<?php esc_attr_e( 'Contact', 'demfilz-payment-forms-for-stripe' ); ?>">
								<?php echo esc_html( $r->email ); ?>
								<?php
								if ( $r->phone ) :
									?>
									<div class="spf-muted"><?php echo esc_html( $r->phone ); ?></div><?php endif; ?>
							</td>
							<td data-label="<?php esc_attr_e( 'Reference', 'demfilz-payment-forms-for-stripe' ); ?>"><?php echo $r->reference ? esc_html( $r->reference ) : '—'; ?></td>
							<td data-label="<?php esc_attr_e( 'Amount', 'demfilz-payment-forms-for-stripe' ); ?>"><strong><?php echo esc_html( dpfs_currency_symbol( $r->currency ) . dpfs_format_minor( $r->amount, $r->currency ) ); ?></strong> <span class="spf-muted"><?php echo esc_html( strtoupper( $r->currency ) ); ?></span></td>
							<td data-label="<?php esc_attr_e( 'Status', 'demfilz-payment-forms-for-stripe' ); ?>"><span class="spf-pill spf-pill-<?php echo esc_attr( $r->status ); ?>"><?php echo esc_html( ucfirst( $r->status ) ); ?></span></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>

		<?php if ( $pages > 1 ) : ?>
			<div class="spf-pagination">
				<?php
				echo wp_kses_post(
					paginate_links(
						array(
							'base'      => add_query_arg( 'paged', '%#%' ),
							'format'    => '',
							'current'   => $paged,
							'total'     => $pages,
							'prev_text' => '‹',
							'next_text' => '›',
						)
					)
				);
				?>
			</div>
		<?php endif; ?>
		<?php
	}

	/**
	 * Format a currency => minor-amount map into a display string.
	 *
	 * @param array  $map               Currency map.
	 * @param string $fallback_currency Currency to show when the map is empty.
	 * @return string
	 */
	private function money_map( $map, $fallback_currency = 'aud' ) {
		if ( empty( $map ) ) {
			return dpfs_currency_symbol( $fallback_currency ) . '0.00';
		}
		$parts = array();
		foreach ( $map as $cur => $minor ) {
			$parts[] = dpfs_currency_symbol( $cur ) . dpfs_format_minor( $minor, $cur ) . ' ' . strtoupper( $cur );
		}
		return implode( ' · ', $parts );
	}

	/**
	 * Stream all payments as a CSV download.
	 */
	public function export_csv() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'demfilz-payment-forms-for-stripe' ) );
		}
		check_admin_referer( 'dpfs_export' );

		$rows = DPFS_DB::query(
			array(
				'per_page' => 100000,
				'page'     => 1,
			)
		);

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=stripe-payments-' . gmdate( 'Y-m-d' ) . '.csv' );

		// Streaming a CSV download straight to the browser; WP_Filesystem cannot write to php://output.
		// phpcs:disable WordPress.WP.AlternativeFunctions
		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'Date', 'Name', 'Email', 'Phone', 'Reference', 'Message', 'Amount', 'Currency', 'Status', 'Session ID' ) );
		foreach ( $rows as $r ) {
			fputcsv(
				$out,
				array(
					$r->created_at,
					$r->name,
					$r->email,
					$r->phone,
					$r->reference,
					$r->message,
					dpfs_format_minor( $r->amount, $r->currency ),
					strtoupper( $r->currency ),
					$r->status,
					$r->session_id,
				)
			);
		}
		fclose( $out );
		// phpcs:enable WordPress.WP.AlternativeFunctions
		exit;
	}
}
