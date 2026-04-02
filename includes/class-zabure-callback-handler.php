<?php
/**
 * Callback handler for Zabure Content Paywall.
 *
 * Handles the /zabure-return/ browser redirect that Zabure sends after
 * the user completes payment on the Zabure payment page.
 *
 * @package ZabureContentPaywall
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Zabure_Callback_Handler
 */
class Zabure_Callback_Handler {

	/**
	 * Constructor — registers rewrite rule and template_redirect hook.
	 */
	public function __construct() {
		add_action( 'init',              [ $this, 'add_rewrite_rule' ] );
		add_filter( 'query_vars',        [ $this, 'add_query_var' ] );
		add_action( 'template_redirect', [ $this, 'maybe_handle_callback' ] );
	}

	/**
	 * Register the /zabure-return/ rewrite rule.
	 *
	 * @return void
	 */
	public function add_rewrite_rule(): void {
		add_rewrite_rule( '^zabure-return/?$', 'index.php?zabure_return=1', 'top' );
	}

	/**
	 * Register the zabure_return query variable.
	 *
	 * @param array $vars Existing query variables.
	 * @return array
	 */
	public function add_query_var( array $vars ): array {
		$vars[] = 'zabure_return';
		return $vars;
	}

	/**
	 * Check if the current request is for /zabure-return/ and handle it.
	 *
	 * @return void
	 */
	public function maybe_handle_callback(): void {
		if ( ! get_query_var( 'zabure_return' ) ) {
			return;
		}

		$this->handle_callback();
	}

	/**
	 * Process the Zabure return callback.
	 *
	 * Validates the session cookie, updates session status, and either
	 * redirects to the full post (if already confirmed) or shows the
	 * payment processing/polling page.
	 *
	 * @return void
	 */
	public function handle_callback(): void {
		// 1. Require the user to be logged in.
		if ( ! is_user_logged_in() ) {
			wp_redirect( wp_login_url( sanitize_url( $_SERVER['REQUEST_URI'] ?? '' ) ) );
			exit;
		}

		$current_user_id = get_current_user_id();

		// 2. Get and validate post_id from the URL.
		$post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;

		if ( ! $post_id || ! get_post( $post_id ) ) {
			wp_die(
				esc_html__( 'Invalid return URL. Post not found.', 'zabure-content-paywall' ),
				esc_html__( 'Error', 'zabure-content-paywall' ),
				[ 'response' => 400 ]
			);
		}

		// 3. Read the session cookie.
		$raw_cookie = isset( $_COOKIE['zabure_session'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['zabure_session'] ) ) : '';

		if ( empty( $raw_cookie ) ) {
			$this->show_callback_error(
				__( 'Session not found. If you completed payment, please contact support.', 'zabure-content-paywall' ),
				$post_id
			);
			return;
		}

		// 4. Fetch session from DB.
		$session = Zabure_Database::get_session_by_token( $raw_cookie );

		// 5. Validate session integrity.
		if ( ! $session ) {
			$this->show_callback_error(
				__( 'Session not found. If you completed payment, please contact support.', 'zabure-content-paywall' ),
				$post_id
			);
			return;
		}

		if ( (int) $session->user_id !== $current_user_id ) {
			$this->show_callback_error(
				__( 'Session mismatch. Please log in and try again.', 'zabure-content-paywall' ),
				$post_id
			);
			return;
		}

		if ( (int) $session->post_id !== $post_id ) {
			$this->show_callback_error(
				__( 'Session does not match this post. Please contact support.', 'zabure-content-paywall' ),
				$post_id
			);
			return;
		}

		if ( strtotime( $session->expires_at ) < time() ) {
			$this->show_callback_error(
				__( 'Your session has expired. Please go back to the article and try again.', 'zabure-content-paywall' ),
				$post_id
			);
			return;
		}

		// 6. If session is already completed (webhook beat the redirect), go straight to post.
		if ( 'completed' === $session->status ) {
			$this->expire_cookie();
			wp_redirect( get_permalink( $post_id ) );
			exit;
		}

		// Only process pending/redirect_received sessions.
		if ( ! in_array( $session->status, [ 'pending', 'redirect_received' ], true ) ) {
			$this->show_callback_error(
				__( 'This payment session is no longer valid.', 'zabure-content-paywall' ),
				$post_id
			);
			return;
		}

		// 7. Update session to redirect_received.
		Zabure_Database::update_session_status( (int) $session->id, 'redirect_received' );

		// 8. Expire the cookie (we have the token stored in DB now).
		$this->expire_cookie();

		// 9. Render the payment processing/polling page.
		$session_token = $raw_cookie;
		$post_title    = get_the_title( $post_id );

		wp_enqueue_style(
			'zabure-paywall',
			ZABURE_PAYWALL_URL . 'assets/css/paywall.css',
			[],
			ZABURE_PAYWALL_VERSION
		);

		wp_enqueue_script(
			'zabure-paywall',
			ZABURE_PAYWALL_URL . 'assets/js/paywall.js',
			[ 'jquery' ],
			ZABURE_PAYWALL_VERSION,
			true
		);

		wp_localize_script(
			'zabure-paywall',
			'zabure_paywall',
			[
				'ajax_url'     => rest_url(),
				'nonce'        => wp_create_nonce( 'wp_rest' ),
				'post_id'      => $post_id,
				'is_logged_in' => '1',
			]
		);

		include ZABURE_PAYWALL_PATH . 'templates/payment-processing.php';
		exit;
	}

	/**
	 * Display an error message on the callback page.
	 *
	 * @param string $message The error message to display.
	 * @param int    $post_id The post ID the user was trying to access.
	 * @return void
	 */
	private function show_callback_error( string $message, int $post_id ): void {
		$post_url = $post_id ? get_permalink( $post_id ) : home_url();

		wp_die(
			esc_html( $message )
			. ' <a href="' . esc_url( $post_url ) . '">'
			. esc_html__( 'Return to article', 'zabure-content-paywall' )
			. '</a>',
			esc_html__( 'Payment Verification Error', 'zabure-content-paywall' ),
			[ 'response' => 400 ]
		);
	}

	/**
	 * Expire the zabure_session cookie by setting its expiry to the past.
	 *
	 * @return void
	 */
	private function expire_cookie(): void {
		header(
			'Set-Cookie: zabure_session=deleted; Expires=Thu, 01 Jan 1970 00:00:01 GMT; Path=/; HttpOnly; SameSite=Lax',
			false
		);
	}
}
