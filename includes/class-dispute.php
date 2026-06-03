<?php
namespace ClearWallet;

if (!defined('ABSPATH')) { exit; }

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- transactional plugin; wp_cache would yield stale reads of in-flight transactions/disputes/fee sweeps. Hot paths already use $wpdb->prepare() for all user data.
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$tbl}/{$prefix} interpolation is the plugin's own table name ($wpdb->prefix . 'clearwallet_*'), not user input. WP 6.0 baseline can't use the %i identifier placeholder added in 6.2.
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter -- same rationale as InterpolatedNotPrepared above.


class Dispute {

    public static function register_routes() {
        register_rest_route('clearwallet/v1', '/dispute', [
            [
                'methods'             => 'POST',
                'callback'            => [__CLASS__, 'submit'],
                'permission_callback' => '__return_true',
                'args' => [
                    'tx_hash'  => ['required' => true, 'type' => 'string'],
                    'reason'   => ['required' => true, 'type' => 'string'],
                    'evidence' => ['required' => false, 'type' => 'string'],
                ],
            ],
            [
                'methods'             => 'GET',
                'callback'            => [__CLASS__, 'status'],
                'permission_callback' => '__return_true',
                'args' => ['tx_hash' => ['required' => true, 'type' => 'string']],
            ],
        ]);

        register_rest_route('clearwallet/v1', '/rate-card', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'rate_card'],
            'permission_callback' => '__return_true',
        ]);
    }

    public static function submit(\WP_REST_Request $request) {
        $tx_hash  = sanitize_text_field($request->get_param('tx_hash'));
        $reason   = sanitize_text_field($request->get_param('reason'));
        $evidence = sanitize_textarea_field((string) $request->get_param('evidence'));

        $allowed = ['no_content', 'wrong_content', '404', '5xx', 'timeout', 'corrupted', 'other'];
        if (!in_array($reason, $allowed, true)) {
            return new \WP_Error('clearwallet_invalid_reason', 'Invalid reason', ['status' => 400]);
        }

        // tx_hash format: 0x-prefixed lowercase hex, 64 chars. Reject anything
        // else cheaply, before hitting the DB, to prevent enumeration attacks.
        if (!preg_match('/^0x[a-f0-9]{64}$/i', $tx_hash)) {
            return new \WP_Error('clearwallet_invalid_tx', 'Invalid tx_hash format', ['status' => 400]);
        }

        global $wpdb;
        $tx = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}clearwallet_transactions WHERE tx_hash = %s", $tx_hash
        ), ARRAY_A);

        if (!$tx) {
            return new \WP_Error('clearwallet_no_tx', 'Transaction not found', ['status' => 404]);
        }
        if (in_array($tx['status'], ['refunded', 'refunding'], true)) {
            return rest_ensure_response([
                'status'  => $tx['status'],
                'message' => 'Already processed',
            ]);
        }

        // SECURITY: The /dispute endpoint is public (permission_callback is
        // __return_true) because legitimate AI agents need to file disputes
        // without WordPress credentials. To prevent abuse — anyone with a
        // known tx_hash could otherwise submit disputes and trigger refunds
        // on transactions they didn't make — we REQUIRE positive agent
        // identification matching the original payer.
        //
        // Three checks, all must pass:
        //   1. Detector identifies the requester as an AI agent
        //   2. The detected agent_id matches the agent that paid the tx
        //   3. (Existing) the reason is in the allowed enum
        $detection = Detector::detect($_SERVER);
        if (empty($detection['is_agent']) || empty($detection['agent_id'])) {
            // No verifiable agent identity — refuse. Real agents that paid
            // for this tx will be detectable via the same signature path.
            return new \WP_Error('clearwallet_unidentified',
                'Dispute requires a verifiable agent identity (RFC 9421 Signature-Agent or known UA)',
                ['status' => 403]);
        }
        if ($detection['agent_id'] !== $tx['agent_id']) {
            Abuse::record($detection['agent_id'], 'dispute_mismatch', $tx_hash);
            return new \WP_Error('clearwallet_forbidden',
                'Dispute must come from the paying agent identity', ['status' => 403]);
        }

        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}clearwallet_disputes WHERE tx_hash = %s AND status = 'open'", $tx_hash
        ));
        if ($existing) {
            return rest_ensure_response(['status' => 'pending', 'id' => $existing->id]);
        }

        $wpdb->insert($wpdb->prefix . 'clearwallet_disputes', [
            'tx_hash'    => $tx_hash,
            'agent_id'   => $tx['agent_id'],
            'reason'     => $reason,
            'evidence'   => $evidence,
            'status'     => 'open',
            'created_at' => current_time('mysql', true),
        ]);
        $dispute_id = $wpdb->insert_id;

        $auto_threshold = (int) Installer::setting('auto_approve_threshold', 10000);
        $auto_reasons   = ['no_content', '404', '5xx', 'timeout'];

        if ((int) $tx['amount_atomic'] <= $auto_threshold && in_array($reason, $auto_reasons, true)) {
            return self::resolve($dispute_id, 'refund', 'auto');
        }

        self::notify_operator($tx, $reason, $evidence, $dispute_id);

        return rest_ensure_response([
            'status'    => 'pending',
            'dispute_id'=> $dispute_id,
            'message'   => 'Dispute filed; operator notified',
            'check_url' => rest_url("clearwallet/v1/dispute?tx_hash={$tx_hash}"),
        ]);
    }

    public static function status(\WP_REST_Request $request) {
        $tx_hash = sanitize_text_field($request->get_param('tx_hash'));
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT d.status, d.resolution, d.resolved_at, t.refund_tx
             FROM {$wpdb->prefix}clearwallet_disputes d
             LEFT JOIN {$wpdb->prefix}clearwallet_transactions t ON t.tx_hash = d.tx_hash
             WHERE d.tx_hash = %s
             ORDER BY d.id DESC LIMIT 1", $tx_hash
        ), ARRAY_A);
        if (!$row) {
            return new \WP_Error('clearwallet_no_dispute', 'No dispute found', ['status' => 404]);
        }
        return rest_ensure_response($row);
    }

    public static function rate_card() {
        $rates = Installer::setting('rate_card', []);
        return rest_ensure_response([
            'currency' => 'USDC',
            'network'  => CdpClient::to_caip2(Installer::setting('network', 'base')),
            'network_name' => Installer::setting('network', 'base'),
            'pay_to'   => Installer::setting('payto_wallet', ''),
            'rates'    => array_map(function ($r) {
                return ['atomic' => (int) $r, 'usd' => Facilitator::atomic_to_decimal((int) $r)];
            }, $rates),
            'dispute_url' => rest_url('clearwallet/v1/dispute'),
        ]);
    }

    public static function resolve(int $dispute_id, string $resolution, string $by = 'manual') {
        global $wpdb;
        $tbl = $wpdb->prefix . 'clearwallet_disputes';
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$tbl} WHERE id = %d", $dispute_id), ARRAY_A);
        if (!$row) { return new \WP_Error('clearwallet_no_dispute', 'Not found'); }
        if ($row['status'] !== 'open') {
            return rest_ensure_response(['status' => $row['status']]);
        }

        $now = current_time('mysql', true);
        $wpdb->update($tbl, [
            'status'      => 'resolved',
            'resolution'  => $resolution . ':' . $by,
            'resolved_at' => $now,
        ], ['id' => $dispute_id]);

        if ($resolution === 'refund') {
            $result = Refund::initiate($row['tx_hash'], 'dispute:' . $row['reason'], $row['evidence']);
            return rest_ensure_response([
                'status'     => 'resolved',
                'resolution' => 'refunded',
                'result'     => is_wp_error($result) ? ['error' => $result->get_error_message()] : $result,
            ]);
        }

        return rest_ensure_response([
            'status'     => 'resolved',
            'resolution' => $resolution,
        ]);
    }

    protected static function notify_operator(array $tx, string $reason, string $evidence, int $dispute_id) {
        $to = Installer::setting('dispute_email', get_option('admin_email'));
        if (!$to) { return; }

        $resolve_url = admin_url('admin.php?page=clearwallet&tab=disputes&highlight=' . $dispute_id);
        $subject = sprintf('[ClearWallet] Dispute filed for tx %s', substr($tx['tx_hash'], 0, 12));
        $body = sprintf(
            "Agent %s disputed transaction %s\n\nResource: %s\nAmount: %s USDC\nReason: %s\n\nEvidence:\n%s\n\nResolve: %s",
            $tx['agent_id'],
            $tx['tx_hash'],
            $tx['resource'],
            Facilitator::atomic_to_decimal((int) $tx['amount_atomic']),
            $reason,
            $evidence ?: '(none provided)',
            $resolve_url
        );
        wp_mail($to, $subject, $body);
    }
}
