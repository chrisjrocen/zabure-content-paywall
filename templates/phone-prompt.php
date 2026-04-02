<?php
/**
 * Phone prompt modal template.
 *
 * Variables available (from paywall-cta.php scope or extracted directly):
 *   int    $post_id        The post ID.
 *   string $existing_phone The user's stored phone number (may be empty).
 *
 * @package ZabureContentPaywall
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

<div id="zabure-phone-modal" class="zabure-phone-modal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="zabure-modal-title">

	<div class="zabure-phone-modal-card">

		<h3 id="zabure-modal-title" class="zabure-modal-title">
			<?php esc_html_e( 'Confirm Your Phone Number', 'zabure-content-paywall' ); ?>
		</h3>

		<p class="zabure-modal-description">
			<?php esc_html_e( 'We use your phone number to confirm payment in case you get disconnected.', 'zabure-content-paywall' ); ?>
		</p>

		<div id="zabure-phone-error" class="zabure-error-message" style="display:none;" role="alert"></div>

		<div class="zabure-phone-field">
			<label for="zabure-phone-input"><?php esc_html_e( 'Phone number', 'zabure-content-paywall' ); ?></label>
			<input
				type="tel"
				id="zabure-phone-input"
				name="zabure_phone"
				placeholder="+256700000000"
				value="<?php echo esc_attr( $existing_phone ); ?>"
				autocomplete="tel"
				inputmode="tel">
		</div>

		<div class="zabure-modal-actions">
			<button type="button" id="zabure-phone-submit" class="zabure-btn zabure-btn-primary">
				<?php esc_html_e( 'Continue to Payment →', 'zabure-content-paywall' ); ?>
			</button>
			<button type="button" id="zabure-phone-cancel" class="zabure-btn-link">
				<?php esc_html_e( 'Cancel', 'zabure-content-paywall' ); ?>
			</button>
		</div>

	</div>

</div>
