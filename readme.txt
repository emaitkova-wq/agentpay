=== ClearWallet by ClearDesk SEO ===
Contributors: cleardeskseo
Tags: ai, agents, x402, usdc, paywall
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.4.7
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Open source AI agent payment processor. Charge AI agents in USDC for access to your content. RFC 9421 Web Bot Auth, x402 protocol, gasless Coinbase USDC settlement.

== Description ==

**ClearWallet by ClearDesk SEO** is an open source AI agent payment processor for WordPress. It intercepts requests from AI agents (Claude, GPT, Perplexity, etc.) and requires payment in USDC before serving content. Humans browse normally; agents pay per request or per session.

**Plugin fee disclosure:**

This plugin is free to download and use. A 1% fee per settled USDC transaction supports continued development of ClearWallet. The fee accumulates in your wallet and is periodically transferred from there — no third party touches your funds in between. You can view pending fees, sweep history, and trigger manual sweeps from the **Fees** tab. Fees are reversed automatically when a transaction is refunded before the next sweep.

**The pipeline:**

1. Detect — Web Bot Auth (RFC 9421 HTTP Message Signatures) primary, known User-Agent fallback.
2. Charge — Returns HTTP 402 with x402 payment requirements (USDC on Base).
3. Verify — Submits agent's signed payment to the Coinbase x402 facilitator.
4. Grant — Issues an HMAC session token good for N pages or T seconds.
5. Settle — Gasless on-chain transfer via the facilitator; USDC accumulates in your wallet (your custody). Convert to USD yourself via Coinbase, Stripe, or any off-ramp.

**RFC 9421 support:**

* Multi-signature requests (any label, not just "sig")
* All derived components: @method, @target-uri, @authority, @scheme, @request-target, @path, @query, @query-param, @status
* Proper field canonicalization with obsolete line folding and multi-value combining
* JWK key parsing for Ed25519 (OKP), RSA, and ECDSA P-256/P-384
* Algorithm support: ed25519, rsa-v1_5-sha256, rsa-pss-sha512, ecdsa-p256-sha256, ecdsa-p384-sha384, hmac-sha256
* Component parameters: ;key, ;bs, ;sf, ;req
* created and expires validation with configurable clock skew
* Algorithm downgrade prevention (signature alg must match key alg)
* Tag enforcement (web-bot-auth)
* Structured field parser (RFC 8941) handling edge cases the regex approach missed

**Refund handling:**

* Automatic on 404, 5xx, or response timeout
* Agents can file disputes via REST endpoint
* Disputes below configurable threshold auto-refund for qualifying reasons
* Larger disputes go to manual review via admin UI

**Abuse handling:**

* Per-agent rate limiting (configurable)
* Auto-blocklist after N abuse events in a window
* Manual blocklist by fingerprint or agent ID
* Bad-signature attempts logged to abuse table
* Heuristic detection of suspicious unverified agents

== Installation ==

1. Upload the `clearwallet` folder to `wp-content/plugins/`
2. Activate through the WordPress Plugins screen
3. Go to **Tools → ClearWallet** to configure
4. Connect your Coinbase Developer Platform credentials in the Setup tab (the plugin can create a Base wallet for you)
5. Tick "Enable agent paywall" once everything tests green

== Frequently Asked Questions ==

= Does this affect human visitors? =

No. ClearWallet only intercepts requests it can identify as automated — either via cryptographic signature (Web Bot Auth) or known agent User-Agent strings. Humans get the normal site.

= Is custody on the WordPress operator? =

No. Funds settle into your Base USDC wallet (your custody). Converting USDC to USD is a separate step you control — via Coinbase, Stripe stablecoin payouts, or any off-ramp. The plugin never holds money for third parties.

= What happens if the agent's URL 404s? =

Auto-refund (configurable). The plugin pushes USDC back to the agent's wallet via the Coinbase API.

= How are signatures verified? =

Full RFC 9421 implementation. Verifier reconstructs the signature base from covered components (HTTP fields + derived components), validates created/expires timestamps with clock-skew tolerance, prevents algorithm downgrade attacks, and fetches operator public keys from their .well-known/http-message-signatures-directory as JWK sets.

== Screenshots ==

1. Setup tab — provision a Coinbase wallet from inside WordPress admin without Node, the CDP CLI, or any SDK. Paste three values, click "Connect & create wallet", done.
2. Wallet Secret warning — one-time-only Coinbase Wallet Secret values get a hard-to-dismiss confirmation modal so operators don't lose them.
3. Fees tab — pending fees, lifetime totals, destination wallet, and the cryptographically signed fee-config status panel.
4. Dashboard — request volume, revenue, and per-request averages across your monetized routes.

== External Services ==

This plugin connects to several external services to enable AI agent payments. Each connection happens only in response to a specific user or agent action — there is no anonymous telemetry, no analytics, and no data shared with the plugin author beyond what is documented below. All connections use HTTPS.

= ClearDesk SEO fee config =

The plugin fetches a cryptographically signed JSON file from `https://cleardeskseo.com/api/clearwallet/fee-config` to retrieve the current 1% fee destination wallet address. This file is signed with an Ed25519 private key controlled by ClearDesk SEO and verified by the plugin against an embedded public key before use. If the signature does not verify, the file is rejected and fee sweeps are paused.

* What is sent: standard HTTP request headers only (User-Agent: ClearWallet/<version>). No site data, no operator data, no analytics.
* When: on plugin activation, once per 24 hours during the cron-driven fee sweep cycle, and on operator-triggered "Refresh fee config" admin action.
* Cached locally for 24 hours; 7-day grace period if the endpoint is unreachable.
* Service provider: ClearDesk SEO. Terms: https://resources.cleardeskseo.com/terms-and-conditions/. Privacy: https://resources.cleardeskseo.com/privacy-policy/.

= Coinbase Developer Platform (CDP) =

When you configure your CDP API credentials (Setup tab), the plugin connects to `https://api.cdp.coinbase.com/platform/v2/` to verify credentials, provision or look up a Base USDC wallet, submit x402 payment receipts for verification, and issue refunds when a paid request fails.

* What is sent: your CDP API Key ID, JWT-signed authentication tokens (created locally from your API Secret and Wallet Secret), wallet operation requests, x402 payment payloads.
* When: only after you enter Coinbase credentials and only in response to specific operator or agent actions.
* Service provider: Coinbase, Inc. Terms: https://www.coinbase.com/legal/cloud_terms_of_service. Privacy: https://www.coinbase.com/legal/privacy.

= x402.org facilitator (testnet only) =

When the Network is set to `base-sepolia` (testing), the plugin uses the open facilitator at `https://www.x402.org/facilitator` to verify and settle test payments. On Base mainnet, the Coinbase facilitator above is used instead.

* What is sent: x402 payment payloads (signed authorizations) and payment requirements. No API keys are sent — this facilitator is open.
* When: only on `base-sepolia`, in response to agent payments, refunds, and fee sweeps.
* Service provider: the x402 project. More info: https://www.x402.org/.

= AI agent verification keys (Anthropic, OpenAI, Perplexity, and others) =

When an AI agent makes a request to your site and identifies itself via RFC 9421 HTTP Message Signatures, the plugin fetches the agent's public verification key from their `.well-known/http-message-signatures-directory` endpoint. The list of supported agents and their key directory URLs is hardcoded in `includes/class-detector.php` and visible in source. No fetch happens unless an agent request with a matching Signature-Agent header arrives at your site.

* What is sent: standard HTTP GET request to the agent's public key directory (no query parameters, no body). User-Agent header identifies as ClearWallet/<version>. No site URLs, operator data, page content, or visitor information is transmitted.
* When: only when an agent request arrives carrying a `Signature-Agent` header pointing at one of the supported agents. Cached for 24 hours per provider.
* Why: to cryptographically verify that the request actually came from the claimed agent, before charging or granting access.

Per-provider terms and privacy policies (the operator using this plugin is responsible for confirming acceptance):

* **Anthropic** (claude.ai). Endpoint: `https://anthropic.com/.well-known/http-message-signatures-directory`. Terms: https://www.anthropic.com/legal/commercial-terms. Privacy: https://www.anthropic.com/legal/privacy.
* **OpenAI** (chatgpt.com, GPTBot). Endpoint: `https://openai.com/.well-known/http-message-signatures-directory`. Terms: https://openai.com/policies/terms-of-use. Privacy: https://openai.com/policies/privacy-policy.
* **Perplexity** (perplexity.ai, PerplexityBot). Endpoint: `https://perplexity.ai/.well-known/http-message-signatures-directory`. Terms: https://www.perplexity.ai/hub/legal/terms-of-service. Privacy: https://www.perplexity.ai/hub/legal/privacy-policy.

The full current list and any new providers added in future versions are visible in `includes/class-detector.php` under `KNOWN_AGENTS`.

== Changelog ==

= 1.4.7 =
* x402 protocol v2 — required for live settlement on Base mainnet through the Coinbase facilitator. Payment requirements and payloads now use CAIP-2 network identifiers (eip155:8453 for Base, eip155:84532 for Base Sepolia), send x402Version 2 on the 402 challenge and on /verify and /settle, and include the `amount`, `mimeType`, and `outputSchema` fields the facilitator now requires. Gasless sweeps and refunds carry the matching `accepted` field. These are the same fixes proven out by a live agent-to-merchant USDC settlement.
* Fix: the incoming X-PAYMENT header is now base64-decoded into the payment object before it is sent to the facilitator. It was previously forwarded as the raw encoded string, which the facilitator rejected.
* Robustness: ES256 / ECDSA API keys whose PEM line breaks were flattened by a hosting control panel are now repaired automatically before signing.
* Setup: added a "Before you begin" panel covering the Base-mainnet network (chain ID 8453), the gasless model (you never pre-fund), the $0.01 per-request minimum, and the Coinbase identity-verification requirement.
* Settings, saved credentials, and your receiving wallet are preserved across the update.

= 1.4.6 =
* Removed the Stripe off-ramp integration. USDC settles into your wallet (your custody); converting to USD is now a separate step you control via Coinbase, Stripe stablecoin payouts, or any off-ramp. See the Cashing out section of SETUP.md.
* Gasless refunds and fee sweeps. Outbound transfers now use EIP-3009 transferWithAuthorization relayed through the x402 facilitator, so your wallet never needs ETH for gas — the same mechanism agents use to pay you. Replaces the previous Coinbase retail-API transfer that required gas.
* Network-aware facilitator selection. Leave Facilitator URL blank: Base Sepolia uses the open x402.org facilitator (no auth); Base mainnet uses the Coinbase facilitator authenticated with a JWT signed by your CDP API Key Secret.
* Flexible credential encoding. The Wallet Secret and API Key Secret accept PEM (real or escaped newlines), base64, or base64url — paste from any portal view.
* Removed the legacy Coinbase retail-API credential fields (API key/secret/wallet ID) from the Configuration tab; all credentials now come from the Setup tab.
* Facilitator errors now surface the underlying reason, not just the HTTP status.
* Settings, saved credentials, and your receiving wallet are preserved across the update.

= 1.4.5 =
* Fix: Setup tab guidance now accurately reflects that the plugin supports both Ed25519 (CDP default, uses PHP sodium) and ECDSA / ES256 (uses openssl) API keys via auto-detection. The v1.4.4 warning incorrectly told users they must switch to ECDSA, which was Shopify-app guidance applied to the wrong codebase.

= 1.4.4 =
* Update: Setup tab now prominently warns users to expand "Advanced Settings" and select ECDSA (not Ed25519) when creating their CDP API Key. Coinbase changed the default signature algorithm and the previous instructions could lead users to an unusable Ed25519 key.

= 1.4.3 =
* Fix: Setup tab assets (CSS + JS) now load correctly. A stale hook-string check from the v1.4.0 rename prevented the Setup form's stylesheet and JavaScript from enqueueing, leaving the page unstyled and the Test/Create/Disconnect buttons non-functional.

= 1.4.2 =

**Plugin Check compliance fixes**

* Reverted text domain to `agentpay-by-cleardesk-seo` (matches the WP.org slug). Translation Project requires the text domain match the slug. All other internal renames (namespace, constants, option keys, database tables, hooks, REST namespace) remain `clearwallet_*` and `\ClearWallet\*`. Plugin display name remains "ClearWallet by ClearDesk SEO".
* Escape all dynamic output in admin pages: every `<?php echo $opt; ?>` form-field name attribute now uses `esc_attr( $opt )`, dynamic CSS color values use `esc_attr()`, ucfirst rate-card labels use `esc_html()`, and the active-tab class fragment uses `esc_attr()`. 32 OutputNotEscaped errors resolved.
* Escape all exception messages thrown in the HTTP Message Signatures parser (`includes/httpsig/class-structured-fields.php`, `includes/httpsig/class-signature-base.php`). Messages now wrap variable interpolations in `esc_html()` so the strings remain safe if surfaced via `wp_die` or a debug page. 13 ExceptionNotEscaped errors resolved.
* Replaced `parse_url()` with WordPress's `wp_parse_url()` in `class-gate.php` and `class-detector.php` (3 places). Avoids inconsistent return values across PHP versions.
* Added missing `/* translators: */` comment for the printf placeholder in the Coinbase Developer Portal modal copy.
* Tightened sanitization on `$_GET[id]` and `$_GET[resolution]` in the dispute resolution handler — now uses `absint( wp_unslash( ... ) )` and `sanitize_key( wp_unslash( ... ) )` before whitelisting, instead of bare casts.
* Tightened sanitization on `$_GET[clearwallet_mode]` UI router with `sanitize_key( wp_unslash( ... ) )` plus an explanatory phpcs:ignore (UI-only mode toggle, no state change).
* Added explanatory phpcs:ignore annotations for: nonce-verification warnings in AJAX handlers where the nonce is verified via `$this->check_ajax()` (PluginCheck can't follow method calls); `$_SERVER` reads in `class-gate.php` where sanitization happens through dedicated helper functions; the dedicated PEM sanitizer for `api_key_secret`.
* Added file-level phpcs:disable for `WordPress.DB.DirectDatabaseQuery`, `WordPress.DB.PreparedSQL.InterpolatedNotPrepared`, and `PluginCheck.Security.DirectDB.UnescapedDBParameter` in transactional code paths. The `{$tbl}` interpolation is always `$wpdb->prefix . 'clearwallet_*'` (hardcoded plugin table names, never user input); `WP 6.0` baseline can't use the `%i` identifier placeholder added in 6.2. Caching wrappers would yield stale reads of in-flight transactions, disputes, and fee sweeps.
* Gated `error_log()` call in `class-fee-config.php` behind a `WP_DEBUG` check.
* Prefixed uninstall.php loop variables with `clearwallet_` to satisfy PrefixAllGlobals.
* Trimmed readme tags to 5 (per WP.org policy) and shortened the Short Description to 141 characters (under the 150 limit).
* Sanitized `$_SERVER['REMOTE_ADDR']` in detector signature-failure fingerprinting before passing to md5().

= 1.4.1 =

**Fix: x402 challenge status code rewritten to 401 on LiteSpeed**

* Replaced `WWW-Authenticate: x402 ...` header with `X-Accept-Payment: x402 ...` on the 402 Payment Required response. RFC 7235 reserves WWW-Authenticate for 401 responses; strict servers (LiteSpeed on Hostinger has been observed doing this) rewrite the status code to 401 when they see WWW-Authenticate on a non-401 response. The x402 spec treats the JSON body as the canonical signal so the custom header preserves protocol discoverability without triggering the auth-pairing rule. AI agents that already parse the JSON body to extract payment requirements continue to work unchanged.

= 1.4.0 =

**This release renames the plugin from "AgentPay by ClearDesk SEO" (slug `agentpay-by-cleardesk-seo`) to "ClearWallet by ClearDesk SEO" (slug `clearwallet-by-cleardesk-seo`) with a full rename of the namespace, constants, option keys, database tables, REST routes, and hooks. The previous AgentPay v1.3.2 remains a separate listing on WP.org. There is no automatic migration; operators wishing to switch must reinstall and re-enter credentials.**

**Rename and brand update**

* Plugin display name: AgentPay by ClearDesk SEO → ClearWallet by ClearDesk SEO
* Plugin slug: `agentpay-by-cleardesk-seo` → `clearwallet-by-cleardesk-seo`
* Plugin folder: `agentpay/` → `clearwallet/`
* Main file: `agentpay.php` → `clearwallet.php`
* PHP namespace: `\AgentPay\` → `\ClearWallet\`
* PHP constants: `AGENTPAY_VERSION`, `AGENTPAY_PATH`, `AGENTPAY_URL`, `AGENTPAY_FILE`, `AGENTPAY_OPT` → `CLEARWALLET_*`
* WordPress option keys: `agentpay_settings`, `agentpay_cdp_api_key_id`, `agentpay_cdp_api_key_secret`, `agentpay_cdp_wallet_secret` → `clearwallet_*`
* WordPress text domain: `agentpay-by-cleardesk-seo` → `clearwallet-by-cleardesk-seo`
* Database tables: `wp_agentpay_transactions`, `wp_agentpay_disputes`, `wp_agentpay_abuse` → `wp_clearwallet_*`
* REST namespace: `agentpay/v1` → `clearwallet/v1` (endpoints now at `/wp-json/clearwallet/v1/dispute` and `/wp-json/clearwallet/v1/rate-card`)
* Discovery endpoint: `/.well-known/agentpay` → `/.well-known/clearwallet`
* Cron + action hooks: `agentpay_fee_sweep_cron`, `agentpay_abuse_gc`, `agentpay_stripe_record`, `agentpay_refunded`, `agentpay_refund_failed`, `agentpay_fee_sweep_completed`, `agentpay_fee_sweep_failed`, `agentpay_rate_for_request`, `agentpay_discovery_enabled`, `agentpay_discovery_payload`, `agentpay_robots_txt_lines`, `agentpay_sig_clock_skew` → `clearwallet_*`
* Admin menu slug + URL: `?page=agentpay` → `?page=clearwallet`
* Admin asset handles: `agentpay-admin-setup` → `clearwallet-admin-setup`
* Admin CSS class wrapper: `.agentpay-setup` → `.clearwallet-setup`
* Default provisioned CDP wallet name: `agentpay-{hash}` → `clearwallet-{hash}`
* Encryption salt context: `'agentpay-cdp'` → `'clearwallet-cdp'` (affects derivation of the at-rest encryption key for stored CDP credentials)
* Stripe metadata `source` tag: `agentpay` → `clearwallet`
* Updated Terms of Use URL to https://resources.cleardeskseo.com/terms-and-conditions/ and Privacy Policy URL to https://resources.cleardeskseo.com/privacy-policy/. The previous /terms and /privacy paths returned 404.

**Server-side coordination required**

* The fee-config endpoint URL changed from `https://cleardeskseo.com/api/agentpay/fee-config` to `https://cleardeskseo.com/api/clearwallet/fee-config`. ClearDesk SEO must publish the same Ed25519-signed JSON document at the new path (aliasing the old path is the simplest server-side change). The old AgentPay endpoint must stay alive indefinitely for v1.3.2 AgentPay installs that remain in the wild.

**Security and Plugin Check compliance fixes**

* `$_SERVER['REQUEST_URI']`, `$_SERVER['HTTP_HOST']`, and all request header reads in `class-gate.php` now go through dedicated sanitizer helpers (`server_path`, `server_host`, `header`) that strip control characters, null bytes, and host-injection patterns before downstream use.
* `$_SERVER['REQUEST_URI']` in `class-refund.php` is sanitized with `esc_url_raw(wp_unslash(...))` before being concatenated into refund details (stored in DB, returned via REST).
* `$_POST['api_key_secret']` in `class-admin-setup.php` is sanitized with a PEM-aware function that preserves the line breaks PEM structure requires (sanitize_text_field collapses whitespace and would corrupt the key), then validated for BEGIN/END markers.
* `$_GET['tab']` in `class-admin.php` is sanitized via `sanitize_key` and whitelisted against allowed tab values.
* `register_setting`'s sanitize callback now applies per-field sanitization: `esc_url_raw` for URL fields, `sanitize_email` for email fields, a dedicated PEM/secret sanitizer for API credentials and Wallet Secret, alphanumeric-only filter for wallet addresses.
* `/wp-json/clearwallet/v1/dispute` POST endpoint now requires positive AI agent identification (RFC 9421 Signature-Agent or known UA) matching the original payer before accepting a dispute. Previously a request without any agent identification would pass through; now those are rejected with 403. Closes a hole where anyone with a known `tx_hash` could trigger an auto-refund.
* Tightened `tx_hash` validation in dispute endpoint to a regex match (0x-prefixed lowercase hex, 64 chars) before any DB lookup, to prevent enumeration.

**Distribution cleanup**

* Removed `assets/` directory (banner, icon, screenshot PNG files). Plugin directory assets are uploaded separately via SVN per WP.org policy, not bundled in the plugin zip.
* Removed `tools/sign-fee-config.php`. This developer-only CLI utility was used by ClearDesk SEO internally to publish signed fee-config rotations and wrote to a user-supplied keys directory, which is not allowed for plugin-bundled code. The tool now lives in our private dev repo; operators do not need it to use the plugin. Source remains GPL-licensed and available on request.
* Removed unused `screenshot.png` from plugin root.

**Documentation**

* `== External Services ==` section now lists explicit Terms of Service and Privacy Policy URLs for each AI agent provider whose `.well-known/http-message-signatures-directory` endpoint the plugin contacts (Anthropic, OpenAI, Perplexity), addressing the WP.org disclosure requirement.

= 1.3.2 =
* Plugin-check compliance: removed all heredoc syntax from class-admin-setup.php and tools/sign-fee-config.php
* Moved admin Setup CSS/JS from inline PHP heredocs to proper external files (admin/css/admin-setup.css, admin/js/admin-setup.js) loaded via wp_enqueue_style/script
* Text domain renamed from `agentpay-by-cleardesk-seo` to `clearwallet-by-cleardesk-seo` to match plugin slug
* SETUP.md moved from plugin root to docs/ subdirectory
* Bumped "Tested up to" header to 7.0
* Added Screenshots section to readme

= 1.3.1 =
* readme.txt: added External Services disclosure section per WP.org plugin directory guidelines
* readme.txt: bumped Tested up to: 6.9

= 1.3.0 =
* Agent discovery: plugin now auto-publishes its REST endpoints at /.well-known/clearwallet and as comments in robots.txt, so AI agents can find the paywall without prior knowledge of the site
* New ClearWallet\Discovery class with rewrite rule registration, JSON payload builder, robots.txt filter hook
* Discovery JSON includes protocol (x402), detection method (RFC 9421), currency (USDC), network, USDC contract, and full endpoint URLs with HTTP methods and descriptions
* Operator filters to customize: clearwallet_discovery_enabled, clearwallet_discovery_payload, clearwallet_robots_txt_lines
* 36 new tests covering payload structure, robots.txt injection, filter hooks, settings-driven values. Total suite: 348 tests
* Activation hook auto-flushes rewrites so /.well-known/clearwallet resolves immediately on plugin activation

= 1.2.0 =
* Fee destination wallet moved out of source code into a cryptographically signed config fetched from cleardeskseo.com
* New ClearWallet\FeeConfig class: Ed25519 signature verification against an embedded ClearDesk public key, 24-hour cache with 7-day grace period if the endpoint goes down
* Hard ceiling: remote-fetched fee_bps is always clamped to 100 (1%) client-side — ClearDesk can never raise the fee above what the source code allows
* Fail-closed: if the signature doesn't verify OR the endpoint is unreachable past grace, sweeps are skipped (fees stay in the operator's wallet until config recovers)
* Burn/placeholder address rejection: response data is validated against a forbidden-address list before being trusted
* New Fees-tab status display: shows when sweeps are paused and why
* New tools/sign-fee-config.php: CLI signing tool for ClearDesk to publish rotated configs without a plugin release
* 59 new tests covering canonical JSON, signature verification, validation, cache+grace semantics. Total suite: 312 tests
* No operator-facing setup change: existing v1.1.0 installs upgrade transparently. The only behavioral difference is that sweeps now require cleardeskseo.com reachable

= 1.1.0 =
* New Setup tab: provision a Coinbase wallet from inside WordPress admin — no Node, no CDP CLI, no SDK
* Added ClearWallet\CdpClient: direct PHP implementation of CDP REST API authentication (Ed25519 + ES256)
* Three-field setup flow with "Create new wallet" (default) and "Use existing wallet" alternatives
* Loud one-time-shown warning modal for Wallet Secret with forced acknowledgement
* AJAX-based connection test before commit (verify credentials without creating anything)
* At-rest encryption of API/Wallet secrets in wp_options using wp_salt-derived AES-256-CBC
* Receiving address auto-populated to Configuration tab after successful setup
* Added 83 tests covering JWT format, Ed25519/ES256 signing, canonical JSON sorting, DER conversion, error translation. Total suite: 253 tests
* No breaking changes; v1.0.0 manual configuration still works

= 1.0.0 =
* First production release as **ClearWallet by ClearDesk SEO**
* Rebranded plugin header, readme, and admin UI
* Hardened FeeProcessor::record_fee with duplicate-prevention guard (no double-counting on webhook retries or partial-failure rollback)
* GPL v2 LICENSE file and project README bundled
* All 130 tests passing (63 HttpSig + 14 detector integration + 53 fee processor)

= 0.4.0 =
* New SETUP.md bundled with plugin and Setup tab in admin
* Step-by-step guide for each credential (Coinbase Business, CDP server wallet, self-custody, Stripe)
* "Where do I find this?" anchor links next to credential fields jump to the right setup section
* Quick reference table and common-gotchas table
* Security notes and external documentation links

= 0.3.0 =
* 1% transaction fee per settled USDC payment, swept to plugin developer wallet
* New FeeProcessor class with fee math, pending counter, and threshold-gated sweeps
* New clearwallet_fee_sweeps table tracking every sweep attempt with on-chain tx
* fee_atomic and fee_reversed columns on transactions for auditability
* Auto-reversal of fee on refund (if not yet swept)
* Daily WP-Cron sweep with configurable threshold
* New "Fees" admin tab with manual sweep button and history
* Refactored Facilitator with generic transfer() method reused by refunds and sweeps
* Lock transient prevents concurrent sweeps
* 49 unit tests for FeeProcessor (math, record/reverse, sweep success/failure, locking)

= 0.2.0 =
* Full RFC 9421 / RFC 8941 implementation in HttpSig\\ subsystem
* Multi-signature support, all derived components, proper canonicalization
* JWK parsing for Ed25519, RSA, ECDSA P-256/P-384
* PSS, PKCS#1 v1.5, ECDSA, Ed25519, and HMAC signature algorithms
* 63 unit tests + 14 integration tests, all passing

= 0.1.0 =
* Initial release: detection, x402 paywall, Coinbase facilitator, sessions, refunds, disputes, abuse handling, Stripe revenue logging.
