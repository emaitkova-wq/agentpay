=== AgentPay by ClearDesk SEO ===
Contributors: cleardeskseo
Tags: ai, agents, x402, usdc, micropayments, paywall, web-bot-auth
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.3.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Open source AI agent payment processor. Charge AI agents in USDC for access to your content. RFC 9421 Web Bot Auth, x402 protocol, Coinbase settlement, Stripe off-ramp. A 1% transaction fee supports continued development.

== Description ==

**AgentPay by ClearDesk SEO** is an open source AI agent payment processor for WordPress. It intercepts requests from AI agents (Claude, GPT, Perplexity, etc.) and requires payment in USDC before serving content. Humans browse normally; agents pay per request or per session.

**Plugin fee disclosure:**

This plugin is free to download and use. A 1% fee per settled USDC transaction supports continued development of AgentPay. The fee accumulates in your wallet and is periodically transferred from there — no third party touches your funds in between. You can view pending fees, sweep history, and trigger manual sweeps from the **Fees** tab. Fees are reversed automatically when a transaction is refunded before the next sweep.

**The pipeline:**

1. Detect — Web Bot Auth (RFC 9421 HTTP Message Signatures) primary, known User-Agent fallback.
2. Charge — Returns HTTP 402 with x402 payment requirements (USDC on Base).
3. Verify — Submits agent's signed payment to the Coinbase x402 facilitator.
4. Grant — Issues an HMAC session token good for N pages or T seconds.
5. Off-ramp — USDC accumulates in your wallet; Stripe handles USDC → USD → bank.

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

1. Upload the `agentpay` folder to `wp-content/plugins/`
2. Activate through the WordPress Plugins screen
3. Go to **Tools → AgentPay** to configure
4. Enter your Base USDC wallet, Coinbase API credentials, and Stripe account
5. Tick "Enable agent paywall" once everything tests green

== Frequently Asked Questions ==

= Does this affect human visitors? =

No. AgentPay only intercepts requests it can identify as automated — either via cryptographic signature (Web Bot Auth) or known agent User-Agent strings. Humans get the normal site.

= Is custody on the WordPress operator? =

No. Funds settle into your Base USDC wallet (your custody), and Stripe handles conversion to USD via your existing payouts setup. The plugin never holds money for third parties.

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

The plugin fetches a cryptographically signed JSON file from `https://cleardeskseo.com/api/agentpay/fee-config` to retrieve the current 1% fee destination wallet address. This file is signed with an Ed25519 private key controlled by ClearDesk SEO and verified by the plugin against an embedded public key before use. If the signature does not verify, the file is rejected and fee sweeps are paused.

* What is sent: standard HTTP request headers only (User-Agent: AgentPay/<version>). No site data, no operator data, no analytics.
* When: on plugin activation, once per 24 hours during the cron-driven fee sweep cycle, and on operator-triggered "Refresh fee config" admin action.
* Cached locally for 24 hours; 7-day grace period if the endpoint is unreachable.
* Service provider: ClearDesk SEO. Terms: https://cleardeskseo.com/terms. Privacy: https://cleardeskseo.com/privacy.

= Coinbase Developer Platform (CDP) =

When you configure your CDP API credentials (Setup tab), the plugin connects to `https://api.cdp.coinbase.com/platform/v2/` to verify credentials, provision or look up a Base USDC wallet, submit x402 payment receipts for verification, and issue refunds when a paid request fails.

* What is sent: your CDP API Key ID, JWT-signed authentication tokens (created locally from your API Secret and Wallet Secret), wallet operation requests, x402 payment payloads.
* When: only after you enter Coinbase credentials and only in response to specific operator or agent actions.
* Service provider: Coinbase, Inc. Terms: https://www.coinbase.com/legal/cloud_terms_of_service. Privacy: https://www.coinbase.com/legal/privacy.

= Stripe API =

When you configure Stripe credentials (Setup tab), the plugin connects to `https://api.stripe.com/v1/` to verify your Stripe account ID and optionally initiate USDC-to-USD conversion via Stripe Treasury.

* What is sent: your Stripe secret key (server-side only, never exposed to browsers), Stripe account ID, transfer requests.
* When: only after you enter Stripe credentials and only in response to specific operator actions.
* Service provider: Stripe, Inc. Terms: https://stripe.com/legal. Privacy: https://stripe.com/privacy.

= AI agent verification keys =

When an AI agent makes a request to your site and identifies itself via RFC 9421 HTTP Message Signatures, the plugin fetches the agent's public verification key from their `.well-known/http-message-signatures-directory` endpoint. The list of supported agents and their key directory URLs is hardcoded in `includes/class-detector.php` and visible in source.

* What is sent: standard HTTP GET request to the agent's public key directory. No site or operator data.
* When: only when an agent request arrives that carries a Signature-Agent header pointing at one of the supported agents.
* Service providers: vary per agent (Anthropic, OpenAI, Perplexity, etc.). Refer to each agent's own privacy policy. The fetched key directory is cached for 24 hours.

== Changelog ==

= 1.3.2 =
* Plugin-check compliance: removed all heredoc syntax from class-admin-setup.php and tools/sign-fee-config.php
* Moved admin Setup CSS/JS from inline PHP heredocs to proper external files (admin/css/admin-setup.css, admin/js/admin-setup.js) loaded via wp_enqueue_style/script
* Text domain renamed from `agentpay` to `agentpay-by-cleardesk-seo` to match plugin slug
* SETUP.md moved from plugin root to docs/ subdirectory
* Bumped "Tested up to" header to 7.0
* Added Screenshots section to readme

= 1.3.1 =
* readme.txt: added External Services disclosure section per WP.org plugin directory guidelines
* readme.txt: bumped Tested up to: 6.9

= 1.3.0 =
* Agent discovery: plugin now auto-publishes its REST endpoints at /.well-known/agentpay and as comments in robots.txt, so AI agents can find the paywall without prior knowledge of the site
* New AgentPay\Discovery class with rewrite rule registration, JSON payload builder, robots.txt filter hook
* Discovery JSON includes protocol (x402), detection method (RFC 9421), currency (USDC), network, USDC contract, and full endpoint URLs with HTTP methods and descriptions
* Operator filters to customize: agentpay_discovery_enabled, agentpay_discovery_payload, agentpay_robots_txt_lines
* 36 new tests covering payload structure, robots.txt injection, filter hooks, settings-driven values. Total suite: 348 tests
* Activation hook auto-flushes rewrites so /.well-known/agentpay resolves immediately on plugin activation

= 1.2.0 =
* Fee destination wallet moved out of source code into a cryptographically signed config fetched from cleardeskseo.com
* New AgentPay\FeeConfig class: Ed25519 signature verification against an embedded ClearDesk public key, 24-hour cache with 7-day grace period if the endpoint goes down
* Hard ceiling: remote-fetched fee_bps is always clamped to 100 (1%) client-side — ClearDesk can never raise the fee above what the source code allows
* Fail-closed: if the signature doesn't verify OR the endpoint is unreachable past grace, sweeps are skipped (fees stay in the operator's wallet until config recovers)
* Burn/placeholder address rejection: response data is validated against a forbidden-address list before being trusted
* New Fees-tab status display: shows when sweeps are paused and why
* New tools/sign-fee-config.php: CLI signing tool for ClearDesk to publish rotated configs without a plugin release
* 59 new tests covering canonical JSON, signature verification, validation, cache+grace semantics. Total suite: 312 tests
* No operator-facing setup change: existing v1.1.0 installs upgrade transparently. The only behavioral difference is that sweeps now require cleardeskseo.com reachable

= 1.1.0 =
* New Setup tab: provision a Coinbase wallet from inside WordPress admin — no Node, no CDP CLI, no SDK
* Added AgentPay\CdpClient: direct PHP implementation of CDP REST API authentication (Ed25519 + ES256)
* Three-field setup flow with "Create new wallet" (default) and "Use existing wallet" alternatives
* Loud one-time-shown warning modal for Wallet Secret with forced acknowledgement
* AJAX-based connection test before commit (verify credentials without creating anything)
* At-rest encryption of API/Wallet secrets in wp_options using wp_salt-derived AES-256-CBC
* Receiving address auto-populated to Configuration tab after successful setup
* Added 83 tests covering JWT format, Ed25519/ES256 signing, canonical JSON sorting, DER conversion, error translation. Total suite: 253 tests
* No breaking changes; v1.0.0 manual configuration still works

= 1.0.0 =
* First production release as **AgentPay by ClearDesk SEO**
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
* New agentpay_fee_sweeps table tracking every sweep attempt with on-chain tx
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
