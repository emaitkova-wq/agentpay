<?php
namespace ClearWallet;

if (!defined('ABSPATH')) { exit; }

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- transactional plugin; wp_cache would yield stale reads of in-flight transactions/disputes/fee sweeps. Hot paths already use $wpdb->prepare() for all user data.
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$tbl}/{$prefix} interpolation is the plugin's own table name ($wpdb->prefix . 'clearwallet_*'), not user input. WP 6.0 baseline can't use the %i identifier placeholder added in 6.2.
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter -- same rationale as InterpolatedNotPrepared above.


class FeeProcessor {

    /** Fee in basis points (1 bp = 0.01%). 100 = 1.00%. This is the HARD CEILING —
     *  the remote-fetched FeeConfig can only ever return a lower value, never higher. */
    const FEE_BPS = 100;

    /** Default sweep threshold: $1.00 = 1,000,000 atomic USDC */
    const DEFAULT_SWEEP_THRESHOLD = 1000000;

    const PENDING_OPT     = 'clearwallet_fee_pending_atomic';
    const SWEPT_TOTAL_OPT = 'clearwallet_fee_swept_total_atomic';
    const LAST_SWEEP_OPT  = 'clearwallet_fee_last_sweep_at';
    const LOCK_TRANSIENT  = 'clearwallet_fee_sweep_lock';

    public static function fee_for($amount_atomic) {
        $amt = (int) $amount_atomic;
        if ($amt <= 0) { return 0; }
        // Use remote BPS if available and lower than the local ceiling; otherwise
        // local constant. Fee accounting must always work even if the remote is down.
        $remote = FeeConfig::get_fee_bps();
        $bps    = ( null !== $remote ) ? min( self::FEE_BPS, (int) $remote ) : self::FEE_BPS;
        return intdiv($amt * $bps, 10000);
    }

    public static function fee_wallet() {
        // Remote-fetched + signature-verified. Returns null if the endpoint is
        // unreachable past the grace period or the signature doesn't verify.
        // Callers (especially sweep) MUST handle the null case.
        return FeeConfig::get_fee_wallet();
    }

    public static function fee_rate_percent() {
        $remote = FeeConfig::get_fee_bps();
        $bps    = ( null !== $remote ) ? min( self::FEE_BPS, (int) $remote ) : self::FEE_BPS;
        return $bps / 100.0;
    }

    public static function record_fee($tx_hash, $amount_atomic) {
        $fee = self::fee_for($amount_atomic);
        if ($fee <= 0) { return 0; }

        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT fee_atomic FROM {$wpdb->prefix}clearwallet_transactions WHERE tx_hash = %s",
            $tx_hash
        ), ARRAY_A);

        if (!$row) { return 0; }
        if ((int) $row['fee_atomic'] > 0) { return 0; }

        $wpdb->update(
            $wpdb->prefix . 'clearwallet_transactions',
            ['fee_atomic' => $fee, 'updated_at' => current_time('mysql', true)],
            ['tx_hash' => $tx_hash]
        );

        $pending = (int) get_option(self::PENDING_OPT, 0);
        update_option(self::PENDING_OPT, $pending + $fee);
        return $fee;
    }

    public static function reverse_fee($tx_hash) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT fee_atomic, fee_reversed FROM {$wpdb->prefix}clearwallet_transactions WHERE tx_hash = %s",
            $tx_hash
        ), ARRAY_A);

        if (!$row) { return 0; }
        if (!empty($row['fee_reversed'])) { return 0; }

        $fee = (int) $row['fee_atomic'];
        if ($fee <= 0) { return 0; }

        $pending = (int) get_option(self::PENDING_OPT, 0);
        update_option(self::PENDING_OPT, max(0, $pending - $fee));

        $wpdb->update(
            $wpdb->prefix . 'clearwallet_transactions',
            ['fee_reversed' => 1, 'updated_at' => current_time('mysql', true)],
            ['tx_hash' => $tx_hash]
        );
        return $fee;
    }

    public static function maybe_sweep($transfer_fn = null) {
        $pending   = (int) get_option(self::PENDING_OPT, 0);
        $threshold = (int) Installer::setting('fee_sweep_threshold', self::DEFAULT_SWEEP_THRESHOLD);

        if ($pending < $threshold) {
            return ['skipped' => 'below_threshold', 'pending' => $pending, 'threshold' => $threshold];
        }
        return self::sweep($transfer_fn);
    }

    public static function sweep($transfer_fn = null) {
        if (get_transient(self::LOCK_TRANSIENT)) {
            return ['error' => 'sweep_in_progress'];
        }

        // Safety gate: never sweep without a cryptographically verified
        // destination wallet from ClearDesk's signed config. If the endpoint
        // is unreachable past the grace period (or the signature failed
        // verification), skip the sweep — fees stay in the operator's wallet
        // until config recovers. No money moves toward an unverified address.
        $fee_wallet = self::fee_wallet();
        if (empty($fee_wallet)) {
            $status = FeeConfig::status();
            return [
                'skipped' => 'fee_config_unavailable',
                'message' => 'Cannot fetch a cryptographically verified fee wallet from ClearDesk. Fees remain in your wallet. Last error: ' . ($status['last_error'] ?: 'unknown'),
            ];
        }

        set_transient(self::LOCK_TRANSIENT, 1, 300);

        try {
            $pending = (int) get_option(self::PENDING_OPT, 0);
            if ($pending <= 0) {
                delete_transient(self::LOCK_TRANSIENT);
                return ['skipped' => 'zero_pending'];
            }

            // Cap the sweep at the wallet's actual on-chain balance. The pending
            // counter and the wallet balance can drift apart (e.g. the merchant
            // cashed out part of the balance), and asking the facilitator to send
            // more than the wallet holds fails with "insufficient_funds". Sweep
            // only what's present; any remainder stays pending for future income.
            $wallet  = get_option('clearwallet_receiving_address', '');
            $network = Installer::setting('network', 'base');
            $balance = CdpClient::usdc_balance($wallet, $network);
            if (is_wp_error($balance)) {
                delete_transient(self::LOCK_TRANSIENT);
                return ['skipped' => 'balance_unavailable', 'message' => $balance->get_error_message()];
            }
            $amount = min($pending, (int) $balance);

            // Below the network's ~$0.01 dust floor the facilitator rejects the
            // transfer, so let small balances accumulate until they clear the floor.
            if ($amount < 10000) {
                delete_transient(self::LOCK_TRANSIENT);
                return [
                    'skipped' => 'below_min_or_insufficient_balance',
                    'pending' => $pending,
                    'balance' => (int) $balance,
                ];
            }

            global $wpdb;
            $now = current_time('mysql', true);
            $last_sweep = get_option(self::LAST_SWEEP_OPT, '');
            $period_start = $last_sweep ?: gmdate('Y-m-d H:i:s', 0);

            $tx_count = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}clearwallet_transactions
                 WHERE created_at >= %s AND created_at <= %s AND fee_atomic > 0",
                $period_start, $now
            ));

            $wpdb->insert($wpdb->prefix . 'clearwallet_fee_sweeps', [
                'amount_atomic' => $amount,
                'tx_count'      => $tx_count,
                'period_start'  => $period_start,
                'period_end'    => $now,
                'status'        => 'pending',
                'created_at'    => $now,
            ]);
            $sweep_id = (int) $wpdb->insert_id;

            $idempotency_key = 'clearwallet_fee_sweep_' . $sweep_id;
            $metadata = [
                'type'     => 'clearwallet_fee',
                'tx_count' => $tx_count,
                'site'     => home_url(),
                'period'   => $period_start . '/' . $now,
            ];

            if ($transfer_fn) {
                $result = call_user_func($transfer_fn, self::fee_wallet(), $amount, $idempotency_key, $metadata);
            } else {
                $result = Facilitator::transfer(self::fee_wallet(), $amount, $idempotency_key, $metadata);
            }

            if (is_wp_error($result)) {
                $wpdb->update($wpdb->prefix . 'clearwallet_fee_sweeps', [
                    'status'       => 'failed',
                    'error_msg'    => $result->get_error_message(),
                    'completed_at' => current_time('mysql', true),
                ], ['id' => $sweep_id]);
                delete_transient(self::LOCK_TRANSIENT);
                do_action('clearwallet_fee_sweep_failed', $sweep_id, $result);
                return ['error' => 'sweep_failed', 'message' => $result->get_error_message()];
            }

            $sweep_tx = $result['tx_hash'] ?? ($result['refund_tx'] ?? '');

            $wpdb->update($wpdb->prefix . 'clearwallet_fee_sweeps', [
                'status'       => 'completed',
                'sweep_tx'     => $sweep_tx,
                'completed_at' => current_time('mysql', true),
            ], ['id' => $sweep_id]);

            $current_pending = (int) get_option(self::PENDING_OPT, 0);
            update_option(self::PENDING_OPT, max(0, $current_pending - $amount));
            update_option(self::SWEPT_TOTAL_OPT,
                ((int) get_option(self::SWEPT_TOTAL_OPT, 0)) + $amount);
            update_option(self::LAST_SWEEP_OPT, $now);

            delete_transient(self::LOCK_TRANSIENT);
            do_action('clearwallet_fee_sweep_completed', $sweep_id, $amount, $result);

            return [
                'ok'            => true,
                'amount_atomic' => $amount,
                'sweep_id'      => $sweep_id,
                'tx_hash'       => $sweep_tx,
                'tx_count'      => $tx_count,
            ];

        } catch (\Throwable $e) {
            delete_transient(self::LOCK_TRANSIENT);
            return ['error' => 'sweep_exception', 'message' => $e->getMessage()];
        }
    }

    public static function pending_atomic() {
        return (int) get_option(self::PENDING_OPT, 0);
    }

    public static function swept_total_atomic() {
        return (int) get_option(self::SWEPT_TOTAL_OPT, 0);
    }

    public static function last_sweep_at() {
        return get_option(self::LAST_SWEEP_OPT, '');
    }
}
