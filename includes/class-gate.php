<?php
namespace ClearWallet;

if (!defined('ABSPATH')) { exit; }

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- transactional plugin; wp_cache would yield stale reads of in-flight transactions/disputes/fee sweeps. Hot paths already use $wpdb->prepare() for all user data.
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$tbl}/{$prefix} interpolation is the plugin's own table name ($wpdb->prefix . 'clearwallet_*'), not user input. WP 6.0 baseline can't use the %i identifier placeholder added in 6.2.
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter -- same rationale as InterpolatedNotPrepared above.


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
            'dispute_url' => rest_url('clearwallet/v1/dispute'),
        ];
        if ($reason) { $body['error'] = $reason; }

        status_header(402);
        nocache_headers();
        header('Content-Type: application/json');
        // The x402 spec treats the JSON body as the canonical payment-required
        // signal. We advertise the scheme through a custom header rather than
        // WWW-Authenticate because RFC 7235 reserves WWW-Authenticate for 401
        // responses, and some strict servers (LiteSpeed on Hostinger has been
        // observed doing this) will rewrite the status code from 402 to 401
        // when they see a WWW-Authenticate header on a non-401 response.
        // Using X-Accept-Payment preserves discoverability without triggering
        // that pairing rule, so the 402 status survives intact.
        header('X-Accept-Payment: x402 realm="' . esc_attr(wp_parse_url(home_url(), PHP_URL_HOST)) . '"');
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
        $path = self::server_path();
        if (strpos($path, '/wp-json/') !== false || strpos($path, '?rest_route=') !== false) {
            $action = 'view';
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public agent-detection path, not form processing
        if (!empty($_GET['s'])) { $action = 'search'; }
        $rates = apply_filters('clearwallet_rate_for_request', $rates, $path);
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
        if (!isset($_SERVER[$key])) { return null; }
        // $_SERVER values arrive raw from PHP; sanitize and unslash to make safe
        // for downstream use in URLs, log messages, and signature inputs.
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized on the following line via sanitize_text_field()
        $value = wp_unslash($_SERVER[$key]);
        return is_string($value) ? sanitize_text_field($value) : null;
    }

    /**
     * Safely read $_SERVER['REQUEST_URI']. Returns a URL-safe string suitable
     * for use in signature inputs, refund details, and rate-card matching.
     * Strips any control characters, null bytes, or anything that could be
     * used for header/log injection.
     */
    protected static function server_path() {
        if (!isset($_SERVER['REQUEST_URI'])) { return '/'; }
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized via esc_url_raw() on the next line
        $raw = wp_unslash($_SERVER['REQUEST_URI']);
        if (!is_string($raw) || $raw === '') { return '/'; }
        // esc_url_raw applied to a path returns the path safely; for inputs
        // that are paths (not full URLs) it strips dangerous characters.
        $clean = esc_url_raw($raw);
        return $clean !== '' ? $clean : '/';
    }

    /**
     * Safely read $_SERVER['HTTP_HOST']. Allows only the chars valid in a
     * host header (letters, digits, dots, hyphens, optional :port).
     */
    protected static function server_host() {
        if (!isset($_SERVER['HTTP_HOST'])) { return ''; }
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized via preg_replace whitelist on the next line
        $raw = wp_unslash($_SERVER['HTTP_HOST']);
        if (!is_string($raw)) { return ''; }
        // Reject anything outside the RFC-valid host charset to prevent
        // header injection. preg_replace is safer here than sanitize_text_field
        // because sanitize_text_field collapses whitespace but allows other chars.
        return preg_replace('/[^A-Za-z0-9\-\.\:]/', '', $raw);
    }

    protected static function current_url() {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        return $scheme . '://' . self::server_host() . self::server_path();
    }

    protected static function is_internal_request() {
        if (defined('DOING_CRON') && DOING_CRON) { return true; }
        if (defined('DOING_AJAX') && DOING_AJAX) { return true; }
        if (defined('WP_CLI') && WP_CLI) { return true; }
        $path = self::server_path();
        if (strpos($path, '/wp-json/clearwallet/') !== false) { return true; }
        return false;
    }

    protected static function record_transaction(string $tx_hash, array $detection, $agent_addr, array $req) {
        global $wpdb;
        $now = current_time('mysql', true);
        $wpdb->insert($wpdb->prefix . 'clearwallet_transactions', [
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
