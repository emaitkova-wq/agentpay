<?php
if (!defined('WP_UNINSTALL_PLUGIN')) { exit; }



// phpcs:disable WordPress.DB.DirectDatabaseQuery -- uninstall must DROP tables directly; cache layer not appropriate during uninstall.
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$t} is $wpdb->prefix . 'clearwallet_*' (hardcoded plugin table names).
// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange -- uninstall is the canonical place to drop schema.
global $wpdb;

delete_option('clearwallet_settings');
delete_option('clearwallet_session_secret');
delete_option('clearwallet_db_version');
delete_option('clearwallet_fee_pending_atomic');
delete_option('clearwallet_fee_swept_total_atomic');
delete_option('clearwallet_fee_last_sweep_at');

$clearwallet_tables = [
    $wpdb->prefix . 'clearwallet_transactions',
    $wpdb->prefix . 'clearwallet_disputes',
    $wpdb->prefix . 'clearwallet_abuse',
    $wpdb->prefix . 'clearwallet_fee_sweeps',
];
foreach ($clearwallet_tables as $clearwallet_table) {
    $wpdb->query("DROP TABLE IF EXISTS {$clearwallet_table}");
}

$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_clearwallet_%'");
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_clearwallet_%'");
