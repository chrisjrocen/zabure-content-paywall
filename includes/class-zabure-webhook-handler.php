<?php
/**
 * Webhook handler for Zabure Content Paywall.
 *
 * This is the most security-critical class in the plugin.
 * HMAC-SHA256 signature verification happens before any other processing.
 *
 * @package ZabureContentPaywall
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Zabure_Webhook_Handler
 */
class Zabure_Webhook_Handler {

	/**
	 * Constructor — registers the REST route.
	 */
	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
	}

	/**
	 * Register the webhook REST route.
	 *
	 * @return void
	 */
	public function register_rest_routes(): void {
		register_rest_route(
			'zabure-paywall/v1',
			'/webhook',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'handle_webhook' ],
				'permission_callback' => '__return_true', // Server-to-server; auth is HMAC.
			]
		);
	}

	/**
	 * Handle an incoming Zabure webhook.
	 *
	 * Security steps are performed strictly in order:
	 * 1. Read raw body (required for HMAC computation).
	 * 2. Verify HMAC-SHA256 signature.
	 * 3. Parse payload.
	 * 4. Deduplicate by transactionId.
	 * 5. Match session via Strategy A or B.
	 * 6. Grant access.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response Always returns HTTP 200 after the signature check passes (per Zabure webhook expectations).
	 */
	public function handle_webhook( WP_REST_Request $request ): WP_REST_Response {
		// --- Step 1: Read raw body BEFORE any parsing ---
		$raw_body = $request->get_body();

		// --- Step 2: Read and verify HMAC signature ---
		$signature = (string) $request->get_header( 'x_webhook_signature' );
		$secret    = (string) get_option( 'zabure_webhook_secret', '' );

		if ( empty( $secret ) ) {
			$this->log_error( 'Webhook received but zabure_webhook_secret is not configured.' );

			return new WP_REST_Response(
				[ 'error' => 'Webhook secret not configured.' ],
				500
			);
		}

		$expected_signature = hash_hmac( 'sha256', $raw_body, $secret );

		// Use hash_equals() to prevent timing attacks.
		if ( ! hash_equals( $expected_signature, $signature ) ) {
			$this->log_error(
				sprintf(
					'Webhook signature mismatch. Expected: %s | Received: %s | IP: %s',
					$expected_signature,
					$signature,
					sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? 'unknown' )
				)
			);

			return new WP_REST_Response( [ 'error' => 'Invalid signature.' ], 401 );
		}

		// --- Step 3: Parse payload ---
		$payload = json_decode( $raw_body, true );

		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $payload ) ) {
			$this->log_error( 'Webhook received invalid JSON payload.' );

			return new WP_REST_Response( [ 'received' => true, 'action' => 'invalid_json' ], 200 );
		}

		// --- Step 4: Ignore non-success events ---
		$event = sanitize_text_field( $payload['event'] ?? '' );

		if ( 'transaction.collect.success' !== $event ) {
			return new WP_REST_Response( [ 'received' => true, 'action' => 'ignored' ], 200 );
		}

		$status = sanitize_text_field( $payload['status'] ?? '' );

		if ( 'SUCCESS' !== $status ) {
			$this->log_error(
				sprintf(
					'Webhook transaction.collect.success event received but status is "%s". Payload: %s',
					$status,
					wp_json_encode( $payload )
				)
			);

			return new WP_REST_Response( [ 'received' => true, 'action' => 'non_success_status' ], 200 );
		}

		// --- Step 5: Deduplication check ---
		$transaction_id = sanitize_text_field( $payload['transactionId'] ?? '' );

		if ( $transaction_id && Zabure_Database::transaction_id_exists( $transaction_id ) ) {
			return new WP_REST_Response( [ 'received' => true, 'action' => 'duplicate' ], 200 );
		}

		// --- Extract payment details ---
		$amount   = (int) ( $payload['amount'] ?? 0 );
		$currency = strtoupper( sanitize_text_field( $payload['currency'] ?? '' ) );
		$phone    = sanitize_text_field( $payload['phoneNumber'] ?? '' );
		$phone    = preg_replace( '/[^0-9]/', '', $phone );

		$matched_session = null;

		// --- Step 6: Match Strategy A — phone number (primary) ---
		// Zabure no longer redirects the user back; webhook is the only completion signal.
		// Phone number is the most reliable identifier since it comes from the payment itself.
		if ( $phone ) {
			$phone_meta_key = (string) get_option( 'zabure_phone_meta_key', 'phone_number' );

			$users = get_users(
				[
					'meta_key'   => $phone_meta_key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value' => $phone,          // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'number'     => 1,
				]
			);

			if ( ! empty( $users ) ) {
				$matched_user    = $users[0];
				$candidate_sessions = Zabure_Database::get_sessions_by_amount_currency_window( $amount, $currency, 30 );

				foreach ( $candidate_sessions as $candidate ) {
					if (
						'pending' === $candidate->status &&
						(int) $candidate->user_id === (int) $matched_user->ID
					) {
						$matched_session = $candidate;
						break;
					}
				}
			}
		}

		// --- Step 7: Match Strategy B — amount + currency window fallback ---
		// Used when phone number is absent or not stored (edge case).
		if ( ! $matched_session ) {
			$candidate_sessions = Zabure_Database::get_sessions_by_amount_currency_window( $amount, $currency, 30 );
			$pending_sessions   = array_filter(
				$candidate_sessions,
				fn( object $s ) => 'pending' === $s->status
			);

			if ( 1 === count( $pending_sessions ) ) {
				$matched_session = reset( $pending_sessions );
			} elseif ( count( $pending_sessions ) > 1 ) {
				$this->log_error(
					sprintf(
						'Multiple pending sessions matched for amount=%d currency=%s with no phone. Granting to most recent.',
						$amount,
						$currency
					)
				);
				$matched_session = reset( $pending_sessions ); // Already sorted DESC by initiated_at.
			}
		}

		// --- Step 8: Grant access or log unmatched webhook ---
		if ( $matched_session ) {
			// Store the transaction ID before granting (dedup for future webhooks).
			Zabure_Database::update_session_status(
				(int) $matched_session->id,
				$matched_session->status, // Don't change status yet; grant_access() will set completed.
				[
					'zabure_transaction_id' => $transaction_id,
					'zabure_external_ref'   => sanitize_text_field( $payload['externalReference'] ?? '' ),
				]
			);

			$access_manager = new Zabure_Access_Manager();
			$access_manager->grant_access(
				(int) $matched_session->user_id,
				(int) $matched_session->post_id,
				(int) $matched_session->id,
				'webhook'
			);

			/**
			 * Fires when the webhook is successfully matched to a session.
			 *
			 * @param object $matched_session The matched session row.
			 * @param array  $payload         The full webhook payload.
			 */
			do_action( 'zabure_webhook_matched', $matched_session, $payload );

		} else {
			// No match found — log the full payload so admin can reconcile manually.
			$this->log_unmatched_webhook( $payload );

			/**
			 * Fires when the webhook cannot be matched to any session.
			 *
			 * @param array $payload The full webhook payload.
			 */
			do_action( 'zabure_webhook_unmatched', $payload );
		}

		/**
		 * Fires for every verified webhook received, regardless of match outcome.
		 *
		 * @param array $payload The full webhook payload.
		 */
		do_action( 'zabure_webhook_received', $payload );

		return new WP_REST_Response( [ 'received' => true ], 200 );
	}

	/**
	 * Log an error message to the WordPress error log.
	 *
	 * @param string $message The message to log.
	 * @return void
	 */
	private function log_error( string $message ): void {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( '[Zabure Paywall] ' . $message );
	}

	/**
	 * Log an unmatched webhook payload to the error log and a custom option.
	 *
	 * Unmatched webhooks represent paid transactions with no session —
	 * they must not be silently discarded.
	 *
	 * @param array $payload The full decoded webhook payload.
	 * @return void
	 */
	private function log_unmatched_webhook( array $payload ): void {
		$entry = [
			'timestamp' => current_time( 'mysql' ),
			'payload'   => $payload,
		];

		// Append to a rolling log stored in wp_options (last 50 entries).
		$log = (array) get_option( 'zabure_unmatched_webhooks', [] );
		array_unshift( $log, $entry );
		$log = array_slice( $log, 0, 50 );

		update_option( 'zabure_unmatched_webhooks', $log, false );

		$this->log_error(
			sprintf(
				'Unmatched webhook: transactionId=%s amount=%d currency=%s phone=%s',
				sanitize_text_field( $payload['transactionId'] ?? 'none' ),
				(int) ( $payload['amount'] ?? 0 ),
				sanitize_text_field( $payload['currency'] ?? '' ),
				sanitize_text_field( $payload['phoneNumber'] ?? '' )
			)
		);
	}
}
