<?php
if (!defined('WP_UNINSTALL_PLUGIN')) { exit; }

global $wpdb;

delete_option('agentpay_settings');
delete_option('agentpay_session_secret');
delete_option('agentpay_db_version');
delete_option('agentpay_fee_pending_atomic');
delete_option('agentpay_fee_swept_total_atomic');
delete_option('agentpay_fee_last_sweep_at');

$tables = [
    $wpdb->prefix . 'agentpay_transactions',
    $wpdb->prefix . 'agentpay_disputes',
    $wpdb->prefix . 'agentpay_abuse',
    $wpdb->prefix . 'agentpay_fee_sweeps',
];
foreach ($tables as $t) {
    $wpdb->query("DROP TABLE IF EXISTS {$t}");
}

$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_agentpay_%'");
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_agentpay_%'");
