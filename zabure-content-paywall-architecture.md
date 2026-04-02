# Zabure Content Paywall — Plugin Architecture Reference

**Project:** WordPress Content Paywall Plugin
**Payment Gateway:** Zabure
**PHP Version:** 8.2.27
**WordPress Version:** 6.9.4
**Author:** chris (admin@wp-fundi.com)
**Last Updated:** 2026-04-02
**Status:** Pre-development reference — approved architecture

---

## 1. Overview

A custom WordPress plugin that restricts premium post content behind a Zabure payment wall. Logged-in users see a preview (featured image + 2 paragraphs) and a Call-to-Action to pay. After successful payment, the full content is permanently unlocked for that user with no expiry.

**Key design decisions:**
- No WooCommerce. No third-party plugins beyond the login plugin.
- Payment links are created automatically via the Zabure API (one per post). The Zabure dashboard stays clean.
- The webhook is the **source of truth** for confirming payment — even if the user's browser crashes, access is still granted.
- Access is stored permanently in `wp_usermeta`. No expiry.
- Users must be logged in to pay. Guest access is not supported.

---

## 2. Payment Flow (End-to-End)

### Normal Flow (redirect succeeds)

```
1.  User visits a premium post (logged in)
2.  Plugin shows: featured image + N paragraphs + paywall CTA
3.  User clicks "Unlock Full Article"
4.  JS calls: POST /wp-json/zabure-paywall/v1/initiate { post_id }
5.  Plugin:
      a. Verifies user is logged in
      b. Prompts user to confirm/enter phone number (if not stored yet)
      c. Generates a 32-char cryptographic session token (nonce)
      d. Saves session record to DB: { token, user_id, post_id, amount, currency, status: pending }
      e. Sets a short-lived cookie: zabure_session=<token> (HttpOnly, Secure, SameSite=Lax, 30 min)
      f. Returns the Zabure payment link URL for this post
6.  JS redirects browser to Zabure payment page
7.  User completes payment on Zabure
8.  Zabure redirects browser to: /zabure-return/?post_id=42
9.  Plugin callback handler:
      a. Reads cookie: zabure_session
      b. Looks up session in DB by token
      c. Verifies: token matches, user_id = current user, not expired, status = pending
      d. Sets session status → redirect_received
      e. Checks if webhook has already arrived (status = completed) → if yes, redirect to full post
      f. If not yet: show "Confirming your payment..." page with AJAX polling
10. Webhook arrives (server-to-server from Zabure):
      a. Verifies HMAC-SHA256 signature
      b. Finds the redirect_received session by amount + currency + timing
      c. Sets session status → completed, source = webhook
      d. Grants access: adds post_id to user's _zabure_paid_posts meta
11. AJAX poll detects status = completed → JS redirects to full post
```

### Fallback Flow (browser crashes, redirect never completes)

```
1–7. Same as above (session created, user goes to Zabure)
8.   User's browser crashes / closes before redirect
9.   Webhook fires from Zabure server regardless:
      a. Verifies HMAC-SHA256 signature
      b. No redirect_received session found → tries phone number matching
      c. Looks up WordPress user by phone number (from webhook payload phoneNumber field)
      d. Finds that user's pending session for a post with matching amount + currency
      e. Grants access: adds post_id to user's _zabure_paid_posts meta
      f. Session status → completed, source = webhook
10.  Next time user visits the post → full content shown automatically
```

### Access Check (every page load)

```
1. the_content filter fires
2. Is this post premium? (_zabure_is_premium = 1)
   → No:  show full content, stop here
   → Yes: continue
3. Is user logged in?
   → No:  show preview + "Login to read the full article" prompt
   → Yes: continue
4. Does _zabure_paid_posts contain this post_id?
   → Yes: show full content
   → No:  show preview + paywall CTA
```

---

## 3. Database Table

**Table:** `wp_zabure_paywall_sessions`

```sql
CREATE TABLE wp_zabure_paywall_sessions (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_token       VARCHAR(64)     NOT NULL UNIQUE,
    user_id             BIGINT UNSIGNED NOT NULL,
    post_id             BIGINT UNSIGNED NOT NULL,
    amount              INT UNSIGNED    NOT NULL,
    currency            VARCHAR(10)     NOT NULL,
    zabure_link_id      VARCHAR(100)    DEFAULT NULL,
    zabure_transaction_id VARCHAR(100)  DEFAULT NULL,
    zabure_external_ref VARCHAR(100)    DEFAULT NULL,
    status              ENUM('pending','redirect_received','completed','failed','expired')
                                        NOT NULL DEFAULT 'pending',
    source              ENUM('redirect','webhook','manual') DEFAULT NULL,
    initiated_at        DATETIME        NOT NULL,
    completed_at        DATETIME        DEFAULT NULL,
    expires_at          DATETIME        NOT NULL,
    INDEX idx_user_id   (user_id),
    INDEX idx_post_id   (post_id),
    INDEX idx_status    (status),
    INDEX idx_token     (session_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**Session lifecycle:**
- `pending` → created when user clicks Pay
- `redirect_received` → user returned from Zabure via browser redirect
- `completed` → webhook confirmed, access granted
- `failed` → webhook reported a failed/cancelled payment
- `expired` → nonce TTL (30 min) elapsed without completion

---

## 4. Post Meta Fields

Stored per post on the post edit screen via a custom meta box.

| Meta Key                      | Type    | Description                                              |
|-------------------------------|---------|----------------------------------------------------------|
| `_zabure_is_premium`          | int     | `1` = premium, `0` = free. Default: `0`                  |
| `_zabure_amount`              | int     | Amount in smallest currency unit (e.g. `5000` = UGX 50) |
| `_zabure_currency`            | string  | One of: `UGX`, `KES`, `TZS`, `USD`                      |
| `_zabure_preview_paragraphs`  | int     | How many paragraphs to show free. Default: `2`           |
| `_zabure_link_id`             | string  | Zabure payment link ID (set by plugin via API)           |
| `_zabure_link_url`            | string  | Zabure shareable URL, e.g. `https://pay.zabure.com/l/x` |

**Important:** `_zabure_link_id` and `_zabure_link_url` are written automatically by the plugin when admin saves the post with `_zabure_is_premium = 1`. The admin does **not** create links manually in the Zabure dashboard.

---

## 5. User Meta Fields

| Meta Key                | Type   | Description                                               |
|-------------------------|--------|-----------------------------------------------------------|
| `_zabure_paid_posts`    | array  | Serialized array of post IDs user has paid for, e.g. `[42, 55, 103]` |
| `phone_number`          | string | User's phone number (key name is configurable in settings). Used for webhook-to-user matching. |

**Access check (performant):**
```php
$paid_posts = get_user_meta($user_id, '_zabure_paid_posts', true) ?: [];
$has_access = in_array($post_id, (array) $paid_posts);
```

---

## 6. Plugin Settings (wp-admin Options)

Stored in `wp_options` as individual options.

| Option Key                      | Description                                                               |
|---------------------------------|---------------------------------------------------------------------------|
| `zabure_api_key`                | Zabure API key (X-API-Key header)                                         |
| `zabure_webhook_secret`         | HMAC-SHA256 secret configured in Zabure dashboard                         |
| `zabure_environment`            | `sandbox` or `live`                                                       |
| `zabure_phone_meta_key`         | The `wp_usermeta` key that holds the user's phone number (e.g. `phone_number`) |

**API base URLs:**
- Sandbox: `https://sandbox.zabure.com`
- Live: `https://pay.zabure.com`

---

## 7. PHP Class Structure

```
zabure-content-paywall/
├── zabure-content-paywall.php          ← Main plugin file: constants, autoloader, init
├── includes/
│   ├── class-zabure-api.php            ← Zabure REST API client (create link, list links)
│   ├── class-zabure-database.php       ← All DB CRUD: sessions table operations
│   ├── class-zabure-access-manager.php ← grant_access(), has_access(), revoke_access()
│   ├── class-zabure-content-filter.php ← the_content hook, preview truncation, paywall HTML
│   ├── class-zabure-payment-handler.php← Initiate endpoint: session creation, API call
│   ├── class-zabure-callback-handler.php ← /zabure-return/ rewrite rule handler
│   ├── class-zabure-webhook-handler.php← Webhook endpoint: HMAC verify, access grant logic
│   └── class-zabure-admin.php          ← Admin pages, meta box, settings page
├── assets/
│   ├── css/
│   │   └── paywall.css                 ← Paywall CTA styles, processing screen styles
│   └── js/
│       └── paywall.js                  ← Initiate payment AJAX, status polling, redirect
└── templates/
    ├── paywall-cta.php                 ← "Unlock this article" call-to-action HTML
    ├── phone-prompt.php                ← Phone number collection/confirmation modal
    └── payment-processing.php         ← "Confirming your payment..." waiting screen
```

---

## 8. REST API Endpoints

### 8.1 Initiate Payment
```
POST /wp-json/zabure-paywall/v1/initiate
Authentication: WordPress cookie (must be logged in)
Body: { "post_id": 42, "phone_number": "256700000000" }

Success 200:
{
  "payment_url": "https://pay.zabure.com/l/abc123",
  "session_token": "a1b2c3...32chars"
}

Error 401: Not logged in
Error 400: Post not found, not premium, or invalid phone
Error 500: Zabure API error
```

**Logic:**
1. Validate user is logged in
2. Validate post exists and `_zabure_is_premium = 1`
3. Save/update phone number in user meta (`_zabure_phone_meta_key`)
4. Generate `session_token` using `bin2hex(random_bytes(32))`
5. Set `zabure_session` cookie (HttpOnly, Secure, SameSite=Lax, expires +30min)
6. Insert session row: `{ token, user_id, post_id, amount, currency, status: pending, expires_at: +30min }`
7. Return `_zabure_link_url` from post meta (link was pre-created at post save time)

---

### 8.2 Return Callback (Rewrite Rule)
```
GET /zabure-return/?post_id=42
Authentication: WordPress cookie (must be logged in)
No body

Renders: templates/payment-processing.php
Or: wp_redirect() to post if already confirmed
```

**Logic:**
1. Read `zabure_session` cookie
2. Get current user ID
3. Look up session by token — verify `user_id` matches, verify not expired
4. Set session status → `redirect_received`
5. Check if session already `completed` (webhook beat the redirect) → redirect to post
6. Otherwise: render processing page with `session_token` in a data attribute for JS polling

---

### 8.3 Status Poll (AJAX)
```
GET /wp-json/zabure-paywall/v1/check-status?token=<session_token>
Authentication: WordPress cookie (must be logged in)

Pending response:
{ "status": "pending" }

Completed response:
{ "status": "completed", "redirect_url": "https://site.com/post-slug/" }

Failed response:
{ "status": "failed", "message": "Payment was not completed." }
```

**JS behaviour:** Polls every 3 seconds. On `completed`, sets `window.location.href` to `redirect_url`. Times out after 10 minutes with a "contact support" message.

---

### 8.4 Webhook Receiver
```
POST /wp-json/zabure-paywall/v1/webhook
Authentication: HMAC-SHA256 signature in x-webhook-signature header
No WordPress auth required (server-to-server)

Expected payload:
{
  "event": "transaction.collect.success",
  "transactionId": "uuid",
  "externalReference": "PAY-abc123xyz",
  "status": "SUCCESS",
  "amount": 50000,
  "currency": "UGX",
  "phoneNumber": "256700000000",
  "customerName": "Test Customer",
  "metadata": {},
  "timestamp": "2025-12-19T20:30:45.123Z"
}

Always returns 200 immediately after signature check.
```

**Logic (in order):**
1. Read raw request body (before any parsing — required for HMAC)
2. Read `x-webhook-signature` header
3. Compute `hash_hmac('sha256', $raw_body, $webhook_secret)`
4. Compare with header value using `hash_equals()` — if mismatch, log and return 401
5. Parse JSON payload
6. Ignore events that are not `transaction.collect.success`
7. **Match Strategy A (redirect-confirmed):**
   - Query sessions: `status = redirect_received` AND `amount = payload.amount` AND `currency = payload.currency` AND `expires_at > NOW()`
   - If exactly one match found: grant access (source = `webhook`)
8. **Match Strategy B (phone number fallback):**
   - If Strategy A finds no match (redirect never completed)
   - Query `wp_usermeta` for `meta_key = [phone_meta_key]`, `meta_value = payload.phoneNumber`
   - If user found: find their `pending` session matching `amount + currency`
   - If found: grant access (source = `webhook`)
9. Log webhook event to DB regardless of match outcome
10. Return `{ "received": true }` with HTTP 200

---

### 8.5 Admin Manual Grant
```
POST /wp-json/zabure-paywall/v1/admin/grant
Authentication: WordPress cookie, must have manage_options capability
Body: { "user_id": 5, "post_id": 42 }

Success: { "success": true }
Error 403: Not an admin
```

---

## 9. Security Design

| Threat | Mitigation |
|--------|-----------|
| Fake redirect (manually typing callback URL) | Session token is 32-byte random (256 bits entropy), single-use, 30-minute expiry, bound to logged-in user ID |
| Webhook spoofing | HMAC-SHA256 signature verified with `hash_equals()` before any processing |
| Token reuse | Session token marked `completed` immediately on first valid use |
| Race condition (redirect + webhook arrive simultaneously) | DB transaction + `status` ENUM ensures only one wins; second check is a no-op |
| Webhook replay attack | `transactionId` (UUID) stored — duplicate transaction IDs are rejected |
| CSRF on initiate endpoint | WordPress nonce required in JS AJAX header |
| Privilege escalation via manual grant | `manage_options` capability check |
| Phone number brute force on webhook | HMAC must pass first; attacker can't reach matching logic |

---

## 10. Admin UI Pages

### 10.1 Post Meta Box (on Post edit screen)
Shown for all posts. Fields:
- [ ] **Premium post** — checkbox toggle
- **Price** — number input (in smallest currency unit)
- **Currency** — dropdown: UGX / KES / TZS / USD
- **Free preview paragraphs** — number input (default: 2)
- **Payment link status** — read-only display:
  - ✅ Active: `https://pay.zabure.com/l/abc123` [Copy] [Refresh]
  - ❌ Not created — saved automatically when post is saved with Premium = on

### 10.2 Plugin Settings Page
`wp-admin → Settings → Zabure Paywall`
- Zabure API Key (password field)
- Webhook Secret (password field)
- Environment: ⚬ Sandbox ⚬ Live
- Phone Number Meta Key (text field, default: `phone_number`)
- Webhook URL — read-only display of the endpoint URL to copy into Zabure dashboard

### 10.3 Access Manager Page
`wp-admin → Zabure Paywall → Access Manager`

Table: User | Post | Date Granted | Source | Transaction Ref | Actions
Actions per row: [Revoke Access]
Top controls: Search by user, Search by post, [Grant Access Manually] button

### 10.4 Payment Logs Page
`wp-admin → Zabure Paywall → Payment Logs`

Table: Date | User | Post | Amount | Currency | Status | Source | Transaction ID
Filters: Status dropdown, Date range picker
Export: CSV download

---

## 11. Phone Number Prompt (UX Flow)

Because the webhook-fallback path requires a phone number to identify the user, the plugin includes a phone number confirmation step in the payment flow.

**When it appears:** Before the user is redirected to Zabure (during the initiate step), a modal or inline form appears:

> **Confirm your payment details**
> We'll use your phone number to confirm payment if your browser disconnects.
> Phone number: [+256 ____________]
> [Continue to Payment →]

**Logic:**
- If `_zabure_phone_meta_key` already has a value for this user → pre-fill the field and show as confirmation (they can edit it)
- If empty → required field
- On submit → saves to user meta, then proceeds to initiate the payment session
- Phone number is stored in format: country code + number, no spaces (e.g. `256700000000`)

**Template file:** `templates/phone-prompt.php`
**Handled by:** `class-zabure-payment-handler.php` + `paywall.js`

---

## 12. Content Truncation Logic

```php
function truncate_to_paragraphs(string $content, int $paragraph_count): string {
    // Strip shortcodes before processing
    $content = do_shortcode($content);

    // Split by closing paragraph tags
    $paragraphs = preg_split('/(<\/p>)/i', $content, -1, PREG_SPLIT_DELIM_CAPTURE);

    $output = '';
    $count = 0;
    foreach ($paragraphs as $i => $part) {
        $output .= $part;
        if (stripos($part, '</p>') !== false) {
            $count++;
            if ($count >= $paragraph_count) break;
        }
    }
    return $output;
}
```

The featured image is always shown (handled by the theme, not filtered by the plugin).

---

## 13. Zabure API Client

**Class:** `Zabure_API`
**Base URL:** dynamically set from `zabure_environment` option

```php
// Create a payment link (called at post save time)
$api->create_payment_link([
    'amount'      => 50000,
    'currency'    => 'UGX',
    'description' => 'Access: ' . get_the_title($post_id),
    'redirectUrl' => home_url('/zabure-return/?post_id=' . $post_id),
]);
// Returns: { id, url, status, ... }
// Stored in: _zabure_link_id, _zabure_link_url post meta
```

**Note on redirectUrl:** This is set **once at link creation time** and is a static URL pointing back to the WordPress callback endpoint. The per-user session tracking is handled via the cookie, not the URL.

---

## 14. Plugin Bootstrap (main file)

```php
// zabure-content-paywall.php
define('ZABURE_PAYWALL_VERSION', '1.0.0');
define('ZABURE_PAYWALL_PATH', plugin_dir_path(__FILE__));
define('ZABURE_PAYWALL_URL',  plugin_dir_url(__FILE__));

register_activation_hook(__FILE__,   ['Zabure_Database', 'create_tables']);
register_deactivation_hook(__FILE__, ['Zabure_Database', 'maybe_cleanup']);

add_action('plugins_loaded', function() {
    // Load all classes
    // Register REST routes
    // Register rewrite rules
    // Register content filter
    // Register admin pages
});
```

---

## 15. Action Hooks & Filters (for extensibility)

| Hook | Type | When it fires |
|------|------|---------------|
| `zabure_access_granted` | action | After user is given access to a post. Args: `$user_id`, `$post_id`, `$source` |
| `zabure_access_revoked` | action | After admin revokes access. Args: `$user_id`, `$post_id` |
| `zabure_before_paywall_cta` | action | Just before the CTA HTML is output |
| `zabure_paywall_cta_html` | filter | Filter the full CTA HTML block. Args: `$html`, `$post_id` |
| `zabure_preview_paragraph_count` | filter | Override paragraph count dynamically. Args: `$count`, `$post_id` |
| `zabure_webhook_matched` | action | Fires when webhook is matched to a session. Args: `$session`, `$payload` |
| `zabure_webhook_unmatched` | action | Fires when webhook cannot be matched. Args: `$payload` |

---

## 16. Open Questions / Future Considerations

- [ ] **Bundle pricing** — Should the plugin support selling multiple posts as a bundle at a discount? (Not in v1)
- [ ] **Refunds** — Admin manually revokes access for now. No automated refund webhook handling in v1.
- [ ] **Guest support** — Currently requires login. If guest access is ever needed, architecture needs revisiting.
- [ ] **Email notification** — Send user a "payment confirmed" email via `wp_mail()` on access grant. Easy to add via `zabure_access_granted` hook.
- [ ] **Transaction ID deduplication** — Store `zabure_transaction_id` and reject duplicate webhooks. Implement in v1 as a safety measure.
- [ ] **Login plugin phone meta key** — Must confirm the exact `wp_usermeta` key used by the chosen login plugin and set it in plugin settings.
- [ ] **Retry logic for Zabure API** — If link creation fails at post save time, add an admin notice and a [Retry] button in the meta box.

---

## 17. Development Sequence (Recommended Build Order)

1. Plugin scaffold + autoloader + activation hook (DB table creation)
2. `Zabure_Database` class — all session CRUD methods
3. `Zabure_API` client — `create_payment_link()` method only
4. Admin meta box — premium toggle, price, currency, link creation on save
5. `Zabure_Access_Manager` — `grant_access()`, `has_access()`, `revoke_access()`
6. `Zabure_Content_Filter` — `the_content` hook, truncation, paywall CTA template
7. Initiate endpoint + phone prompt template + `paywall.js`
8. `Zabure_Callback_Handler` — redirect handler, cookie validation, processing template
9. Status poll endpoint + polling JS
10. `Zabure_Webhook_Handler` — HMAC verification, matching strategies A & B, access grant
11. Admin settings page
12. Admin access manager + payment logs pages
13. Manual grant endpoint
14. End-to-end testing (sandbox environment)
15. Security audit + hardening pass

---

*This document is the single source of truth for the plugin's architecture. All implementation decisions during development should trace back to this spec. Any deviations must be documented here.*
