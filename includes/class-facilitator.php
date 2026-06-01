<?php
namespace AgentPay;

if (!defined('ABSPATH')) { exit; }

class Facilitator {

    public static function verify($payment_payload, array $requirements) {
        return self::call('/verify', [
            'paymentPayload'      => $payment_payload,
            'paymentRequirements' => $requirements,
        ]);
    }

    public static function settle($payment_payload, array $requirements) {
        return self::call('/settle', [
            'paymentPayload'      => $payment_payload,
            'paymentRequirements' => $requirements,
        ], true);
    }

    public static function transfer($destination, $amount_atomic, $idempotency_key, array $metadata = []) {
        $key = Installer::setting('coinbase_api_key');
        $sec = Installer::setting('coinbase_api_secret');
        $wal = Installer::setting('coinbase_wallet_id');

        if (!$key || !$sec || !$wal) {
            return new \WP_Error('agentpay_unconfigured',
                'Coinbase API credentials and wallet ID required for transfers.');
        }

        $body = [
            'wallet_id'       => $wal,
            'asset'           => 'USDC',
            'network'         => Installer::setting('network', 'base'),
            'destination'     => $destination,
            'amount'          => self::atomic_to_decimal($amount_atomic),
            'idempotency_key' => $idempotency_key,
            'metadata'        => array_merge(['site' => home_url()], $metadata),
        ];

        $resp = wp_remote_post('https://api.coinbase.com/v2/transactions', [
            'timeout' => 20,
            'headers' => [
                'Content-Type'  => 'application/json',
                'CB-ACCESS-KEY' => $key,
                'CB-VERSION'    => '2024-01-01',
                'Authorization' => 'Bearer ' . self::sign_coinbase_request('POST', '/v2/transactions', $body, $sec),
            ],
            'body' => wp_json_encode($body),
        ]);

        if (is_wp_error($resp)) { return $resp; }

        $code = wp_remote_retrieve_response_code($resp);
        $data = json_decode(wp_remote_retrieve_body($resp), true);

        if ($code >= 400 || empty($data['data']['id'])) {
            return new \WP_Error('agentpay_transfer_failed',
                $data['errors'][0]['message'] ?? 'Transfer request failed', $data);
        }

        return ['tx_hash' => $data['data']['id'], 'raw' => $data];
    }

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

    protected static function call($path, array $body, $authenticated = false) {
        $base = rtrim(Installer::setting('facilitator_url',
            'https://api.cdp.coinbase.com/platform/v2/x402'), '/');

        $args = [
            'timeout' => 15,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode($body),
        ];

        if ($authenticated) {
            $key = Installer::setting('coinbase_api_key');
            if ($key) { $args['headers']['Authorization'] = 'Bearer ' . $key; }
        }

        $resp = wp_remote_post($base . $path, $args);
        if (is_wp_error($resp)) { return $resp; }

        $code = wp_remote_retrieve_response_code($resp);
        $data = json_decode(wp_remote_retrieve_body($resp), true);

        if ($code >= 400) {
            return new \WP_Error('agentpay_facilitator_error',
                $data['error'] ?? 'Facilitator returned ' . $code, $data);
        }
        return $data ?: [];
    }

    protected static function sign_coinbase_request($method, $path, array $body, $secret) {
        $ts = time();
        $message = $ts . $method . $path . wp_json_encode($body);
        return $ts . '.' . hash_hmac('sha256', $message, $secret);
    }

    public static function atomic_to_decimal($atomic) {
        return number_format($atomic / 1000000, 6, '.', '');
    }

    public static function decimal_to_atomic($dec) {
        return (int) round(((float) $dec) * 1000000);
    }
}
