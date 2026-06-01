<?php
namespace AgentPay;

if (!defined('ABSPATH')) { exit; }

class Stripe {

    public static function maybe_record_revenue(string $tx_hash, $amount_atomic) {
        if (!Installer::setting('stripe_record_revenue', 1)) { return; }
        if (!self::is_configured()) { return; }

        wp_schedule_single_event(time() + 5, 'agentpay_stripe_record', [$tx_hash, (int) $amount_atomic]);
    }

    public static function record(string $tx_hash, int $amount_atomic) {
        if (!self::is_configured()) { return; }

        $secret  = Installer::setting('stripe_secret_key');
        $account = Installer::setting('stripe_account_id');

        $resp = wp_remote_post('https://api.stripe.com/v1/treasury/inbound_transfers', [
            'timeout' => 15,
            'headers' => [
                'Authorization' => 'Bearer ' . $secret,
                'Stripe-Account' => $account,
                'Content-Type'  => 'application/x-www-form-urlencoded',
            ],
            'body' => http_build_query([
                'amount'      => self::atomic_to_cents($amount_atomic),
                'currency'    => 'usd',
                'description' => 'AgentPay USDC settlement ' . substr($tx_hash, 0, 16),
                'metadata'    => [
                    'tx_hash' => $tx_hash,
                    'source'  => 'agentpay',
                    'asset'   => 'USDC',
                    'network' => Installer::setting('network', 'base'),
                ],
            ]),
        ]);

        if (is_wp_error($resp)) { return; }
        $code = wp_remote_retrieve_response_code($resp);
        if ($code < 200 || $code >= 300) { return; }

        global $wpdb;
        $wpdb->update($wpdb->prefix . 'agentpay_transactions',
            ['stripe_logged' => 1, 'updated_at' => current_time('mysql', true)],
            ['tx_hash' => $tx_hash]
        );
    }

    public static function is_configured() {
        return Installer::setting('stripe_secret_key') && Installer::setting('stripe_account_id');
    }

    public static function status_summary() {
        if (!self::is_configured()) {
            return ['ok' => false, 'msg' => 'Stripe not configured'];
        }
        $secret = Installer::setting('stripe_secret_key');
        $resp = wp_remote_get('https://api.stripe.com/v1/accounts/' .
            urlencode(Installer::setting('stripe_account_id')), [
            'headers' => ['Authorization' => 'Bearer ' . $secret],
            'timeout' => 10,
        ]);
        if (is_wp_error($resp)) {
            return ['ok' => false, 'msg' => $resp->get_error_message()];
        }
        $code = wp_remote_retrieve_response_code($resp);
        if ($code === 200) {
            $body = json_decode(wp_remote_retrieve_body($resp), true);
            return [
                'ok'           => true,
                'payouts_enabled' => $body['payouts_enabled'] ?? false,
                'business_name'   => $body['business_profile']['name'] ?? '(unnamed)',
            ];
        }
        return ['ok' => false, 'msg' => "Stripe returned {$code}"];
    }

    protected static function atomic_to_cents(int $atomic) {
        return (int) round($atomic / 10000);
    }
}

add_action('agentpay_stripe_record', ['\AgentPay\Stripe', 'record'], 10, 2);
