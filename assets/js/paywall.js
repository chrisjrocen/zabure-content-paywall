/**
 * Zabure Content Paywall — Frontend JavaScript
 *
 * Handles three phases:
 *   Phase 1 — Payment initiation (CTA button → phone modal → initiate API → redirect)
 *   Phase 2 — Status polling (processing page → poll check-status → redirect on completion)
 *   Phase 3 — Admin copy button (post edit screen)
 *
 * Requires: jQuery (loaded by WordPress), zabure_paywall global (via wp_localize_script)
 *
 * @package ZabureContentPaywall
 */

/* global zabure_paywall, jQuery */

( function ( $ ) {
	'use strict';

	// =========================================================================
	// Phase 1 — Payment initiation
	// =========================================================================

	function initPhase1() {
		var $payBtn   = $( '#zabure-pay-btn' );
		var $modal    = $( '#zabure-phone-modal' );
		var $submit   = $( '#zabure-phone-submit' );
		var $cancel   = $( '#zabure-phone-cancel' );
		var $phone    = $( '#zabure-phone-input' );
		var $errBox   = $( '#zabure-phone-error' );

		if ( ! $payBtn.length ) {
			return;
		}

		/**
		 * Show an error inside the phone modal.
		 *
		 * @param {string} msg The error message.
		 */
		function showModalError( msg ) {
			$errBox.text( msg ).show();
		}

		function clearModalError() {
			$errBox.text( '' ).hide();
		}

		/**
		 * Redirect the user to the Zabure payment page.
		 * Opens the same tab (not a popup) to preserve cookie context.
		 *
		 * @param {string} phoneNumber The sanitized phone number.
		 */
		function initiatePayment( phoneNumber ) {
			var postId = $payBtn.data( 'post-id' );

			// Disable button to prevent double-clicks.
			$submit.prop( 'disabled', true ).text( zabure_paywall.initiating_text || 'Redirecting…' );

			$.ajax( {
				url: zabure_paywall.ajax_url + 'zabure-paywall/v1/initiate',
				method: 'POST',
				contentType: 'application/json',
				data: JSON.stringify( {
					post_id: parseInt( postId, 10 ),
					phone_number: phoneNumber
				} ),
				headers: {
					'X-WP-Nonce': zabure_paywall.nonce
				},
				success: function ( response ) {
					if ( response && response.payment_url ) {
						window.location.href = response.payment_url;
					} else {
						$submit.prop( 'disabled', false ).text( zabure_paywall.continue_text || 'Continue to Payment →' );
						showModalError( response.error || 'An error occurred. Please try again.' );
					}
				},
				error: function ( xhr ) {
					$submit.prop( 'disabled', false ).text( zabure_paywall.continue_text || 'Continue to Payment →' );
					var msg = 'An error occurred. Please try again.';
					try {
						var body = JSON.parse( xhr.responseText );
						if ( body && body.message ) {
							msg = body.message;
						} else if ( body && body.error ) {
							msg = body.error;
						}
					} catch ( e ) { /* ignore */ }
					showModalError( msg );
				}
			} );
		}

		// Open the phone modal on Pay button click.
		$payBtn.on( 'click', function () {
			clearModalError();
			$modal.show();
			$phone.trigger( 'focus' );
		} );

		// Submit phone form.
		$submit.on( 'click', function () {
			clearModalError();

			var rawPhone = $phone.val().trim();
			var digits   = rawPhone.replace( /[^0-9]/g, '' );

			if ( ! digits || digits.length < 9 || digits.length > 15 ) {
				showModalError( 'Please enter a valid phone number (digits only, 9–15 characters).' );
				$phone.trigger( 'focus' );
				return;
			}

			initiatePayment( digits );
		} );

		// Allow Enter key in phone field.
		$phone.on( 'keydown', function ( e ) {
			if ( 13 === e.which ) {
				e.preventDefault();
				$submit.trigger( 'click' );
			}
		} );

		// Cancel / close modal.
		$cancel.on( 'click', function () {
			$modal.hide();
			clearModalError();
		} );

		// Close modal on overlay click.
		$modal.on( 'click', function ( e ) {
			if ( $( e.target ).is( $modal ) ) {
				$modal.hide();
				clearModalError();
			}
		} );

		// Close modal on Escape key.
		$( document ).on( 'keydown', function ( e ) {
			if ( 27 === e.which && $modal.is( ':visible' ) ) {
				$modal.hide();
				clearModalError();
			}
		} );
	}

	// =========================================================================
	// Phase 2 — Status polling (payment-processing.php page)
	// =========================================================================

	function initPhase2() {
		if ( ! $( 'body' ).hasClass( 'zabure-processing-page' ) ) {
			return;
		}

		var $container   = $( '#zabure-processing' );
		if ( ! $container.length ) {
			return;
		}

		var sessionToken  = $container.data( 'session-token' );
		if ( ! sessionToken ) {
			return;
		}

		var pollInterval  = 3000;        // 3 seconds between polls.
		var maxPollTime   = 10 * 60 * 1000; // 10 minutes timeout.
		var startTime     = Date.now();
		var pollTimer     = null;
		var stopped       = false;

		function showState( state ) {
			$( '#zabure-state-pending, #zabure-state-success, #zabure-state-error, #zabure-state-timeout' ).hide();
			$( '#zabure-state-' + state ).show();
		}

		function stopPolling() {
			stopped = true;
			if ( pollTimer ) {
				clearTimeout( pollTimer );
				pollTimer = null;
			}
		}

		function poll() {
			if ( stopped ) {
				return;
			}

			// Timeout check.
			if ( Date.now() - startTime >= maxPollTime ) {
				stopPolling();
				showState( 'timeout' );
				return;
			}

			$.ajax( {
				url: zabure_paywall.ajax_url + 'zabure-paywall/v1/check-status',
				method: 'GET',
				data: { token: sessionToken },
				headers: {
					'X-WP-Nonce': zabure_paywall.nonce
				},
				success: function ( response ) {
					if ( ! response ) {
						scheduleNextPoll();
						return;
					}

					if ( 'completed' === response.status ) {
						stopPolling();
						showState( 'success' );
						setTimeout( function () {
							window.location.href = response.redirect_url;
						}, 1000 );
						return;
					}

					if ( 'failed' === response.status ) {
						stopPolling();
						$( '#zabure-error-message' ).text( response.message || 'Payment was not completed.' );
						showState( 'error' );
						return;
					}

					if ( 'expired' === response.status ) {
						stopPolling();
						$( '#zabure-error-message' ).text( response.message || 'Session expired.' );
						showState( 'error' );
						return;
					}

					// status === 'pending' — keep polling.
					scheduleNextPoll();
				},
				error: function () {
					// Network error — keep trying unless timed out.
					scheduleNextPoll();
				}
			} );
		}

		function scheduleNextPoll() {
			if ( ! stopped ) {
				pollTimer = setTimeout( poll, pollInterval );
			}
		}

		// Start polling.
		poll();
	}

	// =========================================================================
	// Phase 3 — Admin copy button
	// =========================================================================

	function initPhase3() {
		var $copyBtn = $( '#zabure-copy-link' );

		if ( ! $copyBtn.length ) {
			return;
		}

		$copyBtn.on( 'click', function () {
			var url = $copyBtn.data( 'url' );

			if ( navigator.clipboard && navigator.clipboard.writeText ) {
				navigator.clipboard.writeText( url ).then( function () {
					var original = $copyBtn.text();
					$copyBtn.text( 'Copied!' );
					setTimeout( function () {
						$copyBtn.text( original );
					}, 2000 );
				} ).catch( function () {
					window.prompt( 'Copy this URL:', url );
				} );
			} else {
				window.prompt( 'Copy this URL:', url );
			}
		} );
	}

	// =========================================================================
	// Init
	// =========================================================================

	$( document ).ready( function () {
		initPhase1();
		initPhase2();
		initPhase3();
	} );

}( jQuery ) );
