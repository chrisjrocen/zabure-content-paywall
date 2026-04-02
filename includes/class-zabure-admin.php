<?php
/**
 * Admin UI for Zabure Content Paywall.
 *
 * Registers the settings page, post meta box, admin menus,
 * and REST endpoints for manual grant/revoke.
 *
 * @package ZabureContentPaywall
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Zabure_Admin
 */
class Zabure_Admin {

	/**
	 * Constructor — registers all admin hooks.
	 */
	public function __construct() {
		add_action( 'admin_menu',            [ $this, 'register_admin_menu' ] );
		add_action( 'admin_init',            [ $this, 'register_settings' ] );
		add_action( 'add_meta_boxes',        [ $this, 'add_meta_box' ] );
		add_action( 'save_post',             [ $this, 'save_meta_box' ], 10, 2 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_scripts' ] );
		add_action( 'rest_api_init',         [ $this, 'register_rest_routes' ] );
	}

	// =========================================================================
	// Admin menu
	// =========================================================================

	/**
	 * Register top-level admin menu and subpages.
	 *
	 * @return void
	 */
	public function register_admin_menu(): void {
		add_menu_page(
			esc_html__( 'Zabure Paywall', 'zabure-content-paywall' ),
			esc_html__( 'Zabure Paywall', 'zabure-content-paywall' ),
			'manage_options',
			'zabure-paywall',
			[ $this, 'render_access_manager_page' ],
			'dashicons-lock',
			58
		);

		add_submenu_page(
			'zabure-paywall',
			esc_html__( 'Access Manager', 'zabure-content-paywall' ),
			esc_html__( 'Access Manager', 'zabure-content-paywall' ),
			'manage_options',
			'zabure-paywall',
			[ $this, 'render_access_manager_page' ]
		);

		add_submenu_page(
			'zabure-paywall',
			esc_html__( 'Payment Logs', 'zabure-content-paywall' ),
			esc_html__( 'Payment Logs', 'zabure-content-paywall' ),
			'manage_options',
			'zabure-payment-logs',
			[ $this, 'render_payment_logs_page' ]
		);

		// Settings page is registered under Settings → Zabure Paywall.
		add_options_page(
			esc_html__( 'Zabure Paywall Settings', 'zabure-content-paywall' ),
			esc_html__( 'Zabure Paywall', 'zabure-content-paywall' ),
			'manage_options',
			'zabure-paywall-settings',
			[ $this, 'render_settings_page' ]
		);
	}

	// =========================================================================
	// Settings
	// =========================================================================

	/**
	 * Register plugin settings via the Settings API.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		$settings = [
			'zabure_api_key'         => 'sanitize_text_field',
			'zabure_webhook_secret'  => 'sanitize_text_field',
			'zabure_environment'     => [ $this, 'sanitize_environment' ],
			'zabure_phone_meta_key'  => 'sanitize_text_field',
		];

		foreach ( $settings as $option => $sanitize_cb ) {
			register_setting( 'zabure_paywall_settings', $option, [ 'sanitize_callback' => $sanitize_cb ] );
		}

		add_settings_section(
			'zabure_paywall_main',
			esc_html__( 'API Credentials', 'zabure-content-paywall' ),
			'__return_false',
			'zabure-paywall-settings'
		);

		$fields = [
			[
				'id'    => 'zabure_api_key',
				'label' => __( 'Zabure API Key', 'zabure-content-paywall' ),
				'cb'    => [ $this, 'render_field_api_key' ],
			],
			[
				'id'    => 'zabure_webhook_secret',
				'label' => __( 'Webhook Secret', 'zabure-content-paywall' ),
				'cb'    => [ $this, 'render_field_webhook_secret' ],
			],
			[
				'id'    => 'zabure_environment',
				'label' => __( 'Environment', 'zabure-content-paywall' ),
				'cb'    => [ $this, 'render_field_environment' ],
			],
			[
				'id'    => 'zabure_phone_meta_key',
				'label' => __( 'Phone Number Meta Key', 'zabure-content-paywall' ),
				'cb'    => [ $this, 'render_field_phone_meta_key' ],
			],
			[
				'id'    => 'zabure_webhook_url',
				'label' => __( 'Webhook Endpoint URL', 'zabure-content-paywall' ),
				'cb'    => [ $this, 'render_field_webhook_url' ],
			],
		];

		foreach ( $fields as $field ) {
			add_settings_field(
				$field['id'],
				esc_html( $field['label'] ),
				$field['cb'],
				'zabure-paywall-settings',
				'zabure_paywall_main'
			);
		}
	}

	/**
	 * Sanitize the environment option — must be 'sandbox' or 'live'.
	 *
	 * @param mixed $value Raw input.
	 * @return string
	 */
	public function sanitize_environment( mixed $value ): string {
		return in_array( $value, [ 'sandbox', 'live' ], true ) ? $value : 'sandbox';
	}

	// =========================================================================
	// Settings field renderers
	// =========================================================================

	/** @return void */
	public function render_field_api_key(): void {
		$value = esc_attr( (string) get_option( 'zabure_api_key', '' ) );
		echo '<input type="password" id="zabure_api_key" name="zabure_api_key" value="' . $value . '" class="regular-text" autocomplete="new-password">';
	}

	/** @return void */
	public function render_field_webhook_secret(): void {
		$value = esc_attr( (string) get_option( 'zabure_webhook_secret', '' ) );
		echo '<input type="password" id="zabure_webhook_secret" name="zabure_webhook_secret" value="' . $value . '" class="regular-text" autocomplete="new-password">';
	}

	/** @return void */
	public function render_field_environment(): void {
		$current = get_option( 'zabure_environment', 'sandbox' );
		foreach ( [ 'sandbox' => __( 'Sandbox (testing)', 'zabure-content-paywall' ), 'live' => __( 'Live (production)', 'zabure-content-paywall' ) ] as $val => $label ) {
			$checked = checked( $current, $val, false );
			echo '<label style="margin-right:1.5em;"><input type="radio" name="zabure_environment" value="' . esc_attr( $val ) . '" ' . $checked . '> ' . esc_html( $label ) . '</label>';
		}
	}

	/** @return void */
	public function render_field_phone_meta_key(): void {
		$value = esc_attr( (string) get_option( 'zabure_phone_meta_key', 'phone_number' ) );
		echo '<input type="text" id="zabure_phone_meta_key" name="zabure_phone_meta_key" value="' . $value . '" class="regular-text">';
		echo '<p class="description">' . esc_html__( 'The wp_usermeta key that stores the user\'s phone number. Default: phone_number', 'zabure-content-paywall' ) . '</p>';
	}

	/** @return void */
	public function render_field_webhook_url(): void {
		$url = rest_url( 'zabure-paywall/v1/webhook' );
		echo '<input type="text" value="' . esc_attr( $url ) . '" class="large-text" readonly onfocus="this.select();">';
		echo '<p class="description">' . esc_html__( 'Copy this URL into your Zabure dashboard webhook settings.', 'zabure-content-paywall' ) . '</p>';
	}

	// =========================================================================
	// Settings page
	// =========================================================================

	/**
	 * Render the plugin settings page.
	 *
	 * @return void
	 */
	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'zabure-content-paywall' ) );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Zabure Paywall Settings', 'zabure-content-paywall' ); ?></h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'zabure_paywall_settings' );
				do_settings_sections( 'zabure-paywall-settings' );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	// =========================================================================
	// Post meta box
	// =========================================================================

	/**
	 * Register the Zabure Paywall Settings meta box on post edit screens.
	 *
	 * @return void
	 */
	public function add_meta_box(): void {
		add_meta_box(
			'zabure_paywall_settings',
			esc_html__( 'Zabure Paywall Settings', 'zabure-content-paywall' ),
			[ $this, 'render_meta_box' ],
			'post',
			'side',
			'default'
		);
	}

	/**
	 * Render the meta box HTML.
	 *
	 * @param WP_Post $post The post being edited.
	 * @return void
	 */
	public function render_meta_box( WP_Post $post ): void {
		wp_nonce_field( 'zabure_meta_box_save_' . $post->ID, 'zabure_meta_box_nonce' );

		$is_premium   = (int) get_post_meta( $post->ID, '_zabure_is_premium', true );
		$amount       = (int) get_post_meta( $post->ID, '_zabure_amount', true );
		$currency     = (string) get_post_meta( $post->ID, '_zabure_currency', true );
		$preview_n    = (int) get_post_meta( $post->ID, '_zabure_preview_paragraphs', true );
		$link_id      = (string) get_post_meta( $post->ID, '_zabure_link_id', true );
		$link_url     = (string) get_post_meta( $post->ID, '_zabure_link_url', true );

		if ( ! $preview_n ) {
			$preview_n = 2;
		}

		$currencies = [ 'UGX', 'KES', 'TZS', 'USD' ];
		?>
		<p>
			<label>
				<input type="checkbox" name="_zabure_is_premium" id="_zabure_is_premium" value="1" <?php checked( 1, $is_premium ); ?>>
				<?php esc_html_e( 'Premium post (paywall enabled)', 'zabure-content-paywall' ); ?>
			</label>
		</p>

		<p>
			<label for="_zabure_amount"><strong><?php esc_html_e( 'Price (in smallest currency unit, e.g. 5000 = UGX 50)', 'zabure-content-paywall' ); ?></strong></label><br>
			<input type="number" name="_zabure_amount" id="_zabure_amount" value="<?php echo esc_attr( $amount ); ?>" min="0" class="widefat">
		</p>

		<p>
			<label for="_zabure_currency"><strong><?php esc_html_e( 'Currency', 'zabure-content-paywall' ); ?></strong></label><br>
			<select name="_zabure_currency" id="_zabure_currency" class="widefat">
				<?php foreach ( $currencies as $c ) : ?>
					<option value="<?php echo esc_attr( $c ); ?>" <?php selected( $currency, $c ); ?>><?php echo esc_html( $c ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>

		<p>
			<label for="_zabure_preview_paragraphs"><strong><?php esc_html_e( 'Free preview paragraphs', 'zabure-content-paywall' ); ?></strong></label><br>
			<input type="number" name="_zabure_preview_paragraphs" id="_zabure_preview_paragraphs" value="<?php echo esc_attr( $preview_n ); ?>" min="1" class="widefat">
		</p>

		<hr>
		<p><strong><?php esc_html_e( 'Payment Link Status', 'zabure-content-paywall' ); ?></strong></p>
		<?php if ( $link_url ) : ?>
			<p>
				✅ <a href="<?php echo esc_url( $link_url ); ?>" target="_blank"><?php echo esc_html( $link_url ); ?></a>
				<button type="button" class="button button-small" id="zabure-copy-link" data-url="<?php echo esc_attr( $link_url ); ?>">
					<?php esc_html_e( 'Copy', 'zabure-content-paywall' ); ?>
				</button>
			</p>
		<?php elseif ( $is_premium ) : ?>
			<p class="description" style="color:#d63638;">
				⚠️ <?php esc_html_e( 'No payment link found. Save the post to create one automatically.', 'zabure-content-paywall' ); ?>
			</p>
		<?php else : ?>
			<p class="description"><?php esc_html_e( 'Enable Premium above and save to create a Zabure payment link.', 'zabure-content-paywall' ); ?></p>
		<?php endif; ?>

		<?php
		// Show any admin notice transient from a previous save failure.
		$error = get_transient( 'zabure_link_error_' . $post->ID );
		if ( $error ) {
			echo '<p style="color:#d63638;">' . wp_kses_post( $error ) . '</p>';
			delete_transient( 'zabure_link_error_' . $post->ID );
		}
		?>
		<?php
	}

	/**
	 * Handle save_post — persist meta fields and create the Zabure payment link if needed.
	 *
	 * @param int     $post_id The post ID.
	 * @param WP_Post $post    The post object.
	 * @return void
	 */
	public function save_meta_box( int $post_id, WP_Post $post ): void {
		// Nonce check.
		if (
			! isset( $_POST['zabure_meta_box_nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['zabure_meta_box_nonce'] ) ), 'zabure_meta_box_save_' . $post_id )
		) {
			return;
		}

		// Autosave guard.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Capability check.
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Only handle posts.
		if ( 'post' !== $post->post_type ) {
			return;
		}

		$old_is_premium = (int) get_post_meta( $post_id, '_zabure_is_premium', true );
		$new_is_premium = isset( $_POST['_zabure_is_premium'] ) ? 1 : 0;
		$amount         = isset( $_POST['_zabure_amount'] ) ? absint( $_POST['_zabure_amount'] ) : 0;
		$currency       = isset( $_POST['_zabure_currency'] ) ? sanitize_text_field( wp_unslash( $_POST['_zabure_currency'] ) ) : 'UGX';
		$preview_n      = isset( $_POST['_zabure_preview_paragraphs'] ) ? max( 1, absint( $_POST['_zabure_preview_paragraphs'] ) ) : 2;

		// Validate currency.
		if ( ! in_array( $currency, [ 'UGX', 'KES', 'TZS', 'USD' ], true ) ) {
			$currency = 'UGX';
		}

		update_post_meta( $post_id, '_zabure_is_premium',         $new_is_premium );
		update_post_meta( $post_id, '_zabure_amount',             $amount );
		update_post_meta( $post_id, '_zabure_currency',           $currency );
		update_post_meta( $post_id, '_zabure_preview_paragraphs', $preview_n );

		// Create payment link if premium is newly enabled and no link exists yet.
		$existing_link_id = (string) get_post_meta( $post_id, '_zabure_link_id', true );

		if ( 1 === $new_is_premium && empty( $existing_link_id ) && $amount > 0 ) {
			$api         = new Zabure_API();
			$description = sprintf(
				/* translators: %s: post title */
				__( 'Access: %s', 'zabure-content-paywall' ),
				get_the_title( $post_id )
			);
			$result = $api->create_payment_link( $post_id, $amount, $currency, $description );

			if ( is_wp_error( $result ) ) {
				set_transient(
					'zabure_link_error_' . $post_id,
					sprintf(
						/* translators: %s: error message */
						__( 'Failed to create Zabure payment link: %s', 'zabure-content-paywall' ),
						$result->get_error_message()
					),
					60
				);
			} else {
				$link_id  = $result['id'] ?? '';
				$link_url = $result['url'] ?? '';
				update_post_meta( $post_id, '_zabure_link_id',  sanitize_text_field( $link_id ) );
				update_post_meta( $post_id, '_zabure_link_url', esc_url_raw( $link_url ) );
			}
		}
	}

	// =========================================================================
	// Admin pages
	// =========================================================================

	/**
	 * Render the Access Manager admin page.
	 *
	 * Shows all users who have been granted access to premium posts.
	 *
	 * @return void
	 */
	public function render_access_manager_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'zabure-content-paywall' ) );
		}

		// Collect all users who have _zabure_paid_posts meta.
		$user_query = new WP_User_Query(
			[
				'meta_key' => '_zabure_paid_posts', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'number'   => 200,
			]
		);

		$users = $user_query->get_results();
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Access Manager', 'zabure-content-paywall' ); ?></h1>
			<hr class="wp-header-end">

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'User', 'zabure-content-paywall' ); ?></th>
						<th><?php esc_html_e( 'Post', 'zabure-content-paywall' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'zabure-content-paywall' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php
					$rows_output = false;
					foreach ( $users as $user ) {
						$paid_posts = (array) get_user_meta( $user->ID, '_zabure_paid_posts', true );
						foreach ( $paid_posts as $post_id ) {
							$post_id = (int) $post_id;
							$post    = get_post( $post_id );
							if ( ! $post ) {
								continue;
							}
							$rows_output = true;
							?>
							<tr>
								<td>
									<?php echo esc_html( $user->display_name ); ?><br>
									<small><?php echo esc_html( $user->user_email ); ?></small>
								</td>
								<td>
									<a href="<?php echo esc_url( get_edit_post_link( $post_id ) ); ?>"><?php echo esc_html( get_the_title( $post_id ) ); ?></a>
								</td>
								<td>
									<button
										type="button"
										class="button button-small zabure-revoke-btn"
										data-user-id="<?php echo esc_attr( $user->ID ); ?>"
										data-post-id="<?php echo esc_attr( $post_id ); ?>"
										data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>">
										<?php esc_html_e( 'Revoke', 'zabure-content-paywall' ); ?>
									</button>
								</td>
							</tr>
							<?php
						}
					}

					if ( ! $rows_output ) {
						echo '<tr><td colspan="3">' . esc_html__( 'No access records found.', 'zabure-content-paywall' ) . '</td></tr>';
					}
					?>
				</tbody>
			</table>
		</div>

		<script>
		document.querySelectorAll('.zabure-revoke-btn').forEach(function(btn) {
			btn.addEventListener('click', function() {
				if (!confirm('<?php echo esc_js( __( 'Revoke access for this user?', 'zabure-content-paywall' ) ); ?>')) {
					return;
				}
				var userId = btn.dataset.userId;
				var postId = btn.dataset.postId;
				var nonce  = btn.dataset.nonce;

				fetch('<?php echo esc_js( rest_url( 'zabure-paywall/v1/admin/revoke' ) ); ?>', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': nonce,
					},
					body: JSON.stringify({ user_id: parseInt(userId), post_id: parseInt(postId) }),
				})
				.then(function(r) { return r.json(); })
				.then(function(data) {
					if (data.success) {
						btn.closest('tr').remove();
					} else {
						alert('<?php echo esc_js( __( 'Error revoking access.', 'zabure-content-paywall' ) ); ?>');
					}
				});
			});
		});
		</script>
		<?php
	}

	/**
	 * Render the Payment Logs admin page.
	 *
	 * @return void
	 */
	public function render_payment_logs_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'zabure-content-paywall' ) );
		}

		$filters = [];

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['status'] ) ) {
			$filters['status'] = sanitize_text_field( wp_unslash( $_GET['status'] ) );
		}
		if ( ! empty( $_GET['date_from'] ) ) {
			$filters['date_from'] = sanitize_text_field( wp_unslash( $_GET['date_from'] ) );
		}
		if ( ! empty( $_GET['date_to'] ) ) {
			$filters['date_to'] = sanitize_text_field( wp_unslash( $_GET['date_to'] ) );
		}
		// phpcs:enable

		// Handle CSV export.
		if ( isset( $_GET['export_csv'] ) && current_user_can( 'manage_options' ) ) {
			check_admin_referer( 'zabure_export_csv' );
			$this->export_csv( $filters );
			exit;
		}

		$sessions = Zabure_Database::get_all_sessions( $filters );

		$status_colours = [
			'pending'           => '#2271b1',
			'redirect_received' => '#996800',
			'completed'         => '#1a7a2e',
			'failed'            => '#b32d2e',
			'expired'           => '#646970',
		];
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Payment Logs', 'zabure-content-paywall' ); ?></h1>

			<form method="get" action="">
				<input type="hidden" name="page" value="zabure-payment-logs">
				<select name="status">
					<option value=""><?php esc_html_e( 'All Statuses', 'zabure-content-paywall' ); ?></option>
					<?php foreach ( array_keys( $status_colours ) as $s ) : ?>
						<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $filters['status'] ?? '', $s ); ?>><?php echo esc_html( ucfirst( str_replace( '_', ' ', $s ) ) ); ?></option>
					<?php endforeach; ?>
				</select>
				<input type="date" name="date_from" value="<?php echo esc_attr( $filters['date_from'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'From', 'zabure-content-paywall' ); ?>">
				<input type="date" name="date_to" value="<?php echo esc_attr( $filters['date_to'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'To', 'zabure-content-paywall' ); ?>">
				<button type="submit" class="button"><?php esc_html_e( 'Filter', 'zabure-content-paywall' ); ?></button>
				<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( array_merge( $filters, [ 'export_csv' => '1' ] ), admin_url( 'admin.php?page=zabure-payment-logs' ) ), 'zabure_export_csv' ) ); ?>" class="button"><?php esc_html_e( 'Export CSV', 'zabure-content-paywall' ); ?></a>
			</form>

			<table class="wp-list-table widefat fixed striped" style="margin-top:1em;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Date', 'zabure-content-paywall' ); ?></th>
						<th><?php esc_html_e( 'User', 'zabure-content-paywall' ); ?></th>
						<th><?php esc_html_e( 'Post', 'zabure-content-paywall' ); ?></th>
						<th><?php esc_html_e( 'Amount', 'zabure-content-paywall' ); ?></th>
						<th><?php esc_html_e( 'Currency', 'zabure-content-paywall' ); ?></th>
						<th><?php esc_html_e( 'Status', 'zabure-content-paywall' ); ?></th>
						<th><?php esc_html_e( 'Source', 'zabure-content-paywall' ); ?></th>
						<th><?php esc_html_e( 'Transaction ID', 'zabure-content-paywall' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $sessions ) ) : ?>
						<tr><td colspan="8"><?php esc_html_e( 'No payment sessions found.', 'zabure-content-paywall' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $sessions as $session ) : ?>
							<tr>
								<td><?php echo esc_html( $session->initiated_at ); ?></td>
								<td><?php
									$user = get_user_by( 'id', $session->user_id );
									echo $user ? esc_html( $user->display_name ) : esc_html( $session->user_id );
								?></td>
								<td><?php echo esc_html( get_the_title( (int) $session->post_id ) ); ?></td>
								<td><?php echo esc_html( number_format( $session->amount ) ); ?></td>
								<td><?php echo esc_html( $session->currency ); ?></td>
								<td>
									<span style="
										display:inline-block;
										padding:2px 8px;
										border-radius:3px;
										color:#fff;
										background:<?php echo esc_attr( $status_colours[ $session->status ] ?? '#646970' ); ?>;">
										<?php echo esc_html( ucfirst( str_replace( '_', ' ', $session->status ) ) ); ?>
									</span>
								</td>
								<td><?php echo esc_html( $session->source ?? '—' ); ?></td>
								<td><?php echo esc_html( $session->zabure_transaction_id ?? '—' ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Output a CSV of session data and exit.
	 *
	 * @param array $filters Active filters to apply to the query.
	 * @return void
	 */
	private function export_csv( array $filters ): void {
		$sessions = Zabure_Database::get_all_sessions( array_merge( $filters, [ 'limit' => 5000 ] ) );

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="zabure-payment-logs-' . gmdate( 'Y-m-d' ) . '.csv"' );

		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, [ 'Date', 'User ID', 'Post ID', 'Amount', 'Currency', 'Status', 'Source', 'Transaction ID' ] );

		foreach ( $sessions as $s ) {
			fputcsv( $out, [
				$s->initiated_at,
				$s->user_id,
				$s->post_id,
				$s->amount,
				$s->currency,
				$s->status,
				$s->source ?? '',
				$s->zabure_transaction_id ?? '',
			] );
		}

		fclose( $out );
	}

	// =========================================================================
	// REST endpoints: manual grant / revoke
	// =========================================================================

	/**
	 * Register admin REST routes for manual grant and revoke.
	 *
	 * @return void
	 */
	public function register_rest_routes(): void {
		register_rest_route(
			'zabure-paywall/v1',
			'/admin/grant',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'rest_manual_grant' ],
				'permission_callback' => [ $this, 'admin_permission_check' ],
				'args'                => [
					'user_id' => [
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					],
					'post_id' => [
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					],
				],
			]
		);

		register_rest_route(
			'zabure-paywall/v1',
			'/admin/revoke',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'rest_manual_revoke' ],
				'permission_callback' => [ $this, 'admin_permission_check' ],
				'args'                => [
					'user_id' => [
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					],
					'post_id' => [
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					],
				],
			]
		);
	}

	/**
	 * Permission callback: must have manage_options capability.
	 *
	 * @return bool
	 */
	public function admin_permission_check(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Manually grant access for a user/post combination.
	 *
	 * Creates a synthetic session record so the grant is traceable.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response
	 */
	public function rest_manual_grant( WP_REST_Request $request ): WP_REST_Response {
		$user_id = (int) $request->get_param( 'user_id' );
		$post_id = (int) $request->get_param( 'post_id' );

		if ( ! get_user_by( 'id', $user_id ) ) {
			return new WP_REST_Response( [ 'success' => false, 'message' => __( 'User not found.', 'zabure-content-paywall' ) ], 404 );
		}

		if ( ! get_post( $post_id ) ) {
			return new WP_REST_Response( [ 'success' => false, 'message' => __( 'Post not found.', 'zabure-content-paywall' ) ], 404 );
		}

		// Create a synthetic session for audit trail.
		$post_amount   = (int) get_post_meta( $post_id, '_zabure_amount', true );
		$post_currency = (string) get_post_meta( $post_id, '_zabure_currency', true );
		$token         = bin2hex( random_bytes( 32 ) );

		$session_id = Zabure_Database::insert_session(
			[
				'session_token' => $token,
				'user_id'       => $user_id,
				'post_id'       => $post_id,
				'amount'        => $post_amount,
				'currency'      => $post_currency ?: 'UGX',
				'status'        => 'pending',
				'source'        => 'manual',
				'initiated_at'  => current_time( 'mysql' ),
				'expires_at'    => gmdate( 'Y-m-d H:i:s', time() + 1800 ),
			]
		);

		if ( ! $session_id ) {
			// Even if session insert fails, still grant access.
			$session_id = 0;
		}

		$access_manager = new Zabure_Access_Manager();
		$access_manager->grant_access( $user_id, $post_id, $session_id, 'manual' );

		return new WP_REST_Response(
			[ 'success' => true, 'message' => __( 'Access granted.', 'zabure-content-paywall' ) ],
			200
		);
	}

	/**
	 * Revoke a user's access to a post.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response
	 */
	public function rest_manual_revoke( WP_REST_Request $request ): WP_REST_Response {
		$user_id = (int) $request->get_param( 'user_id' );
		$post_id = (int) $request->get_param( 'post_id' );

		$access_manager = new Zabure_Access_Manager();
		$access_manager->revoke_access( $user_id, $post_id );

		return new WP_REST_Response(
			[ 'success' => true, 'message' => __( 'Access revoked.', 'zabure-content-paywall' ) ],
			200
		);
	}

	// =========================================================================
	// Admin scripts
	// =========================================================================

	/**
	 * Enqueue admin JavaScript on post edit screens.
	 *
	 * @param string $hook The current admin page hook.
	 * @return void
	 */
	public function enqueue_admin_scripts( string $hook ): void {
		if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
			return;
		}

		// Inline script — small enough not to warrant a separate file.
		wp_add_inline_script(
			'jquery',
			"
			jQuery(document).ready(function(\$) {
				\$('#zabure-copy-link').on('click', function() {
					var url = \$(this).data('url');
					if (navigator.clipboard) {
						navigator.clipboard.writeText(url).then(function() {
							alert('" . esc_js( __( 'Payment link URL copied to clipboard!', 'zabure-content-paywall' ) ) . "');
						});
					} else {
						window.prompt('" . esc_js( __( 'Copy this URL:', 'zabure-content-paywall' ) ) . "', url);
					}
				});
			});
			"
		);
	}
}
