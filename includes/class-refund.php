<?php
namespace AgentPay;

if (!defined('ABSPATH')) { exit; }

class Refund {

    protected static $shutdown_registered = false;
    protected static $request_start = null;

    public static function register_shutdown() {
        if (self::$shutdown_registered) { return; }
        self::$shutdown_registered = true;
        self::$request_start = microtime(true);
        register_shutdown_function([__CLASS__, 'maybe_refund_shutdown']);
    }

    public static function maybe_refund_404() {
        if (!is_404()) { return; }
        if (!Gate::was_paid()) { return; }
        if (!Installer::setting('auto_refund_404', 1)) { return; }

        $current = Gate::current();
        self::initiate(
            $current['tx_hash'],
            '404',
            'Resource not found at ' . ($_SERVER['REQUEST_URI'] ?? '')
        );
    }

    public static function maybe_refund_shutdown() {
        $current = Gate::current();
        if (!$current || empty($current['tx_hash'])) { return; }

        $code = http_response_code();

        if ($code >= 500 && $code < 600 && Installer::setting('auto_refund_5xx', 1)) {
            self::initiate($current['tx_hash'], '5xx', "Server returned {$code}");
            return;
        }

        $elapsed_ms = (microtime(true) - (self::$request_start ?: microtime(true))) * 1000;
        $threshold  = (int) Installer::setting('timeout_threshold_ms', 30000);
        if ($elapsed_ms > $threshold && Installer::setting('auto_refund_timeout', 1)) {
            self::initiate($current['tx_hash'], 'timeout',
                sprintf('Response time %dms exceeded %dms threshold', $elapsed_ms, $threshold));
        }
    }

    public static function initiate(string $tx_hash, string $reason, string $details = '') {
        global $wpdb;
        $tbl = $wpdb->prefix . 'agentpay_transactions';

        $tx = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$tbl} WHERE tx_hash = %s", $tx_hash
        ), ARRAY_A);

        if (!$tx) { return new \WP_Error('agentpay_no_tx', 'Transaction not found'); }
        if ($tx['status'] !== 'paid') {
            return new \WP_Error('agentpay_already_processed', 'Already ' . $tx['status']);
        }
        if (empty($tx['agent_address'])) {
            return self::mark($tx_hash, 'refund_pending', $reason,
                'Cannot auto-refund: agent address missing');
        }

        $wpdb->update($tbl,
            ['status' => 'refunding', 'refund_reason' => $reason, 'updated_at' => current_time('mysql', true)],
            ['tx_hash' => $tx_hash]
        );

        $result = Facilitator::refund(
            $tx_hash,
            $tx['agent_address'],
            (int) $tx['amount_atomic'],
            $reason
        );

        if (is_wp_error($result)) {
            $wpdb->update($tbl,
                ['status' => 'refund_failed', 'updated_at' => current_time('mysql', true)],
                ['tx_hash' => $tx_hash]
            );
            Abuse::record($tx['agent_id'], 'refund_failed', $result->get_error_message());
            do_action('agentpay_refund_failed', $tx_hash, $reason, $result);
            return $result;
        }

        $wpdb->update($tbl, [
            'status'        => 'refunded',
            'refund_tx'     => $result['refund_tx'],
            'refund_reason' => $reason,
            'updated_at'    => current_time('mysql', true),
        ], ['tx_hash' => $tx_hash]);

        FeeProcessor::reverse_fee($tx_hash);

        do_action('agentpay_refunded', $tx_hash, $reason, $result['refund_tx']);
        return $result;
    }

    protected static function mark(string $tx_hash, string $status, string $reason, string $details) {
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'agentpay_transactions', [
            'status'        => $status,
            'refund_reason' => $reason,
            'updated_at'    => current_time('mysql', true),
        ], ['tx_hash' => $tx_hash]);
        return ['status' => $status, 'details' => $details];
    }
}
