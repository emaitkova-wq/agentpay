<?php
namespace ClearWallet;

if (!defined('ABSPATH')) { exit; }

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- transactional plugin; wp_cache would yield stale reads of in-flight transactions/disputes/fee sweeps. Hot paths already use $wpdb->prepare() for all user data.
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$tbl}/{$prefix} interpolation is the plugin's own table name ($wpdb->prefix . 'clearwallet_*'), not user input. WP 6.0 baseline can't use the %i identifier placeholder added in 6.2.
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter -- same rationale as InterpolatedNotPrepared above.


class Abuse {

    public static function check(array $detection) {
        if (empty($detection['is_agent'])) { return ['ok' => true]; }

        $fp = $detection['fingerprint'] ?? 'unknown';
        $agent_id = $detection['agent_id'] ?? 'unknown';

        if (self::is_blocklisted($fp, $agent_id)) {
            return ['ok' => false, 'reason' => 'blocklisted'];
        }

        if (self::over_rate_limit($fp)) {
            self::record($agent_id, 'rate_limit');
            if (self::should_auto_block($agent_id)) {
                self::auto_block($fp);
            }
            return ['ok' => false, 'reason' => 'rate_limit'];
        }

        if (!empty($detection['source']) && $detection['source'] === 'user-agent') {
            if (self::recent_failure_rate($agent_id) > 0.6) {
                return ['ok' => false, 'reason' => 'suspicious'];
            }
        }

        return ['ok' => true];
    }

    public static function is_blocklisted(string $fp, string $agent_id = '') {
        $list = Installer::setting('abuse_blocklist', '');
        $entries = array_filter(array_map('trim', explode("\n", $list)));
        foreach ($entries as $e) {
            if ($e === $fp || $e === $agent_id) { return true; }
        }
        return (bool) get_transient('clearwallet_block_' . md5($fp));
    }

    public static function auto_block(string $fp) {
        $window = (int) Installer::setting('abuse_block_window', 600);
        set_transient('clearwallet_block_' . md5($fp), 1, $window);
    }

    public static function over_rate_limit(string $fp) {
        $limit = (int) Installer::setting('abuse_rate_per_min', 120);
        if ($limit <= 0) { return false; }

        $key = 'clearwallet_rl_' . md5($fp);
        $count = (int) get_transient($key);
        $count++;
        set_transient($key, $count, 60);
        return $count > $limit;
    }

    public static function record(string $agent_id, string $event, string $details = '') {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'clearwallet_abuse', [
            'agent_id'   => $agent_id,
            'event'      => $event,
            'details'    => $details,
            'created_at' => current_time('mysql', true),
        ]);
    }

    public static function should_auto_block(string $agent_id) {
        global $wpdb;
        $threshold = (int) Installer::setting('abuse_block_threshold', 20);
        $window    = (int) Installer::setting('abuse_block_window', 600);
        $since     = gmdate('Y-m-d H:i:s', time() - $window);
        $tbl       = $wpdb->prefix . 'clearwallet_abuse';
        $count     = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$tbl} WHERE agent_id = %s AND created_at > %s",
            $agent_id, $since
        ));
        return $count >= $threshold;
    }

    public static function recent_failure_rate(string $agent_id) {
        global $wpdb;
        $tbl   = $wpdb->prefix . 'clearwallet_abuse';
        $since = gmdate('Y-m-d H:i:s', time() - 3600);
        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$tbl} WHERE agent_id = %s AND created_at > %s",
            $agent_id, $since
        ));
        if ($total < 5) { return 0; }
        $bad = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$tbl} WHERE agent_id = %s AND created_at > %s AND event IN ('failed_verify','rate_limit','bad_signature')",
            $agent_id, $since
        ));
        return $bad / $total;
    }
}
