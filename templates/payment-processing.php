<?php
/**
 * Payment processing / polling template.
 *
 * Rendered by Zabure_Callback_Handler::handle_callback() after the user returns
 * from the Zabure payment page. Polls the status endpoint via JavaScript.
 *
 * Variables available:
 *   int    $post_id       The post ID being purchased.
 *   string $session_token The 64-char hex session token.
 *   string $post_title    The post title.
 *
 * @package ZabureContentPaywall
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Output a full HTML page using get_header()/get_footer() so the theme
// wraps it, but we add a body class via the body_class filter.
add_filter( 'body_class', function ( array $classes ): array {
	$classes[] = 'zabure-processing-page';
	return $classes;
} );
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?php esc_html_e( 'Confirming Payment…', 'zabure-content-paywall' ); ?> — <?php bloginfo( 'name' ); ?></title>
	<?php wp_head(); ?>
</head>
<body class="zabure-processing-page">

<div class="zabure-processing-wrap">

	<div id="zabure-processing" data-session-token="<?php echo esc_attr( $session_token ); ?>" data-post-id="<?php echo esc_attr( $post_id ); ?>">

		<!-- Default / pending state -->
		<div id="zabure-state-pending">
			<div class="zabure-spinner" aria-hidden="true"></div>
			<h1 class="zabure-processing-heading">
				<?php esc_html_e( 'Confirming your payment…', 'zabure-content-paywall' ); ?>
			</h1>
			<p class="zabure-processing-subtext">
				<?php esc_html_e( 'Please wait while we verify your payment. Do not close this page.', 'zabure-content-paywall' ); ?>
			</p>
		</div>

		<!-- Success state (hidden, shown by JS) -->
		<div id="zabure-state-success" style="display:none;" aria-live="polite">
			<div class="zabure-success-icon" aria-hidden="true">✅</div>
			<h1 class="zabure-processing-heading">
				<?php esc_html_e( 'Payment confirmed! Redirecting you now…', 'zabure-content-paywall' ); ?>
			</h1>
		</div>

		<!-- Error state (hidden, shown by JS) -->
		<div id="zabure-state-error" style="display:none;" aria-live="assertive">
			<div class="zabure-error-icon" aria-hidden="true">❌</div>
			<h1 class="zabure-processing-heading zabure-error-heading">
				<?php esc_html_e( 'Payment Not Confirmed', 'zabure-content-paywall' ); ?>
			</h1>
			<p id="zabure-error-message" class="zabure-processing-subtext"></p>
			<a href="<?php echo esc_url( get_permalink( $post_id ) ); ?>" class="zabure-btn zabure-btn-primary zabure-try-again">
				<?php esc_html_e( 'Try Again', 'zabure-content-paywall' ); ?>
			</a>
		</div>

		<!-- Timeout state (hidden, shown by JS after 10 minutes) -->
		<div id="zabure-state-timeout" style="display:none;" aria-live="assertive">
			<p class="zabure-processing-subtext">
				<?php esc_html_e( 'Taking too long? Contact support if you believe payment was made.', 'zabure-content-paywall' ); ?>
			</p>
			<a href="<?php echo esc_url( get_permalink( $post_id ) ); ?>" class="zabure-btn-link">
				<?php esc_html_e( 'Return to article', 'zabure-content-paywall' ); ?>
			</a>
		</div>

	</div>

</div>

<?php wp_footer(); ?>
</body>
</html>
