<?php
/**
 * Plugin Name:       ClearWallet by ClearDesk SEO
 * Plugin URI:        https://cleardeskseo.com/wp-plugins/
 * Description:       Open source AI agent payment processor. Charge AI agents in USDC for access to your content — RFC 9421 Web Bot Auth, x402 protocol, gasless Coinbase USDC settlement.
 * Version:           1.4.6
 * Requires PHP:      7.4
 * Requires at least: 6.0
 * Author:            ClearDesk SEO
 * Author URI:        https://cleardeskseo.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       agentpay-by-cleardesk-seo
 */

if (!defined('ABSPATH')) { exit; }

define('CLEARWALLET_VERSION', '1.4.6');
define('CLEARWALLET_FILE', __FILE__);
define('CLEARWALLET_PATH', plugin_dir_path(__FILE__));
define('CLEARWALLET_URL', plugin_dir_url(__FILE__));
define('CLEARWALLET_OPT', 'clearwallet_settings');

require_once CLEARWALLET_PATH . 'includes/class-installer.php';
require_once CLEARWALLET_PATH . 'includes/httpsig/class-structured-fields.php';
require_once CLEARWALLET_PATH . 'includes/httpsig/class-signature-base.php';
require_once CLEARWALLET_PATH . 'includes/httpsig/class-jwk.php';
require_once CLEARWALLET_PATH . 'includes/httpsig/class-key-resolver.php';
require_once CLEARWALLET_PATH . 'includes/httpsig/class-verifier.php';
require_once CLEARWALLET_PATH . 'includes/class-detector.php';
require_once CLEARWALLET_PATH . 'includes/class-session.php';
require_once CLEARWALLET_PATH . 'includes/class-facilitator.php';
require_once CLEARWALLET_PATH . 'includes/class-cdp-client.php';
require_once CLEARWALLET_PATH . 'includes/class-fee-config.php';
require_once CLEARWALLET_PATH . 'includes/class-fee-processor.php';
require_once CLEARWALLET_PATH . 'includes/class-abuse.php';
require_once CLEARWALLET_PATH . 'includes/class-gate.php';
require_once CLEARWALLET_PATH . 'includes/class-refund.php';
require_once CLEARWALLET_PATH . 'includes/class-dispute.php';
require_once CLEARWALLET_PATH . 'includes/class-discovery.php';

if (is_admin()) {
    require_once CLEARWALLET_PATH . 'includes/class-admin.php';
    require_once CLEARWALLET_PATH . 'includes/class-admin-setup.php';
    new \ClearWallet\AdminSetup();
}

register_activation_hook(__FILE__, ['\ClearWallet\Installer', 'activate']);
register_activation_hook(__FILE__, ['\ClearWallet\Discovery', 'activate']);
register_activation_hook(__FILE__, function () {
    if (!wp_next_scheduled('clearwallet_fee_sweep_cron')) {
        wp_schedule_event(time() + 3600, 'daily', 'clearwallet_fee_sweep_cron');
    }
});
register_deactivation_hook(__FILE__, ['\ClearWallet\Installer', 'deactivate']);
register_deactivation_hook(__FILE__, ['\ClearWallet\Discovery', 'deactivate']);
register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook('clearwallet_fee_sweep_cron');
});

add_action('clearwallet_fee_sweep_cron', function () {
    if (\ClearWallet\Installer::setting('fee_sweep_enabled', 1)) {
        \ClearWallet\FeeProcessor::maybe_sweep();
    }
});

add_action('plugins_loaded', function () {
    $settings = get_option(CLEARWALLET_OPT, []);

    add_action('rest_api_init', ['\ClearWallet\Dispute', 'register_routes']);

    // Discovery (.well-known/clearwallet + robots.txt) runs regardless of whether
    // the gate is enabled — it's pure metadata about how to interact with the
    // site, useful even when payments are temporarily disabled.
    \ClearWallet\Discovery::init();

    if (empty($settings['enabled'])) {
        return;
    }

    add_action('parse_request', ['\ClearWallet\Gate', 'intercept'], 1);
    add_action('template_redirect', ['\ClearWallet\Refund', 'maybe_refund_404'], 1);
    \ClearWallet\Refund::register_shutdown();
}, 5);

if (is_admin()) {
    add_action('admin_menu', ['\ClearWallet\Admin', 'add_menu']);
    add_action('admin_init', ['\ClearWallet\Admin', 'register_settings']);
    add_action('admin_post_clearwallet_test_facilitator', ['\ClearWallet\Admin', 'handle_test_facilitator']);
    add_action('admin_post_clearwallet_resolve_dispute',  ['\ClearWallet\Admin', 'handle_resolve_dispute']);
    add_action('admin_post_clearwallet_sweep_fees',       ['\ClearWallet\Admin', 'handle_sweep_fees']);
}
