<?php
namespace AgentPay;

if (!defined('ABSPATH')) { exit; }

class Gate {

    protected static $current = null;

    public static function intercept($wp) {
        if (is_admin() || self::is_internal_request()) { return; }

        $detection = Detector::detect($_SERVER);
        if (empty($detection['is_agent'])) { return; }

        $abuse = Abuse::check($detection);
        if (!$abuse['ok']) {
            self::deny(429, 'Too Many Requests', ['reason' => $abuse['reason']]);
        }

        $session_claims = self::check_session_header();
        if ($session_claims) {
            Session::consume($session_claims);
            self::$current = [
                'detection' => $detection,
                'session'   => $session_claims,
                'tx_hash'   => $session_claims['tx'] ?? '',
                'started'   => microtime(true),
            ];
            return;
        }

        $payment = self::header('x-payment');
        if (!$payment) {
            self::challenge_402($detection);
        }

        $requirements = self::build_requirements();
        $verify = Facilitator::verify($payment, $requirements);
        if (is_wp_error($verify) || empty($verify['isValid'])) {
            Abuse::record($detection['agent_id'], 'failed_verify',
                is_wp_error($verify) ? $verify->get_error_message() : 'invalid');
            self::challenge_402($detection,
                is_wp_error($verify) ? $verify->get_error_message() : 'invalid payment');
        }

        $settle = Facilitator::settle($payment, $requirements);
        if (is_wp_error($settle) || empty($settle['transaction'])) {
            self::deny(402, 'Settlement failed', [
                'error' => is_wp_error($settle) ? $settle->get_error_message() : 'unknown',
            ]);
        }

        $tx_hash = $settle['transaction'];
        $agent_addr = $settle['payer'] ?? ($verify['payer'] ?? null);

        self::record_transaction($tx_hash, $detection, $agent_addr, $requirements);
        FeeProcessor::record_fee($tx_hash, (int) $requirements['maxAmountRequired']);

        $token = Session::issue($detection['agent_id'], $tx_hash);

        header('X-PAYMENT-RESPONSE: ' . base64_encode(wp_json_encode([
            'transaction' => $tx_hash,
            'network'     => $requirements['network'],
            'session'     => $token,
        ])));

        Stripe::maybe_record_revenue($tx_hash, $requirements['maxAmountRequired']);

        self::$current = [
            'detection' => $detection,
            'tx_hash'   => $tx_hash,
            'session'   => Session::verify($token),
            'started'   => microtime(true),
        ];
    }

    public static function current() { return self::$current; }

    public static function was_paid() { return !empty(self::$current['tx_hash']); }

    protected static function challenge_402(array $detection, string $reason = '') {
        $req = self::build_requirements();
        $body = [
            'x402Version' => 1,
            'accepts'     => [$req],
            'agent'       => [
                'detected'  => true,
                'operator'  => $detection['operator'] ?? 'unknown',
                'verified'  => !empty($detection['verified']),
            ],
            'dispute_url' => rest_url('agentpay/v1/dispute'),
        ];
        if ($reason) { $body['error'] = $reason; }

        status_header(402);
        nocache_headers();
        header('Content-Type: application/json');
        header('WWW-Authenticate: x402 realm="' . esc_attr(parse_url(home_url(), PHP_URL_HOST)) . '"');
        echo wp_json_encode($body);
        exit;
    }

    protected static function deny(int $code, string $msg, array $extra = []) {
        status_header($code);
        nocache_headers();
        header('Content-Type: application/json');
        echo wp_json_encode(['error' => $msg] + $extra);
        exit;
    }

    public static function build_requirements() {
        $rate = self::rate_for_request();
        return [
            'scheme'            => 'exact',
            'network'           => Installer::setting('network', 'base'),
            'maxAmountRequired' => (string) $rate,
            'resource'          => self::current_url(),
            'description'       => 'Per-request access',
            'mimeType'          => 'application/json',
            'payTo'             => Installer::setting('payto_wallet', ''),
            'maxTimeoutSeconds' => 60,
            'asset'             => Installer::setting('usdc_contract'),
            'extra'             => [
                'name'    => 'USD Coin',
                'version' => '2',
            ],
        ];
    }

    protected static function rate_for_request() {
        $rates = Installer::setting('rate_card', []);
        $action = 'page';
        $path = $_SERVER['REQUEST_URI'] ?? '/';
        if (strpos($path, '/wp-json/') !== false || strpos($path, '?rest_route=') !== false) {
            $action = 'view';
        }
        if (!empty($_GET['s'])) { $action = 'search'; }
        $rates = apply_filters('agentpay_rate_for_request', $rates, $path);
        return (int) ($rates[$action] ?? $rates['page'] ?? 1000);
    }

    protected static function check_session_header() {
        $auth = self::header('authorization');
        if (!$auth || stripos($auth, 'Bearer ') !== 0) { return null; }
        $token = trim(substr($auth, 7));
        return Session::verify($token);
    }

    protected static function header(string $name) {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return $_SERVER[$key] ?? null;
    }

    protected static function current_url() {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '/');
    }

    protected static function is_internal_request() {
        if (defined('DOING_CRON') && DOING_CRON) { return true; }
        if (defined('DOING_AJAX') && DOING_AJAX) { return true; }
        if (defined('WP_CLI') && WP_CLI) { return true; }
        $path = $_SERVER['REQUEST_URI'] ?? '';
        if (strpos($path, '/wp-json/agentpay/') !== false) { return true; }
        return false;
    }

    protected static function record_transaction(string $tx_hash, array $detection, $agent_addr, array $req) {
        global $wpdb;
        $now = current_time('mysql', true);
        $wpdb->insert($wpdb->prefix . 'agentpay_transactions', [
            'tx_hash'       => $tx_hash,
            'agent_id'      => $detection['agent_id'],
            'agent_address' => $agent_addr,
            'resource'      => $req['resource'],
            'amount_atomic' => (int) $req['maxAmountRequired'],
            'currency'      => 'USDC',
            'network'       => $req['network'],
            'status'        => 'paid',
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);
    }
}
