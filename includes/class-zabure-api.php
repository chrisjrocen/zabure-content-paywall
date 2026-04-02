<?php

/**
 * Zabure API client.
 *
 * Wraps all communication with the Zabure REST API.
 * Uses wp_remote_*() functions exclusively — never cURL directly.
 *
 * @package ZabureContentPaywall
 */

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Class Zabure_API
 */
class Zabure_API
{

	/**
	 * Zabure API key read from plugin settings.
	 *
	 * @var string
	 */
	private string $api_key;

	/**
	 * Current environment: 'sandbox' or 'live'.
	 *
	 * @var string
	 */
	private string $environment;

	/**
	 * Allowed payment methods shown on the Zabure payment page.
	 *
	 * @var array
	 */
	private array $allowed_methods;

	/**
	 * Business name shown on the Zabure payment page.
	 *
	 * @var string
	 */
	private string $business_name;

	/**
	 * Brand primary colour for the Zabure payment page (hex).
	 *
	 * @var string
	 */
	private string $primary_color;

	/**
	 * Sandbox base URL.
	 */
	const SANDBOX_URL = 'https://sandbox.zabure.com';

	/**
	 * Live base URL.
	 */
	const LIVE_URL = 'https://pay.zabure.com';

	/**
	 * Constructor — reads credentials and branding options from plugin settings.
	 */
	public function __construct()
	{
		$this->api_key         = (string) get_option( 'zabure_api_key', '' );
		$this->environment     = (string) get_option( 'zabure_environment', 'sandbox' );
		$this->allowed_methods = (array) get_option( 'zabure_allowed_methods', [ 'MTN_MOMO', 'AIRTEL_MONEY' ] );
		$this->business_name   = (string) get_option( 'zabure_business_name', get_bloginfo( 'name' ) );
		$this->primary_color   = (string) get_option( 'zabure_primary_color', '#4f46e5' );
	}

	/**
	 * Get the Zabure API base URL for the current environment.
	 *
	 * @return string
	 */
	public function get_base_url(): string
	{
		return 'live' === $this->environment ? self::LIVE_URL : self::SANDBOX_URL;
	}

	/**
	 * Create a Zabure payment link for a post.
	 *
	 * The link is created once at post save time and reused for all users.
	 * Payment confirmation is handled exclusively via webhook (no redirectUrl).
	 *
	 * @param int    $post_id     The WordPress post ID.
	 * @param int    $amount      Amount in smallest currency unit (e.g. 5000 = UGX 50).
	 * @param string $currency    Currency code: UGX, KES, TZS, USD.
	 * @param string $description Human-readable payment description shown as the link title.
	 * @return array|WP_Error Full decoded response array on success, WP_Error on failure.
	 */
	public function create_payment_link( int $amount, string $currency, string $description ): array|WP_Error {
		$endpoint = $this->get_base_url() . '/api/v1/payment-links';

		$body = wp_json_encode(
			[
				'title'          => $description,
				'currency'       => strtoupper( $currency ),
				'amount'         => $amount,
				'allowedMethods' => $this->allowed_methods,
				'businessName'   => $this->business_name,
				'primaryColor'   => $this->primary_color,
				'isMultiUse'     => true,
			]
		);

		$response = wp_remote_post(
			$endpoint,
			[
				'headers' => $this->get_headers(),
				'body'    => $body,
				'timeout' => 15,
			]
		);

		return $this->parse_response($response, 'create_payment_link');
	}

	/**
	 * Retrieve a specific payment link by its ID.
	 *
	 * @param string $link_id The Zabure payment link ID.
	 * @return array|WP_Error
	 */
	public function get_payment_link(string $link_id): array|WP_Error
	{
		$endpoint = $this->get_base_url() . '/api/v1/payment-links/' . rawurlencode($link_id);

		$response = wp_remote_get(
			$endpoint,
			[
				'headers' => $this->get_headers(),
				'timeout' => 15,
			]
		);

		return $this->parse_response($response, 'get_payment_link');
	}

	/**
	 * Delete a payment link by its ID.
	 *
	 * @param string $link_id The Zabure payment link ID.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public function delete_payment_link(string $link_id): bool|WP_Error
	{
		$endpoint = $this->get_base_url() . '/api/v1/payment-links/' . rawurlencode($link_id);

		$response = wp_remote_request(
			$endpoint,
			[
				'method'  => 'DELETE',
				'headers' => $this->get_headers(),
				'timeout' => 15,
			]
		);

		if (is_wp_error($response)) {
			return new WP_Error(
				'zabure_http_error',
				sprintf(
					/* translators: %s: error message */
					__('Zabure API request failed: %s', 'zabure-content-paywall'),
					$response->get_error_message()
				)
			);
		}

		$code = wp_remote_retrieve_response_code($response);

		if ($code >= 200 && $code < 300) {
			return true;
		}

		return new WP_Error(
			'zabure_delete_failed',
			sprintf(
				/* translators: %d: HTTP status code */
				__('Zabure delete_payment_link returned HTTP %d.', 'zabure-content-paywall'),
				$code
			)
		);
	}

	/**
	 * Build the common HTTP headers for all Zabure API requests.
	 *
	 * @return array
	 */
	private function get_headers(): array
	{
		return [
			'Content-Type' => 'application/json',
			'Accept'       => 'application/json',
			'X-API-Key'    => $this->api_key,
		];
	}

	/**
	 * Parse a wp_remote_*() response into an array or WP_Error.
	 *
	 * @param array|WP_Error $response   The raw response from wp_remote_*().
	 * @param string         $method_tag Short label for error messages.
	 * @return array|WP_Error
	 */
	private function parse_response(array|WP_Error $response, string $method_tag): array|WP_Error
	{
		if (is_wp_error($response)) {
			return new WP_Error(
				'zabure_http_error',
				sprintf(
					/* translators: 1: method name, 2: error message */
					__('Zabure API %1$s request failed: %2$s', 'zabure-content-paywall'),
					$method_tag,
					$response->get_error_message()
				)
			);
		}

		$code = wp_remote_retrieve_response_code($response);
		$body = wp_remote_retrieve_body($response);

		if (empty($body)) {
			return new WP_Error(
				'zabure_empty_response',
				sprintf(
					/* translators: 1: method name, 2: HTTP status code */
					__('Zabure API %1$s returned an empty response (HTTP %2$d).', 'zabure-content-paywall'),
					$method_tag,
					$code
				)
			);
		}

		$data = json_decode($body, true);

		if (JSON_ERROR_NONE !== json_last_error()) {
			return new WP_Error(
				'zabure_json_error',
				sprintf(
					/* translators: 1: method name, 2: json_last_error_msg */
					__('Zabure API %1$s returned invalid JSON: %2$s', 'zabure-content-paywall'),
					$method_tag,
					json_last_error_msg()
				)
			);
		}

		if ($code < 200 || $code >= 300) {
			$api_message = $data['message'] ?? $data['error'] ?? __('Unknown error', 'zabure-content-paywall');

			return new WP_Error(
				'zabure_api_error',
				sprintf(
					/* translators: 1: method name, 2: HTTP status code, 3: API error message */
					__('Zabure API %1$s failed (HTTP %2$d): %3$s', 'zabure-content-paywall'),
					$method_tag,
					$code,
					$api_message
				),
				['status' => $code, 'response' => $data]
			);
		}

		return (array) $data;
	}
}
