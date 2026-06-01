<?php
namespace ClearWallet;

if (!defined('ABSPATH')) { exit; }

class Installer {

    const DB_VERSION = '1';

    public static function activate() {
        self::create_tables();
        self::seed_defaults();
        self::ensure_session_secret();
        update_option('clearwallet_db_version', self::DB_VERSION);
    }

    public static function deactivate() {
        wp_clear_scheduled_hook('clearwallet_abuse_gc');
    }

    public static function create_tables() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();

        $tx = $wpdb->prefix . 'clearwallet_transactions';
        dbDelta("CREATE TABLE {$tx} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            tx_hash VARCHAR(128) NOT NULL,
            agent_id VARCHAR(190) NOT NULL,
            agent_address VARCHAR(64) DEFAULT NULL,
            resource VARCHAR(500) NOT NULL,
            amount_atomic BIGINT UNSIGNED NOT NULL,
            fee_atomic BIGINT UNSIGNED NOT NULL DEFAULT 0,
            fee_reversed TINYINT(1) NOT NULL DEFAULT 0,
            currency VARCHAR(16) NOT NULL DEFAULT 'USDC',
            network VARCHAR(32) NOT NULL DEFAULT 'base',
            status VARCHAR(24) NOT NULL DEFAULT 'paid',
            refund_tx VARCHAR(128) DEFAULT NULL,
            refund_reason VARCHAR(64) DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY tx_hash (tx_hash),
            KEY agent_id (agent_id),
            KEY status (status),
            KEY created_at (created_at)
        ) {$charset};");

        $dp = $wpdb->prefix . 'clearwallet_disputes';
        dbDelta("CREATE TABLE {$dp} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            tx_hash VARCHAR(128) NOT NULL,
            agent_id VARCHAR(190) NOT NULL,
            reason VARCHAR(64) NOT NULL,
            evidence TEXT,
            status VARCHAR(24) NOT NULL DEFAULT 'open',
            resolution VARCHAR(24) DEFAULT NULL,
            created_at DATETIME NOT NULL,
            resolved_at DATETIME DEFAULT NULL,
            PRIMARY KEY (id),
            KEY tx_hash (tx_hash),
            KEY status (status)
        ) {$charset};");

        $ab = $wpdb->prefix . 'clearwallet_abuse';
        dbDelta("CREATE TABLE {$ab} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            agent_id VARCHAR(190) NOT NULL,
            event VARCHAR(32) NOT NULL,
            details VARCHAR(255) DEFAULT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY agent_id (agent_id),
            KEY created_at (created_at)
        ) {$charset};");

        $fs = $wpdb->prefix . 'clearwallet_fee_sweeps';
        dbDelta("CREATE TABLE {$fs} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            sweep_tx VARCHAR(128) DEFAULT NULL,
            amount_atomic BIGINT UNSIGNED NOT NULL,
            tx_count INT UNSIGNED NOT NULL DEFAULT 0,
            period_start DATETIME NOT NULL,
            period_end DATETIME NOT NULL,
            status VARCHAR(24) NOT NULL DEFAULT 'pending',
            error_msg VARCHAR(500) DEFAULT NULL,
            created_at DATETIME NOT NULL,
            completed_at DATETIME DEFAULT NULL,
            PRIMARY KEY (id),
            KEY status (status),
            KEY created_at (created_at)
        ) {$charset};");
    }

    public static function seed_defaults() {
        if (get_option(CLEARWALLET_OPT)) { return; }
        update_option(CLEARWALLET_OPT, [
            'enabled'                => 0,
            'payto_wallet'           => '',
            'facilitator_url'        => '', // empty = auto-select by network
            'network'                => 'base',
            'usdc_contract'          => '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913',
            'rate_card'              => [
                'page'   => 1000,
                'view'   => 2000,
                'search' => 5000,
                'report' => 20000,
                'export' => 50000,
            ],
            'session_ttl'            => 3600,
            'session_page_budget'    => 50,
            'auto_refund_404'        => 1,
            'auto_refund_5xx'        => 1,
            'auto_refund_timeout'    => 1,
            'timeout_threshold_ms'   => 30000,
            'auto_approve_threshold' => 10000,
            'abuse_rate_per_min'     => 120,
            'abuse_block_threshold'  => 20,
            'abuse_block_window'     => 600,
            'abuse_blocklist'        => '',
            'dispute_email'          => get_option('admin_email'),
            'detector_strict'        => 0,
            'fee_sweep_threshold'    => 1000000,
            'fee_sweep_enabled'      => 1,
        ]);
    }

    public static function ensure_session_secret() {
        if (!get_option('clearwallet_session_secret')) {
            update_option('clearwallet_session_secret', bin2hex(random_bytes(32)));
        }
    }

    public static function setting($key, $default = null) {
        $s = get_option(CLEARWALLET_OPT, []);
        return $s[$key] ?? $default;
    }
}
