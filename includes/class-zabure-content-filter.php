<?php
/**
 * Content filter for Zabure Content Paywall.
 *
 * Hooks into the_content filter to restrict premium post content
 * and render the paywall CTA for users who have not yet paid.
 *
 * @package ZabureContentPaywall
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Zabure_Content_Filter
 */
class Zabure_Content_Filter {

	/**
	 * Constructor — registers hooks.
	 */
	public function __construct() {
		add_filter( 'the_content', [ $this, 'filter_content' ], 20 );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	/**
	 * Filter post content to enforce the paywall.
	 *
	 * Only applies on singular views, inside the loop, on the main query.
	 *
	 * @param string $content The post content.
	 * @return string Filtered content.
	 */
	public function filter_content( string $content ): string {
		if ( ! is_singular() || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		$post_id = get_the_ID();

		if ( ! $post_id ) {
			return $content;
		}

		// Only act on premium posts.
		if ( '1' !== (string) get_post_meta( $post_id, '_zabure_is_premium', true ) ) {
			return $content;
		}

		$preview_n = (int) get_post_meta( $post_id, '_zabure_preview_paragraphs', true );
		if ( $preview_n < 1 ) {
			$preview_n = 2;
		}

		/**
		 * Filter the paragraph count for this post.
		 *
		 * @param int $preview_n The number of paragraphs to show.
		 * @param int $post_id   The post ID.
		 */
		$preview_n = (int) apply_filters( 'zabure_preview_paragraph_count', $preview_n, $post_id );

		// User is not logged in — show preview + login CTA.
		if ( ! is_user_logged_in() ) {
			$preview = $this->truncate_content( $content, $preview_n );
			$cta     = $this->load_template(
				'paywall-cta.php',
				[
					'post_id'    => $post_id,
					'logged_in'  => false,
					'amount'     => (int) get_post_meta( $post_id, '_zabure_amount', true ),
					'currency'   => (string) get_post_meta( $post_id, '_zabure_currency', true ),
					'post_title' => get_the_title( $post_id ),
				]
			);
			return $preview . $cta;
		}

		$user_id        = get_current_user_id();
		$access_manager = new Zabure_Access_Manager();

		// User is logged in and has paid — show full content.
		if ( $access_manager->has_access( $user_id, $post_id ) ) {
			return $content;
		}

		// User is logged in but has not paid — show preview + pay CTA.
		$preview = $this->truncate_content( $content, $preview_n );
		$cta     = $this->load_template(
			'paywall-cta.php',
			[
				'post_id'    => $post_id,
				'logged_in'  => true,
				'amount'     => (int) get_post_meta( $post_id, '_zabure_amount', true ),
				'currency'   => (string) get_post_meta( $post_id, '_zabure_currency', true ),
				'post_title' => get_the_title( $post_id ),
			]
		);

		return $preview . $cta;
	}

	/**
	 * Truncate content to the specified number of paragraphs.
	 *
	 * Applies do_shortcode() first, then splits on </p> tags.
	 *
	 * @param string $content         The full post content.
	 * @param int    $paragraph_count Number of paragraphs to keep.
	 * @return string Truncated HTML.
	 */
	public function truncate_content( string $content, int $paragraph_count ): string {
		$content = do_shortcode( $content );

		// Split, keeping the delimiters (</p> tags) in the results.
		$parts = preg_split( '/(<\/p>)/i', $content, -1, PREG_SPLIT_DELIM_CAPTURE );

		if ( ! $parts ) {
			return $content;
		}

		$output = '';
		$count  = 0;

		foreach ( $parts as $part ) {
			$output .= $part;

			if ( false !== stripos( $part, '</p>' ) ) {
				$count++;
				if ( $count >= $paragraph_count ) {
					break;
				}
			}
		}

		return $output;
	}

	/**
	 * Load a plugin template file and return its output as a string.
	 *
	 * Looks in the plugin's templates/ directory.
	 *
	 * @param string $template_name The template file name (e.g. 'paywall-cta.php').
	 * @param array  $vars          Variables to extract into the template scope.
	 * @return string Rendered HTML.
	 */
	public function load_template( string $template_name, array $vars = [] ): string {
		$template_path = ZABURE_PAYWALL_PATH . 'templates/' . $template_name;

		if ( ! file_exists( $template_path ) ) {
			return '';
		}

		// Extract variables into local scope for the template.
		if ( ! empty( $vars ) ) {
			// phpcs:ignore WordPress.PHP.DontExtract.extract_extract
			extract( $vars, EXTR_SKIP );
		}

		ob_start();
		include $template_path;
		return ob_get_clean();
	}

	/**
	 * Enqueue paywall CSS and JS on singular premium post pages.
	 *
	 * @return void
	 */
	public function enqueue_assets(): void {
		if ( ! is_singular() ) {
			return;
		}

		$post_id = get_queried_object_id();

		if ( '1' !== (string) get_post_meta( $post_id, '_zabure_is_premium', true ) ) {
			return;
		}

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
				'is_logged_in' => is_user_logged_in() ? '1' : '0',
			]
		);
	}
}
