# ClearWallet by ClearDesk SEO

**An open source AI agent payment processor for WordPress.**

ClearWallet charges AI agents (Claude, GPT, Perplexity, and others) to access your content using USDC on Base via the [x402 protocol](https://www.x402.org/). Humans browse normally; agents pay per request or per session. Payments settle gaslessly through an x402 facilitator into a Base USDC wallet you control.

```
agent request
    ↓
[detect]  Web Bot Auth (RFC 9421) or known User-Agent
    ↓
[challenge]  HTTP 402 + x402 payment requirements
    ↓
[verify]  Coinbase facilitator /verify
    ↓
[settle]  Coinbase facilitator /settle  →  USDC arrives in your wallet
    ↓
[grant]  HMAC session token, good for N pages or T seconds
    ↓
[cash out]  Convert USDC → USD yourself (Coinbase, Stripe, any off-ramp)
```

## Features

- **RFC 9421 signature verification** — full HTTP Message Signatures support including all derived components, multi-signature requests, Ed25519/ECDSA/RSA/HMAC algorithms, JWK key resolution, and algorithm-downgrade prevention.
- **x402 paywall** — standards-compliant 402 challenges with USDC requirements on Base mainnet or Sepolia testnet.
- **Sessions** — HMAC-SHA256 bearer tokens with configurable TTL and per-session page budgets.
- **Refunds** — automatic on 404, 5xx, or response timeout; on-demand via REST dispute endpoint; manual via admin UI.
- **Disputes** — `/wp-json/clearwallet/v1/dispute` accepts filings from the paying agent; small qualifying claims auto-refund.
- **Abuse handling** — per-agent rate limiting, auto-blocklist on repeated abuse events, manual blocklist by fingerprint.
- **Gasless transfers** — refunds and fee sweeps use EIP-3009 transferWithAuthorization relayed through the facilitator, so your wallet never needs ETH for gas.
- **Admin UI** — Tools → ClearWallet with Configuration, Dashboard, Fees, Disputes, Transactions, Setup guide, and Agent docs tabs.

## Installation

1. Download `clearwallet.zip` from [Releases](https://github.com/cleardeskseo/clearwallet/releases).
2. WordPress admin → Plugins → Add New → Upload Plugin → choose the zip.
3. Activate.
4. Open **Tools → ClearWallet → Setup guide** for credential walkthroughs.
5. Fill in **Configuration** tab, save.
6. Tick **Enable agent paywall**.

## Requirements

- WordPress 6.0+
- PHP 7.4+ with `sodium` and `openssl` extensions (both standard in modern PHP)
- A Base USDC wallet (Coinbase Business, CDP Server Wallet, or self-custody)
- Coinbase Developer Platform API credentials

See [SETUP.md](clearwallet/SETUP.md) for step-by-step credential acquisition.

## Architecture

```
clearwallet/
├── clearwallet.php              Plugin bootstrap, hook registration
├── readme.txt                WordPress.org plugin directory format
├── SETUP.md                  Operator credential walkthrough
├── uninstall.php             Drop tables and options on plugin removal
└── includes/
    ├── class-installer.php   Activation, DB schema, defaults
    ├── class-detector.php    Agent detection (Web Bot Auth + UA fallback)
    ├── class-gate.php        Core intercept: detect → 402 → settle → session
    ├── class-session.php     HMAC bearer tokens with budget/TTL
    ├── class-facilitator.php x402 facilitator client (verify/settle + gasless transfer)
    ├── class-refund.php      404/5xx/timeout/dispute refunds
    ├── class-fee-processor.php  1% fee accounting and sweeps
    ├── class-abuse.php       Rate limiting and blocklists
    ├── class-dispute.php     REST endpoints for dispute submission
    ├── class-admin.php       Tools menu UI (7 tabs)
    └── httpsig/              RFC 9421 / RFC 8941 library
        ├── class-structured-fields.php   Parser/serializer for SF dicts
        ├── class-signature-base.php      Base string construction
        ├── class-jwk.php                 Ed25519, RSA, EC key parsing
        ├── class-key-resolver.php        Operator key directory lookup
        └── class-verifier.php            Algorithm dispatch and validation
```

## Plugin fee

ClearWallet is free to download, install, and use. A 1% fee on each settled USDC transaction is collected automatically from your wallet and used to support continued development. Pending fees, sweep history, and a manual sweep button are available in the **Fees** admin tab. Fees are reversed automatically when a transaction is refunded before the next sweep.

## Tests

The plugin ships with four test suites:

```
test/run-tests.php           63 unit tests — RFC 9421 library
test/integration.php         14 integration tests — Detector with WordPress mocks
test/run-fee-tests.php       57 unit tests — FeeProcessor
test/run-session-tests.php   36 unit tests — Session HMAC tokens
                            ─────
                            170 tests total
```

Run any individual suite with `php test/run-*.php`. Each suite stubs the WordPress functions it needs in-line, so no WordPress install is required to run them.

## Standards

- [RFC 9421](https://datatracker.ietf.org/doc/html/rfc9421) — HTTP Message Signatures
- [RFC 8941](https://datatracker.ietf.org/doc/html/rfc8941) — Structured Field Values
- [x402](https://www.x402.org/) — HTTP-native payment protocol
- [Web Bot Auth](https://github.com/cloudflare/web-bot-auth) — Cloudflare's bot signing proposal
- [USDC on Base](https://www.circle.com/blog/usdc-now-available-on-base) — contract `0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913`

## License

GPL v2 or later. See `LICENSE`.

## Contributing

Issues and pull requests welcome at <https://github.com/cleardeskseo/clearwallet>. The test suite must pass for any PR. New features should ship with corresponding tests.

---

Built by [ClearDesk SEO](https://cleardeskseo.com).
