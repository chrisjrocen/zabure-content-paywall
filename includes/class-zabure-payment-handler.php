<?php
/**
 * Payment handler for Zabure Content Paywall.
 *
 * Registers and handles the REST API endpoints for initiating payment
 * and polling session status.
 *
 * @package ZabureContentPaywall
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Zabure_Payment_Handler
 */
class Zabure_Payment_Handler {

	/**
	 * Constructor — registers REST routes.
	 */
	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
	}

	/**
	 * Register the initiate and check-status REST routes.
	 *
	 * @return void
	 */
	public function register_rest_routes(): void {
		// POST /wp-json/zabure-paywall/v1/initiate
		register_rest_route(
			'zabure-paywall/v1',
			'/initiate',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'initiate_payment' ],
				'permission_callback' => [ $this, 'logged_in_check' ],
				'args'                => [
					'post_id'      => [
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					],
					'phone_number' => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);

		// GET /wp-json/zabure-paywall/v1/check-status
		register_rest_route(
			'zabure-paywall/v1',
			'/check-status',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'check_payment_status' ],
				'permission_callback' => [ $this, 'logged_in_check' ],
				'args'                => [
					'token' => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);
	}

	/**
	 * Permission callback: user must be logged in.
	 *
	 * @return bool|WP_Error
	 */
	public function logged_in_check(): bool|WP_Error {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'zabure_unauthorized',
				__( 'You must be logged in to continue.', 'zabure-content-paywall' ),
				[ 'status' => 401 ]
			);
		}

		return true;
	}

	/**
	 * Handle the payment initiation request.
	 *
	 * Creates a session record, sets a cookie, and returns the Zabure payment URL.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response
	 */
	public function initiate_payment( WP_REST_Request $request ): WP_REST_Response {
		$post_id = (int) $request->get_param( 'post_id' );
		$user_id = get_current_user_id();

		// --- Validate post ---
		$post = get_post( $post_id );

		if ( ! $post ) {
			return new WP_REST_Response(
				[ 'error' => __( 'Post not found.', 'zabure-content-paywall' ) ],
				400
			);
		}

		if ( '1' !== (string) get_post_meta( $post_id, '_zabure_is_premium', true ) ) {
			return new WP_REST_Response(
				[ 'error' => __( 'This post is not a premium post.', 'zabure-content-paywall' ) ],
				400
			);
		}

		// --- Check payment link exists ---
		$link_url = (string) get_post_meta( $post_id, '_zabure_link_url', true );

		if ( empty( $link_url ) ) {
			return new WP_REST_Response(
				[ 'error' => __( 'Payment link not configured for this post. Please contact the site administrator.', 'zabure-content-paywall' ) ],
				500
			);
		}

		// --- Validate phone number ---
		$raw_phone = (string) $request->get_param( 'phone_number' );
		$phone     = preg_replace( '/[^0-9]/', '', $raw_phone );

		if ( strlen( $phone ) < 9 || strlen( $phone ) > 15 ) {
			return new WP_REST_Response(
				[ 'error' => __( 'Invalid phone number. Please enter a valid phone number (9–15 digits).', 'zabure-content-paywall' ) ],
				400
			);
		}

		// --- Save phone to user meta ---
		$phone_meta_key = (string) get_option( 'zabure_phone_meta_key', 'phone_number' );
		update_user_meta( $user_id, $phone_meta_key, $phone );

		// --- Generate session token ---
		$session_token = bin2hex( random_bytes( 32 ) );

		// --- Insert session into DB ---
		$amount   = (int) get_post_meta( $post_id, '_zabure_amount', true );
		$currency = (string) get_post_meta( $post_id, '_zabure_currency', true );
		$link_id  = (string) get_post_meta( $post_id, '_zabure_link_id', true );

		$session_id = Zabure_Database::insert_session(
			[
				'session_token'  => $session_token,
				'user_id'        => $user_id,
				'post_id'        => $post_id,
				'amount'         => $amount,
				'currency'       => $currency,
				'zabure_link_id' => $link_id,
				'status'         => 'pending',
				'initiated_at'   => current_time( 'mysql' ),
				'expires_at'     => gmdate( 'Y-m-d H:i:s', time() + 1800 ),
			]
		);

		if ( false === $session_id ) {
			return new WP_REST_Response(
				[ 'error' => __( 'Failed to create payment session. Please try again.', 'zabure-content-paywall' ) ],
				500
			);
		}

		// --- Set HttpOnly session cookie ---
		// Use header() directly to support SameSite=Lax, which setcookie() lacks on older PHP.
		$cookie_value   = rawurlencode( $session_token );
		$cookie_expiry  = time() + 1800;
		$cookie_expires = gmdate( 'D, d M Y H:i:s T', $cookie_expiry );
		$secure_flag    = is_ssl() ? '; Secure' : '';

		header(
			'Set-Cookie: zabure_session=' . $cookie_value
			. '; Expires=' . $cookie_expires
			. '; Path=/'
			. $secure_flag
			. '; HttpOnly; SameSite=Lax',
			false
		);

		return new WP_REST_Response(
			[
				'payment_url'   => $link_url,
				'session_token' => $session_token,
			],
			200
		);
	}

	/**
	 * Handle the payment status poll request.
	 *
	 * Returns the current session status so the JS processing page
	 * can redirect the user when payment is confirmed.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response
	 */
	public function check_payment_status( WP_REST_Request $request ): WP_REST_Response {
		$token   = (string) $request->get_param( 'token' );
		$user_id = get_current_user_id();

		$session = Zabure_Database::get_session_by_token( $token );

		if ( ! $session ) {
			return new WP_REST_Response(
				[
					'status'  => 'expired',
					'message' => __( 'Session not found. Please refresh and try again.', 'zabure-content-paywall' ),
				],
				200
			);
		}

		// Ownership check — prevent token fishing by other logged-in users.
		if ( (int) $session->user_id !== $user_id ) {
			return new WP_REST_Response(
				[
					'status'  => 'expired',
					'message' => __( 'Session not found. Please refresh and try again.', 'zabure-content-paywall' ),
				],
				200
			);
		}

		if ( 'completed' === $session->status ) {
			return new WP_REST_Response(
				[
					'status'       => 'completed',
					'redirect_url' => get_permalink( (int) $session->post_id ),
				],
				200
			);
		}

		if ( 'failed' === $session->status ) {
			return new WP_REST_Response(
				[
					'status'  => 'failed',
					'message' => __( 'Your payment was not completed. Please try again.', 'zabure-content-paywall' ),
				],
				200
			);
		}

		if ( 'expired' === $session->status || strtotime( $session->expires_at ) < time() ) {
			return new WP_REST_Response(
				[
					'status'  => 'expired',
					'message' => __( 'Session expired. Please refresh and try again.', 'zabure-content-paywall' ),
				],
				200
			);
		}

		return new WP_REST_Response( [ 'status' => 'pending' ], 200 );
	}
}
