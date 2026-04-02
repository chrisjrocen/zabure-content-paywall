<?php

/**
 * Paywall call-to-action template.
 *
 * Variables available (extracted by Zabure_Content_Filter::load_template()):
 *   int    $post_id    The post ID.
 *   bool   $logged_in  True if the current user is logged in.
 *   int    $amount     Price in smallest currency unit.
 *   string $currency   Currency code (UGX, KES, TZS, USD).
 *   string $post_title The post title.
 *
 * @package ZabureContentPaywall
 */

if (! defined('ABSPATH')) {
	exit;
}

// Format amount for display (divide by 100 for display — e.g. 5000 → 50.00).
$display_amount = $amount;

// Get existing phone for the logged-in user (pre-fills the phone prompt).
$existing_phone = '';
if ($logged_in) {
	$phone_meta_key = (string) get_option('zabure_phone_meta_key', 'phone_number');
	$existing_phone = (string) get_user_meta(get_current_user_id(), $phone_meta_key, true);
}
?>

<?php
/**
 * Inline processing block — shown after the user clicks "Unlock" and Zabure opens in a new tab.
 * Hidden by default; JavaScript swaps it in and hides #zabure-paywall-cta.
 */
?>
<div id="zabure-inline-processing" class="zabure-paywall-wrap" style="display:none;" aria-live="polite">
	<div class="zabure-paywall-cta">

		<div id="zabure-inline-pending">
			<div class="zabure-spinner" aria-hidden="true"></div>
			<p class="zabure-processing-heading">
				<?php esc_html_e('Complete your payment in the new tab.', 'zabure-content-paywall'); ?></p>
			<p class="zabure-processing-subtext">
				<?php esc_html_e('This page will update automatically once payment is confirmed.', 'zabure-content-paywall'); ?>
			</p>
		</div>

		<div id="zabure-inline-success" style="display:none;">
			<div class="zabure-success-icon" aria-hidden="true">✅</div>
			<p class="zabure-processing-heading">
				<?php esc_html_e('Payment confirmed! Redirecting you now…', 'zabure-content-paywall'); ?></p>
		</div>

		<div id="zabure-inline-error" style="display:none;">
			<div class="zabure-error-icon" aria-hidden="true">❌</div>
			<p class="zabure-processing-heading" style="color:var(--zabure-error);">
				<?php esc_html_e('Payment Not Confirmed', 'zabure-content-paywall'); ?></p>
			<p id="zabure-inline-error-msg" class="zabure-processing-subtext"></p>
			<a href="<?php echo esc_url(get_permalink($post_id)); ?>" class="zabure-btn zabure-btn-primary">
				<?php esc_html_e('Try Again', 'zabure-content-paywall'); ?>
			</a>
		</div>

		<div id="zabure-inline-timeout" style="display:none;">
			<p class="zabure-processing-subtext">
				<?php esc_html_e('Taking too long? Contact support if you believe payment was made.', 'zabure-content-paywall'); ?>
			</p>
			<a href="<?php echo esc_url(get_permalink($post_id)); ?>"
				class="zabure-btn-link"><?php esc_html_e('Refresh page', 'zabure-content-paywall'); ?></a>
		</div>

	</div>
</div>

<?php
/**
 * Fires just before the paywall CTA HTML is output.
 *
 * @param int $post_id The post ID.
 */
do_action('zabure_before_paywall_cta', $post_id);
?>

<?php
// Build the CTA HTML so it can be passed through the filter.
ob_start();
?>
<div class="zabure-paywall-blur" aria-hidden="true"></div>

<div class="zabure-paywall-wrap" id="zabure-paywall-cta">
	<div class="zabure-paywall-cta">

		<div class="zabure-paywall-icon" aria-hidden="true">
			<svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none"
				stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
				<rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
				<path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
			</svg>
		</div>

		<h2 class="zabure-paywall-heading">
			<?php esc_html_e('Continue Reading', 'zabure-content-paywall'); ?>
		</h2>

		<p class="zabure-paywall-subtext">
			<?php
			echo wp_kses_post(
				sprintf(
					/* translators: 1: formatted amount, 2: currency code */
					__('Unlock the full article for <strong>%1$s %2$s</strong>', 'zabure-content-paywall'),
					esc_html($display_amount),
					esc_html($currency)
				)
			);
			?>
		</p>

		<?php if (! $logged_in) : ?>

			<a href="<?php echo esc_url(wp_login_url(get_permalink($post_id))); ?>" class="zabure-btn zabure-btn-primary">
				<?php esc_html_e('Login to Continue', 'zabure-content-paywall'); ?>
			</a>

		<?php else : ?>

			<button type="button" id="zabure-pay-btn" class="zabure-btn zabure-btn-primary"
				data-post-id="<?php echo esc_attr($post_id); ?>">
				<?php esc_html_e('Pay for Article (April to June) →', 'zabure-content-paywall'); ?>
			</button>

			<p class="zabure-paywall-note">
				<?php esc_html_e('One-time payment. Permanent access. No subscription.', 'zabure-content-paywall'); ?>
			</p>

			<?php
			// Phone prompt modal (inline, shown/hidden by JS).
			include ZABURE_PAYWALL_PATH . 'templates/phone-prompt.php';
			?>

		<?php endif; ?>

	</div>
</div>
<?php
$cta_html = ob_get_clean();

/**
 * Filter the full paywall CTA HTML block.
 *
 * @param string $cta_html The complete CTA HTML.
 * @param int    $post_id  The post ID.
 */
echo apply_filters('zabure_paywall_cta_html', $cta_html, $post_id); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped