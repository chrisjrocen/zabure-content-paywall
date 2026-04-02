# Claude Code Prompt — Zabure Content Paywall WordPress Plugin

Copy everything below this line and paste it into Claude Code as your opening message.

---

## PROMPT START

You are a Senior WordPress developer with 10+ years of experience building secure, production-grade WordPress plugins. Your task is to build a complete WordPress plugin from scratch called **Zabure Content Paywall**.

A full architecture reference document exists at the path I will give you. Read it first before writing any code. Then build the plugin exactly as specified, following the recommended development sequence in Section 17 of that document.

**Architecture reference file:** `zabure-content-paywall-architecture.md` (in the current working directory)

---

### What This Plugin Does

This plugin restricts premium WordPress post content behind a payment wall powered by the Zabure payment gateway. Users must be logged in and pay a one-time fee per post to unlock full content permanently. There is no expiry on access once it is granted.

The plugin has **no dependency on WooCommerce or any other third-party plugin.**

---

### Environment

- PHP: 8.2.27
- WordPress: 6.9.4
- Payment gateway: Zabure (REST API)
- Zabure sandbox base URL: `https://sandbox.zabure.com`
- Zabure live base URL: `https://pay.zabure.com`
- Zabure API authentication: `X-API-Key` header
- Zabure webhook signature: HMAC-SHA256, sent in `x-webhook-signature` header

---

### Coding Standards & Style Rules

Follow these rules strictly throughout every file:

1. **PHP 8.2 features are allowed:** typed properties, match expressions, readonly properties, named arguments, fibers are allowed but keep it practical.
2. **WordPress Coding Standards (WPCS):** tabs for indentation, Yoda conditions, proper sanitisation and escaping everywhere.
3. **Security-first:** every `$_GET`, `$_POST`, `$_COOKIE` value must be sanitised before use. All database queries use `$wpdb->prepare()`. All output uses the appropriate escape function (`esc_html`, `esc_attr`, `esc_url`, `wp_kses_post`).
4. **No inline SQL strings** outside of `class-zabure-database.php`.
5. **OOP only.** No procedural code outside the main plugin file bootstrap. Each class lives in its own file.
6. **PSR-4-style autoloading** via a simple `spl_autoload_register` in the main plugin file.
7. **All user-facing strings are wrapped in `__()` or `esc_html__()`** with text domain `zabure-content-paywall`.
8. **No direct file access:** every PHP file starts with `if ( ! defined( 'ABSPATH' ) ) { exit; }`.
9. **DocBlocks** on every class and every public method.
10. **No `die()` or `exit()` in webhook handlers** — always return a proper WP_REST_Response.

---

### Plugin File & Folder Structure

Build exactly this structure. Do not add or remove files without explanation:

```
zabure-content-paywall/
├── zabure-content-paywall.php
├── includes/
│   ├── class-zabure-api.php
│   ├── class-zabure-database.php
│   ├── class-zabure-access-manager.php
│   ├── class-zabure-content-filter.php
│   ├── class-zabure-payment-handler.php
│   ├── class-zabure-callback-handler.php
│   ├── class-zabure-webhook-handler.php
│   └── class-zabure-admin.php
├── assets/
│   ├── css/
│   │   └── paywall.css
│   └── js/
│       └── paywall.js
└── templates/
    ├── paywall-cta.php
    ├── phone-prompt.php
    └── payment-processing.php
```

---

### Build Sequence

Build the plugin **in this exact order.** Complete each step fully before moving to the next. After each step, confirm the file is complete before proceeding.

**Step 1 — Main plugin file and autoloader**
File: `zabure-content-paywall.php`
- Plugin header comment (Plugin Name, Description, Version: 1.0.0, Requires PHP: 8.2, Requires at least: 6.0, Text Domain: zabure-content-paywall)
- Define constants: `ZABURE_PAYWALL_VERSION`, `ZABURE_PAYWALL_PATH`, `ZABURE_PAYWALL_URL`, `ZABURE_PAYWALL_BASENAME`
- `spl_autoload_register` that maps class names to files in `includes/`
- `register_activation_hook` → calls `Zabure_Database::create_tables()`
- `register_deactivation_hook` → calls `Zabure_Database::flush_rewrite_rules()`
- `plugins_loaded` action hook that instantiates all classes and wires them up

**Step 2 — Database class**
File: `includes/class-zabure-database.php`
- `create_tables()`: Creates `wp_zabure_paywall_sessions` using `dbDelta()`. Full schema is in the architecture doc Section 3.
- `insert_session( array $data ): int|false`
- `get_session_by_token( string $token ): object|null`
- `get_pending_session_by_user_post( int $user_id, int $post_id ): object|null`
- `update_session_status( int $id, string $status, array $extra_data = [] ): bool`
- `get_sessions_by_amount_currency_window( int $amount, string $currency, int $minutes = 30 ): array` — used by webhook Match Strategy A
- `get_all_sessions( array $filters = [] ): array` — for admin logs page
- `expire_old_sessions(): int` — marks sessions past `expires_at` as `expired`, returns count
- `flush_rewrite_rules()`: static, used on deactivation

**Step 3 — Zabure API client**
File: `includes/class-zabure-api.php`
- Constructor reads `zabure_api_key` and `zabure_environment` from options
- `get_base_url(): string` — returns sandbox or live URL based on environment option
- `create_payment_link( int $post_id, int $amount, string $currency, string $description ): array|WP_Error`
  - POSTs to `/api/v1/payment-links`
  - `redirectUrl` must be: `home_url( '/zabure-return/?post_id=' . $post_id )`
  - Returns the full response array on success, `WP_Error` on failure
- `get_payment_link( string $link_id ): array|WP_Error`
  - GETs `/api/v1/payment-links/{id}`
- `delete_payment_link( string $link_id ): bool|WP_Error`
- All requests use `wp_remote_post()` / `wp_remote_get()` (never cURL directly)
- Handle HTTP errors, non-200 responses, and JSON decode errors — always return `WP_Error` with a descriptive message

**Step 4 — Admin class and meta box**
File: `includes/class-zabure-admin.php`
- Register admin menu: `Zabure Paywall` top-level menu with two subpages: `Access Manager` and `Payment Logs`
- Register Settings page under `Settings → Zabure Paywall` with these fields:
  - `zabure_api_key` (password input, sanitize_text_field)
  - `zabure_webhook_secret` (password input)
  - `zabure_environment` (radio: sandbox / live)
  - `zabure_phone_meta_key` (text input, default: `phone_number`)
  - Read-only field showing the webhook endpoint URL
- Meta box on all `post` edit screens: `Zabure Paywall Settings`
  - Checkbox: `_zabure_is_premium`
  - Number input: `_zabure_amount` (label: "Price (in smallest currency unit, e.g. 5000 = UGX 50)")
  - Select: `_zabure_currency` (options: UGX, KES, TZS, USD)
  - Number input: `_zabure_preview_paragraphs` (default: 2, min: 1)
  - Read-only status row: shows the Zabure payment link URL if it exists, with a [Copy] button. Shows a warning notice if premium is checked but no link has been created yet.
- `save_post` hook:
  - Saves all meta fields with proper sanitisation
  - If `_zabure_is_premium` is newly set to 1 and `_zabure_link_id` is empty: calls `Zabure_API::create_payment_link()` and stores the returned `id` and `url` in post meta
  - If save fails, stores an admin notice transient
- Access Manager page: table of `wp_usermeta` rows where `meta_key = _zabure_paid_posts`. Columns: User (display name + email), Post (title + edit link), Date Granted, Source, Actions. Each row has a [Revoke] button that POSTs to the manual grant endpoint.
- Payment Logs page: table from `Zabure_Database::get_all_sessions()`. Columns: Date, User, Post, Amount, Currency, Status, Source, Transaction ID. Status shown as coloured badges.

**Step 5 — Access Manager class**
File: `includes/class-zabure-access-manager.php`
- `has_access( int $user_id, int $post_id ): bool`
  - Reads `_zabure_paid_posts` user meta array
  - Returns `true` if `$post_id` is in the array
- `grant_access( int $user_id, int $post_id, int $session_id, string $source ): void`
  - Gets current `_zabure_paid_posts` array (or empty array if none)
  - Appends `$post_id` if not already present
  - Updates user meta
  - Calls `Zabure_Database::update_session_status()` to set `completed`, `completed_at = NOW()`, and `source`
  - Fires action hook: `do_action( 'zabure_access_granted', $user_id, $post_id, $source )`
- `revoke_access( int $user_id, int $post_id ): void`
  - Removes `$post_id` from `_zabure_paid_posts` array
  - Updates user meta
  - Fires action hook: `do_action( 'zabure_access_revoked', $user_id, $post_id )`
- `get_user_paid_posts( int $user_id ): array`
- `get_users_with_access( int $post_id ): array`

**Step 6 — Content filter class**
File: `includes/class-zabure-content-filter.php`
- Hook: `add_filter( 'the_content', [ $this, 'filter_content' ], 20 )`
- `filter_content( string $content ): string`
  - Only apply on `is_singular() && in_the_loop() && is_main_query()`
  - Get `$post_id = get_the_ID()`
  - If `_zabure_is_premium` is not `1`, return `$content` unchanged
  - If user is not logged in: return `truncate_content( $content, $n )` + `load_template( 'paywall-cta.php', ['logged_in' => false] )`
  - If user is logged in and `has_access()` is true: return `$content` unchanged
  - Otherwise: return `truncate_content( $content, $n )` + `load_template( 'paywall-cta.php', ['logged_in' => true] )`
- `truncate_content( string $content, int $paragraph_count ): string`
  - Apply `do_shortcode( $content )` first
  - Split on `</p>` using `preg_split`
  - Return only the first `$paragraph_count` closing-paragraph chunks
  - Ensure returned HTML is valid (no unclosed tags)
- `enqueue_assets()`: enqueues `paywall.css` and `paywall.js` only on singular premium posts. Localises JS with `wp_localize_script`: `{ ajax_url, nonce, post_id, is_logged_in }`

**Step 7 — Payment handler class and Initiate endpoint**
File: `includes/class-zabure-payment-handler.php`
- Registers REST route: `POST /wp-json/zabure-paywall/v1/initiate`
  - `permission_callback`: user must be logged in (`is_user_logged_in()`)
  - Parameters: `post_id` (integer, required), `phone_number` (string, required)
- `initiate_payment( WP_REST_Request $request ): WP_REST_Response`
  1. Sanitise and validate `post_id` — confirm post exists and `_zabure_is_premium = 1`
  2. Confirm `_zabure_link_url` exists in post meta (return 500 error with message if not — means admin hasn't set up the payment link yet)
  3. Sanitise `phone_number`: strip all non-numeric characters, must be 9–15 digits
  4. Save phone number to user meta using `get_option( 'zabure_phone_meta_key', 'phone_number' )`
  5. Generate session token: `bin2hex( random_bytes( 32 ) )` — 64 hex chars
  6. Insert session into DB: `{ session_token, user_id, post_id, amount, currency, zabure_link_id, status: pending, initiated_at: NOW(), expires_at: NOW() + 1800 seconds }`
  7. Set cookie: name `zabure_session`, value = session token, expiry = time() + 1800, path = `/`, secure = is_ssl(), httponly = true, samesite = Lax
     - Use `header()` directly for SameSite support since WordPress's `setcookie()` doesn't support SameSite on older PHP
  8. Return: `{ "payment_url": <_zabure_link_url from post meta>, "session_token": <token> }`

**Step 8 — Callback handler class**
File: `includes/class-zabure-callback-handler.php`
- Add rewrite rule: `add_rewrite_rule( '^zabure-return/?$', 'index.php?zabure_return=1', 'top' )`
- Add query var: `zabure_return`
- Hook: `template_redirect` — if `get_query_var('zabure_return')`, intercept and run `handle_callback()`
- `handle_callback(): void`
  1. If user is not logged in: `wp_redirect( wp_login_url( $_SERVER['REQUEST_URI'] ) ); exit;`
  2. Get `$post_id` from `$_GET['post_id']` — sanitise as absint, validate post exists
  3. Read cookie: `$_COOKIE['zabure_session']` — sanitise as `sanitize_text_field`
  4. If no cookie: show error "Session not found. If you completed payment, please contact support."
  5. Get session from DB by token: `Zabure_Database::get_session_by_token( $token )`
  6. Validate: session exists, `user_id` matches `get_current_user_id()`, `post_id` matches, `expires_at` is in the future, `status` is `pending` or `redirect_received`
  7. If session `status = completed`: access already granted → `wp_redirect( get_permalink( $post_id ) ); exit;`
  8. Update session status to `redirect_received`
  9. Delete the cookie (set expired)
  10. Load template: `templates/payment-processing.php` — pass `$post_id` and `$session_token`

**Step 9 — Status poll endpoint**
In `class-zabure-payment-handler.php`, add a second REST route:
- `GET /wp-json/zabure-paywall/v1/check-status`
- `permission_callback`: must be logged in
- Parameter: `token` (string, required)
- `check_payment_status( WP_REST_Request $request ): WP_REST_Response`
  1. Sanitise token
  2. Get session by token
  3. Validate: session exists, `user_id = get_current_user_id()`
  4. If `status = completed`: return `{ "status": "completed", "redirect_url": get_permalink( $post_id ) }`
  5. If `status = failed`: return `{ "status": "failed", "message": "Your payment was not completed. Please try again." }`
  6. If session expired: return `{ "status": "expired", "message": "Session expired. Please refresh and try again." }`
  7. Otherwise: return `{ "status": "pending" }`

**Step 10 — Webhook handler class**
File: `includes/class-zabure-webhook-handler.php`
This is the most security-critical file. Follow every detail precisely.
- Registers REST route: `POST /wp-json/zabure-paywall/v1/webhook`
  - `permission_callback`: `__return_true` (webhook is server-to-server, no WP auth)
- `handle_webhook( WP_REST_Request $request ): WP_REST_Response`
  1. **Read raw body FIRST** before any parsing: `$raw_body = $request->get_body()`
  2. Read signature header: `$signature = $request->get_header( 'x_webhook_signature' )`
  3. Get webhook secret: `get_option( 'zabure_webhook_secret', '' )`
  4. If secret is empty: log error and return 500 (misconfigured)
  5. Compute expected signature: `hash_hmac( 'sha256', $raw_body, $secret )`
  6. Compare using `hash_equals( $expected, $signature )` — if mismatch: return 401 `{ "error": "Invalid signature" }`. Log the attempt.
  7. Parse JSON: `$payload = json_decode( $raw_body, true )`
  8. If `$payload['event']` is not `transaction.collect.success`: return 200 `{ "received": true, "action": "ignored" }`
  9. If `$payload['status']` is not `SUCCESS`: log and return 200 (handle failed payments gracefully)
  10. **Deduplication check**: if `$payload['transactionId']` already exists in `zabure_transaction_id` column of sessions table: return 200 `{ "received": true, "action": "duplicate" }`
  11. **Match Strategy A** (redirect-confirmed session):
      - Call `Zabure_Database::get_sessions_by_amount_currency_window( $amount, $currency, 30 )`
      - Filter results to `status = redirect_received`
      - If exactly one result: this is our match → call `grant_access()` with `source = webhook`
      - If multiple results: log a warning, grant access to the most recently updated session (edge case for high-traffic sites)
  12. **Match Strategy B** (phone number fallback — browser crash scenario):
      - If Strategy A found nothing
      - Get `$phone = sanitize_text_field( $payload['phoneNumber'] )`
      - Query: `get_users( [ 'meta_key' => get_option('zabure_phone_meta_key', 'phone_number'), 'meta_value' => $phone ] )`
      - If user found: call `Zabure_Database::get_pending_session_by_user_post()` — look for a `pending` session for that user matching `amount + currency`
      - If found: grant access with `source = webhook`
  13. If neither strategy matches: log the full payload to a custom log (do not lose this data) — admin can manually reconcile
  14. Fire action hook: `do_action( 'zabure_webhook_received', $payload )`
  15. Always return: `{ "received": true }` with HTTP 200

**Step 11 — Manual grant endpoint**
In `class-zabure-admin.php`, register REST route:
- `POST /wp-json/zabure-paywall/v1/admin/grant`
- `permission_callback`: `current_user_can( 'manage_options' )`
- Parameters: `user_id` (int, required), `post_id` (int, required)
- Creates a synthetic session record with `source = manual` and calls `grant_access()`
- Returns `{ "success": true, "message": "Access granted." }`

Also register revoke endpoint:
- `POST /wp-json/zabure-paywall/v1/admin/revoke`
- Same permission check
- Parameters: `user_id` (int), `post_id` (int)
- Calls `Zabure_Access_Manager::revoke_access()`

**Step 12 — JavaScript (paywall.js)**
Write modern vanilla JavaScript (ES6+, no jQuery dependency — but use jQuery if WordPress already has it enqueued, which it will).
The script must handle three phases:

**Phase 1 — Payment initiation**
- On `DOMContentLoaded`, find `#zabure-pay-btn` (the CTA button)
- On click: show phone prompt modal (if phone not already confirmed), or directly call initiate
- Phone prompt `submit` handler: validate phone field (not empty, digits only), then call `initiate()`
- `initiate( phone_number )`:
  - POST to `zabure_paywall.ajax_url + '/wp-json/zabure-paywall/v1/initiate'`
  - Body: `{ post_id, phone_number }`
  - Include nonce header: `X-WP-Nonce`
  - On success: `window.location.href = response.payment_url`
  - On error: show error message in modal

**Phase 2 — Status polling (processing page)**
- On `DOMContentLoaded`, check if `document.body` has class `zabure-processing-page`
- Read `session_token` from `data-session-token` attribute on `#zabure-processing`
- Poll every 3 seconds: GET `/wp-json/zabure-paywall/v1/check-status?token=<token>` with nonce header
- On `completed`: show success message, then `window.location.href = response.redirect_url` after 1 second delay
- On `failed` or `expired`: show error message, stop polling, show [Try Again] button
- Timeout: stop polling after 10 minutes, show "Taking too long? Contact support."
- Animate a subtle loading indicator while polling

**Phase 3 — Admin copy button**
- On admin post edit screen, handle [Copy] button for payment link URL

**Step 13 — CSS (paywall.css)**
Write clean, minimal CSS. Style these elements:
- `.zabure-paywall-wrap`: wrapper around the CTA section, soft background, padding, border-radius
- `.zabure-paywall-blur`: a subtle visual blur/fade at the bottom of the preview content to indicate more content exists below
- `.zabure-paywall-cta`: the main CTA box (centered, clean card style)
- `#zabure-pay-btn`: primary button, bold, high-contrast
- `.zabure-phone-modal`: full-screen overlay modal, centered card
- `.zabure-phone-modal input`: styled text input
- `.zabure-processing-wrap`: centered loading state
- `.zabure-spinner`: CSS-only animated spinner
- All styles must be responsive (mobile-first)
- Use CSS variables for colours so themes can override: `--zabure-primary`, `--zabure-text`, `--zabure-bg`, `--zabure-border-radius`

**Step 14 — Templates**

`templates/paywall-cta.php`
Variables available: `$post_id`, `$logged_in` (bool), `$amount`, `$currency`, `$post_title`
- Show a lock icon (SVG inline, no external dependency)
- Heading: "Continue Reading"
- Subtext: "Unlock the full article for [formatted amount] [currency]"
- If not logged in: show [Login to Continue] button that links to `wp_login_url( get_permalink() )`
- If logged in: show [Unlock Full Article →] button with `id="zabure-pay-btn"` and `data-post-id="<?php echo esc_attr($post_id); ?>"`
- Include a `<div id="zabure-phone-modal" style="display:none;">` with the phone prompt (or load from phone-prompt.php)

`templates/phone-prompt.php`
Variables: `$post_id`, `$existing_phone` (pre-filled if already stored)
- Modal inner content
- Title: "Confirm Your Phone Number"
- Explanation: "We use your phone number to confirm payment in case you get disconnected."
- Input: `type="tel"`, `id="zabure-phone-input"`, `placeholder="+256700000000"`, pre-filled with `$existing_phone` if set
- [Continue to Payment →] submit button
- [Cancel] link

`templates/payment-processing.php`
Variables: `$post_id`, `$session_token`, `$post_title`
- `<body class="zabure-processing-page">` — achieved by using a custom `wp_head` approach or full-page template
- Spinner animation
- Heading: "Confirming your payment..."
- Subtext: "Please wait while we verify your payment. Do not close this page."
- Hidden div `id="zabure-processing"` with `data-session-token="<?php echo esc_attr($session_token); ?>"`
- Success state (hidden by default, shown by JS): "✅ Payment confirmed! Redirecting you now..."
- Error state (hidden by default, shown by JS): "❌ [error message]" + [Try Again] button
- Enqueue `paywall.js` on this page

**Step 15 — i18n pot file and final checks**
- Ensure every user-facing string uses `__()` or `esc_html__()` with domain `zabure-content-paywall`
- Add a `languages/` directory (empty, ready for translation)
- Verify `register_activation_hook` properly handles multisite
- Add an uninstall hook / `uninstall.php` that removes:
  - The sessions table
  - All `_zabure_*` post meta
  - All `_zabure_paid_posts` user meta
  - All `zabure_*` options
  - (Behind a confirmation option: `zabure_delete_data_on_uninstall = true`)

---

### Critical Security Reminders

Repeat these checks at every step:
- NEVER trust `$_GET`, `$_POST`, or `$_COOKIE` without sanitising
- ALWAYS use `$wpdb->prepare()` for every DB query that includes a variable
- ALWAYS use `hash_equals()` for signature and token comparisons (prevents timing attacks)
- NEVER output raw user data without escaping
- The webhook endpoint MUST verify the HMAC before doing anything else
- The callback handler MUST verify the user_id in the session matches the currently logged-in user
- Nonces expire — always check before trusting them

---

### What NOT to Do

- Do not use WooCommerce or any other payment plugin as a dependency
- Do not use `file_get_contents()` for HTTP requests — use `wp_remote_*` functions
- Do not hardcode API keys anywhere — always read from `get_option()`
- Do not create payment links for free posts
- Do not skip HMAC verification "for testing" — implement it properly from the start
- Do not store sensitive payment data (card numbers etc.) — Zabure handles that offsite

---

### Testing Checklist (Complete After Full Build)

After building all files, verify each of the following manually:

- [ ] Plugin activates without PHP errors
- [ ] Sessions DB table is created on activation
- [ ] Admin can mark a post as premium and a Zabure payment link is created via API and stored
- [ ] Free posts show full content to all users
- [ ] Premium posts show only N paragraphs + CTA to logged-out users
- [ ] Premium posts show only N paragraphs + CTA to logged-in users who haven't paid
- [ ] Clicking [Unlock Full Article] triggers the phone prompt if no phone on file
- [ ] Clicking [Unlock Full Article] skips phone prompt if phone already saved
- [ ] Initiate endpoint returns a valid Zabure payment URL
- [ ] Returning to /zabure-return/ with a valid cookie sets session to `redirect_received`
- [ ] Returning with an invalid/missing cookie shows an error
- [ ] Returning with an expired session token shows an error
- [ ] Status poll returns `pending` before webhook arrives
- [ ] Sending a mock webhook with correct HMAC signature grants access
- [ ] Sending a mock webhook with wrong HMAC signature returns 401
- [ ] Sending a duplicate `transactionId` returns 200 with action: duplicate (no double-grant)
- [ ] Premium post shows full content after access is granted
- [ ] Admin access manager shows the user's granted access
- [ ] Admin can revoke access and user sees the paywall again
- [ ] Admin can manually grant access for a user
- [ ] Uninstall removes all plugin data from DB and options

---

## PROMPT END
