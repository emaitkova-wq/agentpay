# ClearWallet by ClearDesk SEO — Setup Guide

Open source AI agent payment processor. Charge AI agents in USDC for access to your content via the x402 protocol, with gasless Coinbase settlement. This guide explains where to find each credential ClearWallet needs.

You'll set up two things:

1. **A Base USDC wallet (yours)** — where agent payments arrive
2. **Coinbase Developer Platform (CDP) credentials** — to verify and settle x402 payments, and to push refunds and fee sweeps gaslessly

Cashing out USDC to your bank is a separate step you control — see [Cashing out](#cashing-out) below.

---

## Quick path (recommended)

The plugin can **create a Base wallet for you from inside WordPress admin** — no Node, no CDP CLI, no SDK required.

1. Sign up at <https://portal.cdp.coinbase.com/> (about 2 minutes — email + verification)
2. In the CDP Portal, create a **Secret API Key** (API Keys → Secret API Keys → Create). Under **Advanced Settings**, either ECDSA or Ed25519 works — the plugin supports both. Copy the **API Key ID** (`name`) and **API Key Secret** (`privateKey`).
3. In the CDP Portal, generate a **Wallet Secret** (Wallet Secrets → Generate) — **shown ONCE; copy immediately**.
4. In WordPress admin, go to **Tools → ClearWallet → Setup**
5. Paste the three values into the form, click **Connect & create wallet**
6. The plugin provisions a new EVM account on Base via the CDP REST API and stores the receiving address

Total time: about 5 minutes from zero. The plugin handles JWT signing, account creation, and address storage automatically.

If you'd rather use a wallet you already own, the Setup tab also has an **"I already have a wallet"** flow — paste the same three credentials plus the existing `0x…` address, and the plugin verifies it can sign for that address before saving.

---

## Quick reference

| Setup-tab field    | Format                                            | Source                                                  |
| ------------------ | ------------------------------------------------- | ------------------------------------------------------- |
| API Key ID         | `organizations/…/apiKeys/…` (or a bare UUID)      | CDP portal → API Keys → Secret API Keys (`name` field)  |
| API Key Secret     | PEM block, or base64 string                       | Downloaded with the API key (`privateKey` field)        |
| Wallet Secret      | base64 of a PKCS#8 EC key (~ shown once)          | CDP portal → Wallet Secrets → Generate                  |
| Receiving address  | `0x` + 40 hex chars                               | Auto-filled when the plugin provisions; or your own     |

| Config-tab field   | Format                                            | Notes                                                   |
| ------------------ | ------------------------------------------------- | ------------------------------------------------------- |
| Network            | `base` or `base-sepolia`                          | `base` for live, `base-sepolia` for testing             |
| USDC contract      | `0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913`      | Pre-filled for Base mainnet                             |
| Facilitator URL    | *(leave blank)*                                   | Blank = auto-select by network (see below)              |

The plugin accepts the API Key Secret and Wallet Secret in flexible encodings — PEM with real or escaped newlines, base64, or base64url — so a copy/paste from any portal view works.

---

## 1. Your Base USDC wallet

You need a wallet on the Base network that can receive USDC. Two reasonable paths.

### Option A: CDP Server Wallet (recommended)

Best for: full automation, including gasless refunds and fee sweeps. This is what the Quick path above creates.

1. Sign up at <https://portal.cdp.coinbase.com/>
2. Create a project (any name)
3. Use the plugin's **Setup → Connect & create wallet** flow (Quick path above), or create the wallet in the portal manually
4. Server wallets are signed by Coinbase's secure enclave using your Wallet Secret — you don't hold a seed phrase, and the plugin can sign transfer authorizations on your behalf

Because the plugin can sign for a CDP Server Wallet, **gasless refunds and fee sweeps work automatically** — see [How transfers work](#how-transfers-work).

### Option B: Self-custody (Coinbase Wallet, MetaMask, Rainbow)

Best for: developers who already hold a Base wallet and only want to *receive* payments.

1. Install Coinbase Wallet (extension or mobile) or MetaMask
2. Add the **Base** network if not present (chain ID 8453, RPC `https://mainnet.base.org`)
3. Copy your wallet address into the **receiving address** field via the "I already have a wallet" flow
4. **Save your seed phrase securely.** No seed phrase = no funds, ever.

Note: self-custody wallets that aren't CDP-managed **cannot sign the gasless transfer authorizations** the plugin uses for refunds and fee sweeps. Payments still arrive fine, but automated refunds/sweeps require a CDP Server Wallet (Option A). The plugin will report a clear error if it can't sign for the configured address.

---

## 2. Coinbase Developer Platform credentials

All credentials are entered in **Tools → ClearWallet → Setup** and stored encrypted at rest (AES-256, key derived from your WordPress salts). The plugin uses them to:

- Sign the bearer JWT for CDP API calls
- Sign EIP-3009 transfer authorizations for gasless refunds and fee sweeps
- Authenticate to the CDP facilitator on mainnet

### API Key ID and Secret

1. Go to <https://portal.cdp.coinbase.com/>
2. **API Keys → Secret API Keys → Create API key**
3. Under **Advanced Settings**, pick ECDSA or Ed25519 (both supported by the plugin)
4. Download the JSON. It contains:
   - `name` → paste into **API Key ID** (often `organizations/…/apiKeys/…`; paste the entire value)
   - `privateKey` → paste into **API Key Secret** (PEM for ECDSA, base64 for Ed25519)

Treat the secret like a password. Anyone who has it plus the Wallet Secret can move funds from the associated wallet.

### Wallet Secret

1. In the CDP Portal, go to **Wallet Secrets → Generate**
2. **Copy it immediately** — it's shown only once
3. Paste into the **Wallet Secret** field

The Wallet Secret is a base64-encoded EC P-256 key (~138 bytes decoded). It authorizes signing operations on your server wallet — provisioning, and the EIP-3009 transfer authorizations for refunds and sweeps.

### Facilitator URL

**Leave this blank.** The plugin auto-selects the right facilitator by network:

- **Base Sepolia (testnet)** → `https://www.x402.org/facilitator` — free, no auth required
- **Base mainnet** → `https://api.cdp.coinbase.com/platform/v2/x402` — authenticated with a bearer JWT signed by your CDP API Key Secret. Free for the first 1000 transactions/month, then $0.001/tx.

Only set an explicit URL if you run your own facilitator (e.g. for a private network). The **Test facilitator connection** button hits the `/supported` endpoint on the resolved URL.

---

## How transfers work

ClearWallet moves USDC in three situations, all **gasless** — your wallet never needs ETH:

- **Incoming payments**: the agent signs an EIP-3009 authorization; the agent's facilitator broadcasts it and pays gas. USDC arrives in your wallet.
- **Refunds** (404s, 5xx errors, disputes): the plugin asks CDP to sign an EIP-3009 authorization as your wallet, then the facilitator broadcasts it, paying gas. USDC returns to the agent.
- **Fee sweeps** (the 1% fee): same gasless flow — the plugin signs as your wallet, the facilitator broadcasts, USDC moves to the ClearDesk fee wallet.

Because every outbound transfer uses `transferWithAuthorization` (EIP-3009) relayed through the facilitator, **you never need to hold ETH for gas**. On mainnet the only cost is the facilitator's per-call fee (free up to 1000/month, then $0.001 each) — far less than gas would be, and you don't have to pre-fund the wallet with ETH.

---

## Cashing out

ClearWallet's job ends when you have USDC in a wallet you control. Converting that USDC to USD in your bank is a separate step you handle yourself — the plugin deliberately does not broker fiat conversion (doing so would make it a regulated money transmitter). Common options:

1. **Coinbase** — transfer USDC from your wallet to your Coinbase account, sell for USD, withdraw to your bank via ACH. Cleanest path if you used a CDP wallet, since you already have a Coinbase login.
2. **Stripe stablecoin payouts** — if available in your region, connect your wallet to Stripe directly and configure auto-conversion to your bank. This is a relationship between you and Stripe; ClearWallet is not involved.
3. **Fintech on-ramps/off-ramps** — MoonPay, Ramp, Transak all convert USDC → USD with bank transfers.
4. **Spend USDC directly** — Coinbase Card and similar let you spend USDC like a debit card, skipping conversion entirely.

Pick whatever fits your jurisdiction and volume. Many operators who pay contractors or buy tools in USDC simply skip conversion.

---

## 3. Verify the setup

1. In **Setup**, confirm the wallet shows a green "Connected" state with a receiving address
2. In **Configuration**, set the **Network** (start with `base-sepolia` for testing) and leave **Facilitator URL** blank
3. Click **Test facilitator connection** — should report `HTTP 200` with a supported-asset list
4. Tick **Enable agent paywall** at the top
5. Make a test request to a public page using a known agent UA:
   ```
   curl -A "ClaudeBot/1.0" https://your-site.com/some-page
   ```
   Expect HTTP 402 with x402 requirements JSON, including your wallet as `payTo`.

For a full testnet payment test (signing a payment as a simulated agent and settling it on Base Sepolia), see the testing notes in the project README.

---

## 4. Plugin fee

A 1% fee per settled USDC transaction is collected automatically to support continued development. Pending fees and sweep history are shown in the **Fees** admin tab. Fees are reversed automatically when a transaction is refunded before the next sweep. Fee sweeps are **gasless** — see [How transfers work](#how-transfers-work).

### How the fee wallet is delivered

The fee destination wallet is **not hardcoded in the plugin source**. The plugin fetches a signed JSON document from `https://cleardeskseo.com/api/clearwallet/fee-config` and verifies an Ed25519 signature against a public key embedded in the plugin. The matching private key never leaves cleardeskseo.com.

Why this matters to you as an operator:

- **No man-in-the-middle redirect.** Even if a network attacker intercepts the HTTPS connection or compromises the cleardeskseo.com server, they can't change the fee wallet without the private key. The plugin rejects any response that doesn't verify against the embedded public key.
- **Hard 1% ceiling enforced client-side.** The signed response includes a `fee_bps` field, but the plugin clamps it to a maximum of 100 (1%) regardless of what the response claims. ClearDesk can run a promotion that lowers the fee but cannot raise it. Audit `FeeConfig::MAX_FEE_BPS` to confirm.
- **Fail-closed behavior.** If the endpoint is unreachable past a 7-day grace period, or any signature verification fails, sweeps pause entirely. Fees stay in your wallet; no funds move toward an unverified address.
- **Burn/placeholder rejection.** The plugin refuses known burn addresses (`0x0…0`, `0x…dEaD`, etc.) even if a signed response provides one.

You don't need to do anything for this to work — the embedded public key ships with the plugin and the fetch happens automatically.

---

## Common gotchas

| Problem                                   | Cause                                                          | Fix                                                                |
| ----------------------------------------- | ------------------------------------------------------------- | ----------------------------------------------------------------- |
| 402 challenges work, settlement fails     | Facilitator rejected the payload                              | Check the error in the logs — it now includes the facilitator's reason |
| Refunds or sweeps fail                    | Wallet isn't CDP-managed, so the plugin can't sign for it     | Use a CDP Server Wallet (Section 1, Option A)                      |
| `Test facilitator connection` returns 404 | Custom facilitator URL is wrong                               | Leave the field blank to auto-select; or confirm your custom URL   |
| Wrong network errors                      | Wallet/USDC/network fields disagree                          | Make the network field, USDC contract, and wallet all match       |
| Mainnet settlement returns 401            | CDP credentials missing or wrong for the facilitator         | Re-paste API Key ID + Secret in Setup; ensure the key is enabled  |
| `missing_eip712_domain` in logs           | (shouldn't happen in v1.4.0+) older build without `extra`     | Update to the latest plugin version                               |

Note: you do **not** need ETH in your wallet. All transfers are gasless via EIP-3009 — if you see an "insufficient funds for gas" error, you're on an outdated build.

---

## Security notes

- **API Key Secret and Wallet Secret** are stored in `wp_options` encrypted at rest (AES-256-CBC, key derived from `wp_salt`). This keeps them out of plain DB dumps and accidental backups. It is not a substitute for a hardware vault.
- **The receiving address** only receives — exposing it publicly is safe.
- **The Wallet Secret** authorizes signing. Treat it like a password; if compromised, rotate it in the CDP portal (which invalidates the old one immediately) and reconnect in Setup.
- All settings pages are gated behind the `manage_options` capability.

---

## Where to file issues

If something in this guide is wrong or out of date, the underlying CDP APIs probably changed. Check the official docs:

- Coinbase Developer Platform: <https://docs.cdp.coinbase.com/>
- x402 protocol: <https://www.x402.org/>
- Web Bot Auth / RFC 9421: <https://datatracker.ietf.org/doc/html/rfc9421>
