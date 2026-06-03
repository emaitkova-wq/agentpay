<?php
namespace ClearWallet;

if (!defined('ABSPATH')) { exit; }

/**
 * x402 facilitator client.
 *
 * Submits agent-signed payment payloads to an x402 facilitator for
 * verification and settlement, and performs gasless USDC transfers (fee
 * sweeps and refunds) using the EIP-3009 transferWithAuthorization
 * primitive.
 *
 * Two facilitators are supported, selected automatically by network:
 *
 *   - base-sepolia (testnet) -> https://www.x402.org/facilitator
 *       Free, no API keys required.
 *
 *   - base (mainnet) -> https://api.cdp.coinbase.com/platform/v2/x402
 *       Coinbase Developer Platform. Requires a Bearer JWT signed with the
 *       operator's CDP API Key Secret on /verify and /settle. Free tier of
 *       1000 transactions/month, then $0.001/tx.
 *
 * An explicit facilitator_url setting overrides the automatic selection.
 */
class Facilitator {

    public static function verify($payment_payload, array $requirements) {
        return self::call('/verify', [
            'x402Version'         => 2,
            'paymentPayload'      => $payment_payload,
            'paymentRequirements' => $requirements,
        ], true);
    }

    public static function settle($payment_payload, array $requirements) {
        return self::call('/settle', [
            'x402Version'         => 2,
            'paymentPayload'      => $payment_payload,
            'paymentRequirements' => $requirements,
        ], true);
    }

    /**
     * Gasless USDC transfer from the operator's wallet to a destination.
     *
     * Flow (no ETH needed on the operator wallet):
     *   1. CDP signs an EIP-3009 transferWithAuthorization as the operator.
     *   2. We wrap that signature in an x402 payment envelope.
     *   3. The facilitator broadcasts transferWithAuthorization() on USDC,
     *      paying gas itself, and returns the on-chain tx hash.
     *
     * Used by fee sweeps (destination = ClearDesk fee wallet) and refunds
     * (destination = agent's payer address).
     *
     * @param string $destination     0x... recipient address.
     * @param int    $amount_atomic   USDC atomic units (6 decimals).
     * @param string $idempotency_key Accepted for call-site compatibility;
     *                                the EIP-3009 nonce provides on-chain
     *                                idempotency.
     * @param array  $metadata        Accepted for compatibility; not sent.
     * @return array|\WP_Error { tx_hash: string, raw: array } or error.
     */
    public static function transfer($destination, $amount_atomic, $idempotency_key = '', array $metadata = []) {
        $from = get_option('clearwallet_receiving_address', '');
        if (empty($from)) {
            return new \WP_Error('clearwallet_no_wallet',
                'No receiving wallet is configured. Connect CDP in ClearWallet → Setup before transferring.');
        }

        $client = CdpClient::from_stored_credentials();
        if (is_wp_error($client)) {
            return $client;
        }

        $network = Installer::setting('network', 'base');
        $caip2   = CdpClient::to_caip2($network);

        // 1. Sign the EIP-3009 authorization as the operator (gasless). The
        //    signer uses the INTERNAL network name to pick the chainId + the
        //    EIP-712 domain ("USD Coin" mainnet / "USDC" testnet).
        $signed = $client->sign_eip3009_authorization($from, $destination, (int) $amount_atomic, $network);
        if (is_wp_error($signed)) {
            return $signed;
        }

        // 2. Build payment requirements describing what the signature
        //    authorizes. The facilitator validates the two match. x402 v2
        //    requires the CAIP-2 network, both `amount` and `maxAmountRequired`,
        //    and `mimeType` + `outputSchema` on every requirement.
        $requirements = [
            'scheme'            => 'exact',
            'network'           => $caip2,
            'asset'             => CdpClient::usdc_contract($network),
            'payTo'             => $destination,
            'amount'            => (string) $amount_atomic,
            'maxAmountRequired' => (string) $amount_atomic,
            'resource'          => home_url('/clearwallet-internal/transfer'),
            'description'       => 'ClearWallet gasless transfer (sweep or refund)',
            'mimeType'          => 'application/json',
            'outputSchema'      => null,
            'maxTimeoutSeconds' => 60,
            'extra'             => [
                'name'    => ('base-sepolia' === $network) ? 'USDC' : 'USD Coin',
                'version' => '2',
            ],
        ];

        // 3. Build the x402 v2 payment envelope around the signed authorization.
        //    v2 requires x402Version 2, the CAIP-2 network, and `accepted` set
        //    to the selected requirements object.
        $envelope = [
            'x402Version' => 2,
            'scheme'      => 'exact',
            'network'     => $caip2,
            'accepted'    => $requirements,
            'payload'     => [
                'signature'     => $signed['signature'],
                'authorization' => $signed['authorization'],
            ],
        ];

        // 4. Settle via the facilitator (facilitator pays gas).
        $result = self::settle($envelope, $requirements);
        if (is_wp_error($result)) {
            return $result;
        }

        $tx = isset($result['transaction']) ? $result['transaction'] : '';
        $failed = (isset($result['success']) && false === $result['success']);
        if ($failed || empty($tx)) {
            $reason = isset($result['errorReason']) ? $result['errorReason']
                : (isset($result['invalidReason']) ? $result['invalidReason']
                : 'settlement returned no transaction hash');
            return new \WP_Error('clearwallet_settle_failed',
                'Gasless transfer failed at facilitator: ' . $reason, $result);
        }

        return ['tx_hash' => $tx, 'raw' => $result];
    }

    /**
     * Gasless refund — sends USDC back to the agent's payer address.
     *
     * @param string $tx_hash       Original payment tx hash (for the idempotency tag).
     * @param string $agent_address 0x... address to refund.
     * @param int    $amount_atomic USDC atomic units.
     * @param string $reason        Free-text reason (recorded by caller).
     * @return array|\WP_Error { refund_tx, tx_hash, raw } or error.
     */
    public static function refund($tx_hash, $agent_address, $amount_atomic, $reason = '') {
        $result = self::transfer($agent_address, $amount_atomic, 'refund_' . $tx_hash, [
            'type'        => 'refund',
            'original_tx' => $tx_hash,
            'reason'      => $reason,
        ]);
        if (is_wp_error($result)) { return $result; }
        return [
            'refund_tx' => $result['tx_hash'],
            'tx_hash'   => $result['tx_hash'],
            'raw'       => $result['raw'],
        ];
    }

    /**
     * POST to a facilitator endpoint, adding CDP Bearer auth when required.
     *
     * @param string $path          '/verify' or '/settle'.
     * @param array  $body          Request body (will be JSON-encoded).
     * @param bool   $authenticated Whether to attach a CDP Bearer JWT (only
     *                              actually added when the facilitator is CDP).
     * @return array|\WP_Error Parsed JSON body, or error.
     */
    protected static function call($path, array $body, $authenticated = false) {
        $base = self::facilitator_base();

        $headers = [
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
        ];

        // The CDP facilitator requires a Bearer JWT signed with the operator's
        // CDP API Key Secret (ES256/EdDSA). The x402.org facilitator is open
        // and needs no auth, so we only build the JWT when targeting CDP.
        //
        // If the credentials are missing or the JWT can't be signed, RETURN the
        // real reason. Previously this block swallowed those errors and sent the
        // request with no Authorization header, which CDP answers with a
        // misleading "HTTP 401 Unauthorized" that hides the true cause.
        if ($authenticated && false !== strpos($base, 'api.cdp.coinbase.com')) {
            $client = CdpClient::from_stored_credentials();
            if (is_wp_error($client)) {
                return new \WP_Error('clearwallet_cdp_credentials',
                    'CDP credentials are not configured or could not be loaded: '
                    . $client->get_error_message()
                    . ' Connect your Coinbase CDP API key in ClearWallet → Setup.');
            }
            // generate_bearer_jwt builds the uri as
            // "POST api.cdp.coinbase.com/platform/v2{path}". The facilitator
            // lives under /platform/v2/x402, so prefix the path accordingly.
            $jwt = $client->generate_bearer_jwt('POST', '/x402' . $path);
            if (is_wp_error($jwt)) {
                return new \WP_Error('clearwallet_cdp_jwt',
                    'Could not sign the CDP authentication token: '
                    . $jwt->get_error_message()
                    . ' Re-check (and re-paste) your CDP API Key Secret in ClearWallet → Setup.');
            }
            $headers['Authorization'] = 'Bearer ' . $jwt;
        }

        $resp = wp_remote_post($base . $path, [
            'timeout' => 20,
            'headers' => $headers,
            'body'    => wp_json_encode($body),
        ]);
        if (is_wp_error($resp)) { return $resp; }

        $code = wp_remote_retrieve_response_code($resp);
        $raw  = wp_remote_retrieve_body($resp);
        $data = json_decode($raw, true);

        if ($code >= 400) {
            // Surface the facilitator's actual error body, not just the status.
            $msg = isset($data['error']) ? $data['error']
                 : (isset($data['invalidReason']) ? $data['invalidReason']
                 : ('Facilitator returned HTTP ' . $code));
            if (401 === $code || 403 === $code) {
                // CDP rejected the API credentials themselves (not the payment).
                $msg .= ' — Coinbase CDP rejected the API credentials. In ClearWallet -> Setup, '
                      . 'click "Test Connection"; if that also fails, re-create a CDP Secret API Key '
                      . 'and paste the matching Key ID and Secret (from the same key). Also confirm your '
                      . 'CDP account identity is verified and the project has x402 access.';
            }
            return new \WP_Error('clearwallet_facilitator_error',
                $msg . ' (HTTP ' . $code . '): ' . substr((string) $raw, 0, 300), $data);
        }
        return $data ?: [];
    }

    /**
     * Resolve the facilitator base URL. An explicit facilitator_url setting
     * wins; otherwise it's chosen by network (x402.org for testnet, CDP for
     * mainnet).
     *
     * @return string Base URL with no trailing slash.
     */
    public static function facilitator_base() {
        $configured = trim((string) Installer::setting('facilitator_url', ''));
        if ('' !== $configured) {
            return rtrim($configured, '/');
        }
        $network = Installer::setting('network', 'base');
        return ('base-sepolia' === $network)
            ? 'https://www.x402.org/facilitator'
            : 'https://api.cdp.coinbase.com/platform/v2/x402';
    }

    public static function atomic_to_decimal($atomic) {
        return number_format($atomic / 1000000, 6, '.', '');
    }

    public static function decimal_to_atomic($dec) {
        return (int) round(((float) $dec) * 1000000);
    }
}
