# Deployment Map — cleardeskseo.com side

The plugin's `class-fee-config.php` fetches a signed JSON document from
`https://cleardeskseo.com/api/agentpay/fee-config` and verifies an Ed25519
signature against an embedded public key. This document maps out where each
piece lives on the server side and how rotation works.

## File layout

### Public — served over HTTPS

```
/var/www/cleardeskseo.com/public_html/
└── api/
    └── agentpay/
        └── fee-config        ← the signed JSON file (~300 bytes, no extension)
```

That's the only file the plugin ever touches on cleardeskseo.com. It's a static
file — no PHP, no backend logic, no database. Just bytes served with
`Content-Type: application/json`.

### Private — NEVER web-accessible

```
/home/cleardesk/              ← or /etc/cleardesk/, or wherever you keep deploy secrets
├── secrets/
│   └── fee-config.key        ← Ed25519 private key (base64-encoded 64 bytes)
│                                Mode 0600, owned by the deploy user, NEVER web-readable
└── tools/
    └── sign-fee-config.php   ← copy of agentpay/tools/sign-fee-config.php from the plugin
```

The signing tool itself is not sensitive — only the private key is. Mode the
key file `chmod 600 fee-config.key` so only the deploy user can read it. Make
sure it's outside the web root and outside any directory the web server has
access to.

## Web server config

### Apache (`.htaccess` in `public_html/api/agentpay/`)

```apache
<Files "fee-config">
    Header set Content-Type "application/json"
    Header set Cache-Control "public, max-age=3600"
</Files>
```

If cleardeskseo.com runs WordPress, its root `.htaccess` already has the standard
`!-f / !-d` rewrite, which means a physical file at `api/agentpay/fee-config`
gets served directly without WP touching it. Nothing else needed.

### Nginx (in your server block)

```nginx
location = /api/agentpay/fee-config {
    default_type application/json;
    add_header Cache-Control "public, max-age=3600";
}
```

## Rotation workflow

When you need to update the fee wallet (compromise, custodian change, scheduled
key rotation, anything):

```bash
# On the deploy host (or your laptop — see "Safer alternative" below)
php /home/cleardesk/tools/sign-fee-config.php \
  --wallet 0xYourNewBaseUsdcAddress \
  --bps 100 \
  --ttl-days 90 \
  --private-key-file /home/cleardesk/secrets/fee-config.key \
  > /var/www/cleardeskseo.com/public_html/api/agentpay/fee-config

# Verify what's now being served
curl -s https://cleardeskseo.com/api/agentpay/fee-config | jq .
```

Plugins pick up the new wallet within 24 hours (the default cache TTL), or
immediately if an operator clicks "Refresh fee config" in the admin Fees tab.

## Safer alternative — sign on your laptop, upload only the JSON

If you don't want the private key on the cleardeskseo.com server at all, do
the signing on your local machine and SCP the JSON up:

```
your-laptop:
└── ~/cleardesk/
    ├── secrets/
    │   └── fee-config.key
    └── tools/
        └── sign-fee-config.php
```

```bash
# Locally
php ~/cleardesk/tools/sign-fee-config.php \
  --wallet 0xYourNewBaseUsdcAddress \
  --private-key-file ~/cleardesk/secrets/fee-config.key \
  > /tmp/fee-config.json

# Upload
scp /tmp/fee-config.json deploy@cleardeskseo.com:/var/www/cleardeskseo.com/public_html/api/agentpay/fee-config
```

This is the safer model — the private key never touches the production web
server, so a server compromise can't leak it. The tradeoff is you can't rotate
from your phone; you need access to the laptop where the key lives.

## One-time setup (do this once, before publishing v1.2.0)

```bash
# On a trusted machine — laptop or air-gapped
php sign-fee-config.php --generate-keys
```

That prints both halves of a fresh Ed25519 keypair. Then:

1. Paste the **public** key into `class-fee-config.php` → `CLEARDESK_PUBLIC_KEY` constant
2. Build a new plugin zip with that change
3. Save the **private** key to `/home/cleardesk/secrets/fee-config.key` (or wherever) with mode 0600
4. Generate your first signed config (see "Rotation workflow" above) and upload it
5. Publish the new plugin zip

After this, day-to-day rotation only requires steps in "Rotation workflow" —
no plugin release needed.

## Security checklist

- [ ] Private key is mode `0600`, owned by deploy user (not web user)
- [ ] Private key path is outside the web root
- [ ] Private key is in `.gitignore` (don't commit by accident)
- [ ] At least one offline backup of the private key exists
- [ ] cleardeskseo.com forces HTTPS (no HTTP fallback for /api/)
- [ ] The endpoint URL in `FeeConfig::ENDPOINT_URL` uses `https://`
- [ ] Test the rotation workflow once before you need to rotate for real

## What happens if the endpoint goes down

The plugin caches the last good config for 24 hours by default and continues
serving it from cache. After 24 hours it tries to refetch; if that fails it
falls back to the cached value for up to 7 more days (the grace period).
After 7 days with no successful fetch, the plugin pauses sweeps entirely —
fees still accumulate in operator wallets, but no USDC moves toward an
unverified address. The Fees admin tab shows a warning during the grace
period and a stronger warning once sweeps are paused.

So a brief outage (a few hours, a day) is invisible to operators. A multi-week
outage pauses fee collection but doesn't affect the rest of the plugin.

## Why no .json extension on the endpoint

The plugin fetches `/api/agentpay/fee-config` (no extension) intentionally —
it keeps the URL clean, sidesteps any conflict with WordPress upload handlers
that look at `.json` extensions, and the `Content-Type` header is set
explicitly via the web server config anyway. The file on disk has no
extension; serve it as-is.
