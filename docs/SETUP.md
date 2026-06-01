# AgentPay by ClearDesk SEO — Setup Guide

Open source AI agent payment processor. This document explains where to find each credential AgentPay needs.

You'll set up three things:

1. **A Base USDC wallet (yours)** — where agent payments arrive
2. **Coinbase API credentials** — to verify and settle x402 payments, and to push refunds and fee sweeps
3. **Stripe credentials** — to convert USDC to USD and deposit to your bank

This document walks through where to find each one.

---

## Quick path (new in v1.1.0)

If you don't already have a Base wallet, **the plugin will create one for you from inside WordPress admin** — no Node, no CDP CLI, no SDK required.

1. Sign up at <https://portal.cdp.coinbase.com/> (2 minutes — email + verification)
2. In the CDP Portal, create a **Secret API Key** (Access → API Keys → Create) and copy the API Key ID + Secret
3. In the CDP Portal, generate a **Wallet Secret** (Server Wallets → Wallet Secret → Generate) — **shown ONCE; copy immediately**
4. In WordPress admin, go to **Tools → AgentPay → Setup**
5. Paste the three values into the form, click **Connect & create wallet**
6. Plugin provisions a new EVM account on Base via the CDP REST API and stores the receiving address

Total time: about 5 minutes assuming you start from zero. The plugin handles the JWT signing, account creation, and address storage automatically. Skip directly to **Section 3 (Stripe)** below — you can come back to Section 1 if you ever want to switch to a self-custody wallet or migrate to Coinbase Business.

If you'd rather provision the wallet manually (using the CDP SDK or an existing wallet you already own), keep reading. Both paths produce the same end state.

---

## Quick reference

| Plugin field            | Format                          | Source                                                      |
| ----------------------- | ------------------------------- | ----------------------------------------------------------- |
| PayTo wallet            | `0x` + 40 hex chars             | Your Base wallet's receive address                          |
| Network                 | `base` or `base-sepolia`        | Choose based on whether you're live or testing              |
| USDC contract           | `0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913` | Pre-filled for Base mainnet                       |
| Facilitator URL         | `https://api.cdp.coinbase.com/platform/v2/x402` | Pre-filled; Coinbase's hosted x402 facilitator   |
| Coinbase API key        | string (or UUID)                | CDP portal → API Keys                                       |
| Coinbase API secret     | PEM private key or hex string   | Downloaded with the API key                                 |
| Coinbase wallet ID      | UUID                            | CDP portal → Wallets (or `/v2/accounts` for legacy v2)      |
| Stripe account ID       | `acct_` + 24 chars              | Stripe dashboard footer / Account settings                  |
| Stripe secret key       | `sk_live_…` or `sk_test_…`      | Stripe dashboard → Developers → API keys                    |

---

## 1. Your Base USDC wallet

You need a wallet on the Base network that can receive USDC. There are three reasonable paths.

### Option A: Coinbase Business

Best for: regulated businesses that want fiat off-ramping handled.

1. Sign up at <https://www.coinbase.com/business>
2. Verify your business (legal name, address, EIN/equivalent, beneficial owners)
3. Once approved, navigate to **Assets → USDC → Receive**
4. **Select the Base network** (not Ethereum mainnet — common mistake)
5. Copy the `0x…` address. Paste this into the plugin's **PayTo wallet** field.

### Option B: CDP Server Wallet

Best for: full automation, including programmatic refunds and fee sweeps.

1. Sign up at <https://portal.cdp.coinbase.com/>
2. Create a project (any name)
3. **Wallets → Create wallet → EVM → Base**
4. Note the wallet ID (UUID format) — this goes in the **Coinbase wallet ID** field
5. The wallet's address goes in the **PayTo wallet** field
6. Server wallets are signed by Coinbase's HSM, not by you holding a seed phrase

### Option C: Self-custody (Coinbase Wallet, MetaMask, Rainbow)

Best for: developers comfortable managing seed phrases.

1. Install Coinbase Wallet (extension or mobile) or MetaMask
2. Add the **Base** network if not present (chain ID 8453, RPC `https://mainnet.base.org`)
3. Copy your wallet address
4. **Save your seed phrase securely.** No seed phrase = no funds, ever.

Note: with self-custody, the plugin **cannot push automated refunds** — you'd handle refunds manually. For automated refunds, use Option A or B.

---

## 2. Coinbase API credentials

The plugin uses these to:
- Call the facilitator's `/verify` and `/settle` endpoints on every agent payment
- Push refunds (404s, 5xx errors, disputes) from your wallet to the agent
- Push 1% fee sweeps from your wallet to the plugin developer

### Getting the API key (CDP)

1. Go to <https://portal.cdp.coinbase.com/>
2. Sign in. Create a project if you haven't.
3. **API Keys → Create API key**
4. Choose **Server** (not browser/SDK)
5. Download the JSON. It contains a `name` (key ID) and `privateKey` (PEM-encoded EC private key).
6. In the plugin:
   - **Coinbase API key** = the `name` field (looks like `organizations/abc.../apiKeys/xyz...`)
   - **Coinbase API secret** = the `privateKey` field (multi-line PEM string)
7. Set permissions: `wallet:transactions:send`, `wallet:accounts:read`

Treat the secret like a password. Anyone who has it can move funds from the associated wallet.

### Getting the wallet ID

If you created a CDP server wallet (Option B above), the wallet ID is shown in the CDP portal's Wallets list.

If you're using a Coinbase Business / Coinbase.com account with the legacy v2 API instead:
1. Make an authenticated request to `https://api.coinbase.com/v2/accounts`
2. Find the entry where `currency.code` is `USDC`
3. The `id` field is your wallet ID (UUID)

### Facilitator URL

Default: `https://api.cdp.coinbase.com/platform/v2/x402`

Don't change this unless Coinbase publishes a new endpoint or you're running your own facilitator (e.g., for a private network). The plugin's **Test facilitator connection** button hits the `/supported` endpoint on this URL to confirm reachability.

---

## 3. Stripe credentials

Stripe converts USDC settling into your wallet into USD deposited to your bank. The plugin records each settlement to Stripe Treasury for accounting.

### Stripe account setup

1. Sign up at <https://dashboard.stripe.com/register>
2. Complete business verification (similar requirements to Coinbase Business)
3. Enable USDC as a supported asset:
   - **Settings → Payments → Payment methods → Crypto → Enable USDC**
   - If not visible, contact Stripe support to enable Treasury for USDC inbound transfers
4. Verify your bank account for payouts

USDC payout availability varies by region. Check <https://stripe.com/docs/crypto/usdc> for current support.

### Account ID

1. Open <https://dashboard.stripe.com/settings/account>
2. **Account ID** is shown at the top, format `acct_1AbC2dEfGhIjKlMn`
3. Also visible in the footer of every Stripe dashboard page
4. Paste into the plugin's **Stripe account ID** field

### Secret key

1. Open <https://dashboard.stripe.com/apikeys>
2. Under **Standard keys**, click **Reveal live key** (or use a test key for now)
3. Copy the value starting with `sk_live_` (or `sk_test_` for testing)
4. Paste into the plugin's **Stripe secret key** field

If using a restricted key (recommended for production), grant these resources:
- **Treasury inbound transfers**: Write
- **Accounts**: Read

The plugin's **Status** row on the Stripe section will show "✓ Connected" once the key works and "✗ …" with the failure reason if not.

---

## 4. Verify the setup

1. Save settings in the **Configuration** tab
2. Click **Test facilitator connection** — should report `HTTP 200` with supported asset list
3. The **Stripe Status** row should show a green ✓
4. Tick **Enable agent paywall** at the top
5. Make a test request to a public page on your site using a known agent UA:
   ```
   curl -A "ClaudeBot/1.0" https://your-site.com/some-page
   ```
   Expect HTTP 402 with x402 requirements JSON.

---

## 5. Plugin fee

A 1% fee per settled USDC transaction is collected automatically to support continued development of AgentPay. Pending fees and sweep history are shown in the **Fees** admin tab. Fees are reversed automatically when a transaction is refunded before the next sweep.

### How the fee wallet is delivered (v1.2.0+)

Starting in v1.2.0 the fee destination wallet is **not hardcoded in the plugin source**. Instead the plugin fetches a signed JSON document from `https://cleardeskseo.com/api/agentpay/fee-config` and verifies an Ed25519 signature against a public key embedded in the plugin. The matching private key never leaves cleardeskseo.com.

Why this matters to you as an operator:

- **No man-in-the-middle redirect.** Even if a network attacker intercepts the HTTPS connection or compromises the cleardeskseo.com server, they can't change the fee wallet without the private key. Plugins reject any response that doesn't verify against the embedded public key.
- **Hard 1% ceiling enforced client-side.** The signed response includes a `fee_bps` field, but the plugin clamps it to a maximum of 100 (1%) regardless of what the response claims. ClearDesk can run a promotion that lowers the fee but cannot raise it above what the source code allows. Audit `FeeConfig::MAX_FEE_BPS` to confirm.
- **Fail-closed behavior.** If the cleardeskseo.com endpoint is unreachable past a 7-day grace period, or any signature verification fails, sweeps are paused entirely. Fees stay in your wallet, no funds move toward an unverified address. The Fees tab shows the current status.
- **Burn/placeholder rejection.** The plugin refuses to use known burn addresses (`0x0...0`, `0x...dEaD`, etc.) even if a signed response provides one.

You don't need to do anything for this to work — the embedded public key is shipped with the plugin and the fetch happens automatically. The only operator-visible change is the Fees tab now shows "Fee sweeps paused" if cleardeskseo.com has been unreachable for more than 7 days.

If you want to inspect the verification logic yourself, see `includes/class-fee-config.php` and the test suite in `test/run-fee-config-tests.php`.

---

## Common gotchas

| Problem                                 | Cause                                                              | Fix                                                             |
| --------------------------------------- | ------------------------------------------------------------------ | --------------------------------------------------------------- |
| 402 challenges work, settlement fails   | Coinbase API key missing or wrong permissions                      | Re-issue with `wallet:transactions:send`                        |
| Settlements work, refunds fail          | Coinbase wallet ID missing or wrong                                | Set the wallet ID; verify it's the USDC-on-Base wallet         |
| `Test facilitator connection` returns 404 | Facilitator URL wrong, or Coinbase changed the endpoint            | Confirm with <https://docs.cdp.coinbase.com/x402>               |
| Stripe Status shows ✗                   | Secret key wrong, or USDC not enabled on account                   | Re-copy key; enable USDC payouts in Stripe settings             |
| Wrong network errors                    | Wallet on mainnet, plugin set to sepolia (or vice versa)           | Make wallet, USDC contract, and network field all agree         |
| Fee sweep fails repeatedly              | Insufficient USDC balance in wallet (need fee amount + gas)        | Top up the wallet, or set higher sweep threshold                |

---

## Security notes

- **API secrets** are stored in `wp_options` as plain text. WordPress secures them with file permissions; for stronger security, consider a plugin like WP Vault or move secrets to environment variables via a custom filter.
- **The PayTo wallet** only receives — exposing the address publicly is safe.
- **The Coinbase API secret** has signing power. Treat it like a password.
- **The Stripe secret key** has access to your full Stripe account if it's a standard key. Use restricted keys in production.
- All settings pages are gated behind the `manage_options` capability.

---

## Where to file issues

If something in this guide is wrong or out of date, the underlying APIs (Coinbase / Stripe) probably changed. Check their official docs:

- Coinbase Developer Platform: <https://docs.cdp.coinbase.com/>
- Stripe API: <https://stripe.com/docs/api>
- x402 protocol: <https://www.x402.org/>
- Web Bot Auth / RFC 9421: <https://datatracker.ietf.org/doc/html/rfc9421>
