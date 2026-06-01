<?php
/**
 * Plugin Name:       AgentPay by ClearDesk SEO
 * Plugin URI:        https://cleardeskseo.com/wp-plugins/
 * Description:       Open source AI agent payment processor. Charge AI agents in USDC for access to your content — RFC 9421 Web Bot Auth, x402 protocol, Coinbase settlement, Stripe off-ramp.
 * Version:           1.3.2
 * Requires PHP:      7.4
 * Requires at least: 6.0
 * Author:            ClearDesk SEO
 * Author URI:        https://cleardeskseo.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       agentpay-by-cleardesk-seo
 */

if (!defined('ABSPATH')) { exit; }

define('AGENTPAY_VERSION', '1.3.2');
define('AGENTPAY_FILE', __FILE__);
define('AGENTPAY_PATH', plugin_dir_path(__FILE__));
define('AGENTPAY_URL', plugin_dir_url(__FILE__));
define('AGENTPAY_OPT', 'agentpay_settings');

require_once AGENTPAY_PATH . 'includes/class-installer.php';
require_once AGENTPAY_PATH . 'includes/httpsig/class-structured-fields.php';
require_once AGENTPAY_PATH . 'includes/httpsig/class-signature-base.php';
require_once AGENTPAY_PATH . 'includes/httpsig/class-jwk.php';
require_once AGENTPAY_PATH . 'includes/httpsig/class-key-resolver.php';
require_once AGENTPAY_PATH . 'includes/httpsig/class-verifier.php';
require_once AGENTPAY_PATH . 'includes/class-detector.php';
require_once AGENTPAY_PATH . 'includes/class-session.php';
require_once AGENTPAY_PATH . 'includes/class-facilitator.php';
require_once AGENTPAY_PATH . 'includes/class-cdp-client.php';
require_once AGENTPAY_PATH . 'includes/class-fee-config.php';
require_once AGENTPAY_PATH . 'includes/class-fee-processor.php';
require_once AGENTPAY_PATH . 'includes/class-abuse.php';
require_once AGENTPAY_PATH . 'includes/class-gate.php';
require_once AGENTPAY_PATH . 'includes/class-refund.php';
require_once AGENTPAY_PATH . 'includes/class-dispute.php';
require_once AGENTPAY_PATH . 'includes/class-stripe.php';
require_once AGENTPAY_PATH . 'includes/class-discovery.php';

if (is_admin()) {
    require_once AGENTPAY_PATH . 'includes/class-admin.php';
    require_once AGENTPAY_PATH . 'includes/class-admin-setup.php';
    new \AgentPay\AdminSetup();
}

register_activation_hook(__FILE__, ['\AgentPay\Installer', 'activate']);
register_activation_hook(__FILE__, ['\AgentPay\Discovery', 'activate']);
register_activation_hook(__FILE__, function () {
    if (!wp_next_scheduled('agentpay_fee_sweep_cron')) {
        wp_schedule_event(time() + 3600, 'daily', 'agentpay_fee_sweep_cron');
    }
});
register_deactivation_hook(__FILE__, ['\AgentPay\Installer', 'deactivate']);
register_deactivation_hook(__FILE__, ['\AgentPay\Discovery', 'deactivate']);
register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook('agentpay_fee_sweep_cron');
});

add_action('agentpay_fee_sweep_cron', function () {
    if (\AgentPay\Installer::setting('fee_sweep_enabled', 1)) {
        \AgentPay\FeeProcessor::maybe_sweep();
    }
});

add_action('plugins_loaded', function () {
    $settings = get_option(AGENTPAY_OPT, []);

    add_action('rest_api_init', ['\AgentPay\Dispute', 'register_routes']);

    // Discovery (.well-known/agentpay + robots.txt) runs regardless of whether
    // the gate is enabled — it's pure metadata about how to interact with the
    // site, useful even when payments are temporarily disabled.
    \AgentPay\Discovery::init();

    if (empty($settings['enabled'])) {
        return;
    }

    add_action('parse_request', ['\AgentPay\Gate', 'intercept'], 1);
    add_action('template_redirect', ['\AgentPay\Refund', 'maybe_refund_404'], 1);
    \AgentPay\Refund::register_shutdown();
}, 5);

if (is_admin()) {
    add_action('admin_menu', ['\AgentPay\Admin', 'add_menu']);
    add_action('admin_init', ['\AgentPay\Admin', 'register_settings']);
    add_action('admin_post_agentpay_test_facilitator', ['\AgentPay\Admin', 'handle_test_facilitator']);
    add_action('admin_post_agentpay_resolve_dispute',  ['\AgentPay\Admin', 'handle_resolve_dispute']);
    add_action('admin_post_agentpay_sweep_fees',       ['\AgentPay\Admin', 'handle_sweep_fees']);
}
