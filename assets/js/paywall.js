/**
 * Zabure Content Paywall — Frontend JavaScript
 *
 * Phase 1 — Payment initiation:
 *   CTA button → phone modal → POST /initiate → open Zabure in new tab → show inline spinner
 *
 * Phase 2 — Inline status polling:
 *   Poll /check-status every 3 s; on completed redirect to full post.
 *
 * Phase 3 — Admin copy button (post edit screen).
 *
 * Requires: jQuery (loaded by WordPress), zabure_paywall global (wp_localize_script).
 *
 * @package ZabureContentPaywall
 */

/* global zabure_paywall, jQuery */

( function ( $ ) {
	'use strict';

	// =========================================================================
	// Shared polling state (set by Phase 1, used by Phase 2)
	// =========================================================================

	var pollTimer    = null;
	var pollStopped  = false;
	var pollStart    = 0;
	var MAX_POLL_MS  = 10 * 60 * 1000; // 10 minutes
	var POLL_INTERVAL = 3000;           // 3 seconds

	// =========================================================================
	// Phase 1 — Payment initiation
	// =========================================================================

	function initPhase1() {
		var $payBtn  = $( '#zabure-pay-btn' );
		var $modal   = $( '#zabure-phone-modal' );
		var $submit  = $( '#zabure-phone-submit' );
		var $cancel  = $( '#zabure-phone-cancel' );
		var $phone   = $( '#zabure-phone-input' );
		var $errBox  = $( '#zabure-phone-error' );

		if ( ! $payBtn.length ) {
			return;
		}

		function showModalError( msg ) {
			$errBox.text( msg ).show();
		}

		function clearModalError() {
			$errBox.text( '' ).hide();
		}

		/**
		 * Call the initiate endpoint, open Zabure in a new tab, show inline polling UI.
		 *
		 * @param {string} phoneNumber Digits-only phone number.
		 */
		function initiatePayment( phoneNumber ) {
			var postId = $payBtn.data( 'post-id' );

			$submit.prop( 'disabled', true ).text( 'Redirecting…' );

			$.ajax( {
				url: zabure_paywall.ajax_url + 'zabure-paywall/v1/initiate',
				method: 'POST',
				contentType: 'application/json',
				data: JSON.stringify( {
					post_id: parseInt( postId, 10 ),
					phone_number: phoneNumber
				} ),
				headers: { 'X-WP-Nonce': zabure_paywall.nonce },

				success: function ( response ) {
					if ( ! response || ! response.payment_url ) {
						$submit.prop( 'disabled', false ).text( 'Continue to Payment →' );
						showModalError( response.error || 'An error occurred. Please try again.' );
						return;
					}

					// Open Zabure payment page in a new tab.
					window.open( response.payment_url, '_blank' );

					// Swap to inline processing UI.
					$modal.hide();
					$( '#zabure-paywall-cta' ).hide();
					$( '#zabure-inline-processing' ).show();
					showState( 'pending' );

					// Begin polling.
					pollStopped = false;
					pollStart   = Date.now();
					schedulePoll( response.session_token );
				},

				error: function ( xhr ) {
					$submit.prop( 'disabled', false ).text( 'Continue to Payment →' );
					var msg = 'An error occurred. Please try again.';
					try {
						var body = JSON.parse( xhr.responseText );
						if ( body && ( body.message || body.error ) ) {
							msg = body.message || body.error;
						}
					} catch ( e ) { /* ignore */ }
					showModalError( msg );
				}
			} );
		}

		// Open modal on Pay button click.
		$payBtn.on( 'click', function () {
			clearModalError();
			$modal.show();
			$phone.trigger( 'focus' );
		} );

		// Submit phone.
		$submit.on( 'click', function () {
			clearModalError();
			var digits = $phone.val().trim().replace( /[^0-9]/g, '' );
			if ( ! digits || digits.length < 9 || digits.length > 15 ) {
				showModalError( 'Please enter a valid phone number (digits only, 9–15 characters).' );
				$phone.trigger( 'focus' );
				return;
			}
			initiatePayment( digits );
		} );

		// Enter key in phone field.
		$phone.on( 'keydown', function ( e ) {
			if ( 13 === e.which ) { e.preventDefault(); $submit.trigger( 'click' ); }
		} );

		// Cancel / close modal.
		$cancel.on( 'click', function () { $modal.hide(); clearModalError(); } );
		$modal.on( 'click', function ( e ) {
			if ( $( e.target ).is( $modal ) ) { $modal.hide(); clearModalError(); }
		} );
		$( document ).on( 'keydown', function ( e ) {
			if ( 27 === e.which && $modal.is( ':visible' ) ) { $modal.hide(); clearModalError(); }
		} );
	}

	// =========================================================================
	// Phase 2 — Inline status polling
	// =========================================================================

	/**
	 * Show one of the inline processing states.
	 *
	 * @param {string} state  'pending' | 'success' | 'error' | 'timeout'
	 * @param {string} [msg]  Optional message for the error state.
	 */
	function showState( state, msg ) {
		$( '#zabure-inline-pending, #zabure-inline-success, #zabure-inline-error, #zabure-inline-timeout' ).hide();
		$( '#zabure-inline-' + state ).show();
		if ( 'error' === state && msg ) {
			$( '#zabure-inline-error-msg' ).text( msg );
		}
	}

	function stopPolling() {
		pollStopped = true;
		if ( pollTimer ) { clearTimeout( pollTimer ); pollTimer = null; }
	}

	function schedulePoll( token ) {
		if ( ! pollStopped ) {
			pollTimer = setTimeout( function () { doPoll( token ); }, POLL_INTERVAL );
		}
	}

	function doPoll( token ) {
		if ( pollStopped ) { return; }

		if ( Date.now() - pollStart >= MAX_POLL_MS ) {
			stopPolling();
			showState( 'timeout' );
			return;
		}

		$.ajax( {
			url: zabure_paywall.ajax_url + 'zabure-paywall/v1/check-status',
			method: 'GET',
			data: { token: token },
			headers: { 'X-WP-Nonce': zabure_paywall.nonce },

			success: function ( response ) {
				if ( ! response ) { schedulePoll( token ); return; }

				if ( 'completed' === response.status ) {
					stopPolling();
					showState( 'success' );
					setTimeout( function () { window.location.href = response.redirect_url; }, 1000 );
					return;
				}

				if ( 'failed' === response.status ) {
					stopPolling();
					showState( 'error', response.message || 'Payment was not completed.' );
					return;
				}

				if ( 'expired' === response.status ) {
					stopPolling();
					showState( 'error', response.message || 'Session expired.' );
					return;
				}

				// 'pending' — keep polling.
				schedulePoll( token );
			},

			error: function () {
				// Network hiccup — keep trying.
				schedulePoll( token );
			}
		} );
	}

	// =========================================================================
	// Phase 3 — Admin copy button
	// =========================================================================

	function initPhase3() {
		var $copyBtn = $( '#zabure-copy-link' );
		if ( ! $copyBtn.length ) { return; }

		$copyBtn.on( 'click', function () {
			var url = $copyBtn.data( 'url' );
			if ( navigator.clipboard && navigator.clipboard.writeText ) {
				navigator.clipboard.writeText( url ).then( function () {
					var orig = $copyBtn.text();
					$copyBtn.text( 'Copied!' );
					setTimeout( function () { $copyBtn.text( orig ); }, 2000 );
				} ).catch( function () { window.prompt( 'Copy this URL:', url ); } );
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
		// Phase 2 is triggered by Phase 1 (schedulePoll called on initiate success).
		initPhase3();
	} );

}( jQuery ) );
