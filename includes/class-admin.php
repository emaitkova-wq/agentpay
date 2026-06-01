<?php
namespace AgentPay;

if (!defined('ABSPATH')) { exit; }

class Admin {

    public static function add_menu() {
        add_management_page(
            'AgentPay',
            'AgentPay',
            'manage_options',
            'agentpay',
            [__CLASS__, 'render_page']
        );
    }

    public static function register_settings() {
        register_setting('agentpay_group', AGENTPAY_OPT, [
            'sanitize_callback' => [__CLASS__, 'sanitize'],
        ]);
    }

    public static function sanitize($input) {
        $out = get_option(AGENTPAY_OPT, []);
        if (!is_array($input)) { return $out; }

        $bool = ['enabled', 'auto_refund_404', 'auto_refund_5xx', 'auto_refund_timeout',
                 'stripe_record_revenue', 'detector_strict'];
        foreach ($bool as $k) { $out[$k] = !empty($input[$k]) ? 1 : 0; }

        $text = ['payto_wallet', 'facilitator_url', 'coinbase_api_key', 'coinbase_api_secret',
                 'coinbase_wallet_id', 'network', 'usdc_contract', 'stripe_account_id',
                 'stripe_secret_key', 'dispute_email', 'abuse_blocklist'];
        foreach ($text as $k) {
            if (isset($input[$k])) {
                $out[$k] = ($k === 'abuse_blocklist')
                    ? sanitize_textarea_field($input[$k])
                    : sanitize_text_field($input[$k]);
            }
        }

        $int = ['session_ttl', 'session_page_budget', 'timeout_threshold_ms',
                'auto_approve_threshold', 'abuse_rate_per_min', 'abuse_block_threshold',
                'abuse_block_window', 'fee_sweep_threshold'];
        foreach ($int as $k) {
            if (isset($input[$k])) { $out[$k] = max(0, (int) $input[$k]); }
        }

        $bool2 = ['fee_sweep_enabled'];
        foreach ($bool2 as $k) { $out[$k] = !empty($input[$k]) ? 1 : 0; }

        if (!empty($input['rate_card']) && is_array($input['rate_card'])) {
            $out['rate_card'] = [];
            foreach (['page', 'view', 'search', 'report', 'export'] as $action) {
                $out['rate_card'][$action] = max(0, (int) ($input['rate_card'][$action] ?? 0));
            }
        }

        return $out;
    }

    public static function render_page() {
        if (!current_user_can('manage_options')) { wp_die('Forbidden'); }
        $tab = $_GET['tab'] ?? 'config';

        echo '<div class="wrap"><h1>AgentPay <span style="font-size:14px;color:#646970;font-weight:normal;">by ClearDesk SEO</span></h1>';
        self::render_tabs($tab);

        switch ($tab) {
            case 'dashboard': self::render_dashboard(); break;
            case 'fees':      self::render_fees(); break;
            case 'disputes':  self::render_disputes(); break;
            case 'logs':      self::render_logs(); break;
            case 'setup':     self::render_setup(); break;
            case 'docs':      self::render_docs(); break;
            default:          self::render_config();
        }
        echo '</div>';
    }

    protected static function render_tabs($current) {
        $tabs = [
            'config'    => 'Configuration',
            'dashboard' => 'Dashboard',
            'fees'      => 'Fees',
            'disputes'  => 'Disputes',
            'logs'      => 'Transactions',
            'setup'     => 'Setup guide',
            'docs'      => 'Agent docs',
        ];
        echo '<h2 class="nav-tab-wrapper">';
        foreach ($tabs as $key => $label) {
            $active = $key === $current ? ' nav-tab-active' : '';
            $url = admin_url('tools.php?page=agentpay&tab=' . $key);
            echo '<a href="' . esc_url($url) . '" class="nav-tab' . $active . '">' . esc_html($label) . '</a>';
        }
        echo '</h2>';
    }

    protected static function render_config() {
        $s = get_option(AGENTPAY_OPT, []);
        $opt = AGENTPAY_OPT;
        ?>
        <form method="post" action="options.php">
            <?php settings_fields('agentpay_group'); ?>

            <h2>General</h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th><label>Enable agent paywall</label></th>
                    <td>
                        <label><input type="checkbox" name="<?php echo $opt; ?>[enabled]" value="1" <?php checked(!empty($s['enabled'])); ?> />
                        Intercept agent requests and require payment</label>
                    </td>
                </tr>
                <tr>
                    <th><label for="payto_wallet">PayTo wallet (Base USDC)</label></th>
                    <td>
                        <input id="payto_wallet" type="text" class="regular-text code"
                            name="<?php echo $opt; ?>[payto_wallet]"
                            value="<?php echo esc_attr($s['payto_wallet'] ?? ''); ?>"
                            placeholder="0x..." />
                        <p class="description">Your USDC-on-Base wallet address. Funds settle here before off-ramp.
                            <a href="<?php echo esc_url(admin_url('tools.php?page=agentpay&tab=setup#payto-wallet')); ?>">Where do I find this?</a></p>
                    </td>
                </tr>
                <tr>
                    <th><label>Network</label></th>
                    <td>
                        <select name="<?php echo $opt; ?>[network]">
                            <option value="base" <?php selected($s['network'] ?? 'base', 'base'); ?>>Base mainnet</option>
                            <option value="base-sepolia" <?php selected($s['network'] ?? '', 'base-sepolia'); ?>>Base Sepolia (testnet)</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label>USDC contract</label></th>
                    <td>
                        <input type="text" class="regular-text code" name="<?php echo $opt; ?>[usdc_contract]"
                            value="<?php echo esc_attr($s['usdc_contract'] ?? ''); ?>" />
                    </td>
                </tr>
                <tr>
                    <th><label>Strict detector</label></th>
                    <td>
                        <label><input type="checkbox" name="<?php echo $opt; ?>[detector_strict]" value="1" <?php checked(!empty($s['detector_strict'])); ?> />
                        Also gate on headless-browser heuristics (may catch false positives)</label>
                    </td>
                </tr>
            </table>

            <h2>Coinbase facilitator</h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th><label>Facilitator URL</label></th>
                    <td>
                        <input type="url" class="regular-text code" name="<?php echo $opt; ?>[facilitator_url]"
                            value="<?php echo esc_attr($s['facilitator_url'] ?? ''); ?>" />
                        <p class="description">Default: <code>https://api.cdp.coinbase.com/platform/v2/x402</code></p>
                    </td>
                </tr>
                <tr>
                    <th><label>Coinbase API key</label></th>
                    <td>
                        <input type="text" class="regular-text" name="<?php echo $opt; ?>[coinbase_api_key]"
                            value="<?php echo esc_attr($s['coinbase_api_key'] ?? ''); ?>" autocomplete="off" />
                        <p class="description">From Coinbase Developer Platform. Required for settle calls and refunds.
                            <a href="<?php echo esc_url(admin_url('tools.php?page=agentpay&tab=setup#coinbase-keys')); ?>">Where do I find this?</a></p>
                    </td>
                </tr>
                <tr>
                    <th><label>Coinbase API secret</label></th>
                    <td>
                        <input type="password" class="regular-text" name="<?php echo $opt; ?>[coinbase_api_secret]"
                            value="<?php echo esc_attr($s['coinbase_api_secret'] ?? ''); ?>" autocomplete="off" />
                    </td>
                </tr>
                <tr>
                    <th><label>Coinbase wallet ID (non-custodial)</label></th>
                    <td>
                        <input type="text" class="regular-text code" name="<?php echo $opt; ?>[coinbase_wallet_id]"
                            value="<?php echo esc_attr($s['coinbase_wallet_id'] ?? ''); ?>" />
                        <p class="description">Server wallet used to push refunds.
                            <a href="<?php echo esc_url(admin_url('tools.php?page=agentpay&tab=setup#coinbase-keys')); ?>">Where do I find this?</a></p>
                    </td>
                </tr>
                <tr>
                    <th></th>
                    <td>
                        <?php
                        $url = wp_nonce_url(
                            admin_url('admin-post.php?action=agentpay_test_facilitator'),
                            'agentpay_test'
                        );
                        ?>
                        <a href="<?php echo esc_url($url); ?>" class="button">Test facilitator connection</a>
                    </td>
                </tr>
            </table>

            <h2>Stripe off-ramp</h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th><label>Stripe account ID</label></th>
                    <td>
                        <input type="text" class="regular-text code" name="<?php echo $opt; ?>[stripe_account_id]"
                            value="<?php echo esc_attr($s['stripe_account_id'] ?? ''); ?>"
                            placeholder="acct_..." />
                    </td>
                </tr>
                <tr>
                    <th><label>Stripe secret key</label></th>
                    <td>
                        <input type="password" class="regular-text" name="<?php echo $opt; ?>[stripe_secret_key]"
                            value="<?php echo esc_attr($s['stripe_secret_key'] ?? ''); ?>" autocomplete="off"
                            placeholder="sk_live_..." />
                        <p class="description">Used to record USDC settlements in Stripe for reconciliation.
                        USDC → USD payouts must be configured in your Stripe dashboard.
                            <a href="<?php echo esc_url(admin_url('tools.php?page=agentpay&tab=setup#stripe-keys')); ?>">Where do I find this?</a></p>
                    </td>
                </tr>
                <tr>
                    <th><label>Record revenue to Stripe</label></th>
                    <td>
                        <label><input type="checkbox" name="<?php echo $opt; ?>[stripe_record_revenue]" value="1" <?php checked(!empty($s['stripe_record_revenue'])); ?> />
                        Log each settlement as a Stripe Treasury entry</label>
                    </td>
                </tr>
                <tr>
                    <th>Status</th>
                    <td>
                        <?php
                        $status = Stripe::status_summary();
                        if ($status['ok']) {
                            echo '<span style="color: #007a3d;">✓ Connected</span>';
                            if (!empty($status['business_name'])) {
                                echo ' — ' . esc_html($status['business_name']);
                            }
                            if (!empty($status['payouts_enabled'])) {
                                echo ' · payouts enabled';
                            }
                        } else {
                            echo '<span style="color: #a62a2a;">✗ ' . esc_html($status['msg']) . '</span>';
                        }
                        ?>
                    </td>
                </tr>
            </table>

            <h2>Rate card</h2>
            <p class="description">Prices in USDC atomic units (1,000,000 = $1.00). Default route: <code>page</code>.</p>
            <table class="form-table" role="presentation">
                <?php foreach (['page', 'view', 'search', 'report', 'export'] as $action) :
                    $val = (int) ($s['rate_card'][$action] ?? 0);
                    $usd = number_format($val / 1000000, 6); ?>
                <tr>
                    <th><label><?php echo ucfirst($action); ?> (atomic)</label></th>
                    <td>
                        <input type="number" min="0" step="1" name="<?php echo $opt; ?>[rate_card][<?php echo $action; ?>]"
                            value="<?php echo esc_attr($val); ?>" />
                        <span class="description">= $<?php echo esc_html($usd); ?></span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>

            <h2>Sessions</h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th><label>Session TTL (seconds)</label></th>
                    <td><input type="number" min="60" name="<?php echo $opt; ?>[session_ttl]"
                        value="<?php echo esc_attr((int) ($s['session_ttl'] ?? 3600)); ?>" /></td>
                </tr>
                <tr>
                    <th><label>Pages per session</label></th>
                    <td><input type="number" min="1" name="<?php echo $opt; ?>[session_page_budget]"
                        value="<?php echo esc_attr((int) ($s['session_page_budget'] ?? 50)); ?>" /></td>
                </tr>
            </table>

            <h2>Refund policy</h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th>Auto-refund</th>
                    <td>
                        <label><input type="checkbox" name="<?php echo $opt; ?>[auto_refund_404]" value="1" <?php checked(!empty($s['auto_refund_404'])); ?> /> 404 (not found)</label><br>
                        <label><input type="checkbox" name="<?php echo $opt; ?>[auto_refund_5xx]" value="1" <?php checked(!empty($s['auto_refund_5xx'])); ?> /> 5xx (server error)</label><br>
                        <label><input type="checkbox" name="<?php echo $opt; ?>[auto_refund_timeout]" value="1" <?php checked(!empty($s['auto_refund_timeout'])); ?> /> Timeout (slow response)</label>
                    </td>
                </tr>
                <tr>
                    <th><label>Timeout threshold (ms)</label></th>
                    <td><input type="number" min="1000" step="500" name="<?php echo $opt; ?>[timeout_threshold_ms]"
                        value="<?php echo esc_attr((int) ($s['timeout_threshold_ms'] ?? 30000)); ?>" /></td>
                </tr>
                <tr>
                    <th><label>Auto-approve disputes ≤ (atomic)</label></th>
                    <td>
                        <input type="number" min="0" step="100" name="<?php echo $opt; ?>[auto_approve_threshold]"
                            value="<?php echo esc_attr((int) ($s['auto_approve_threshold'] ?? 10000)); ?>" />
                        <p class="description">Below this amount, qualifying disputes auto-refund without manual review.</p>
                    </td>
                </tr>
                <tr>
                    <th><label>Dispute notification email</label></th>
                    <td><input type="email" class="regular-text" name="<?php echo $opt; ?>[dispute_email]"
                        value="<?php echo esc_attr($s['dispute_email'] ?? get_option('admin_email')); ?>" /></td>
                </tr>
            </table>

            <h2>Abuse handling</h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th><label>Rate limit (req/min/agent)</label></th>
                    <td><input type="number" min="0" name="<?php echo $opt; ?>[abuse_rate_per_min]"
                        value="<?php echo esc_attr((int) ($s['abuse_rate_per_min'] ?? 120)); ?>" /></td>
                </tr>
                <tr>
                    <th><label>Auto-block after N abuse events</label></th>
                    <td><input type="number" min="1" name="<?php echo $opt; ?>[abuse_block_threshold]"
                        value="<?php echo esc_attr((int) ($s['abuse_block_threshold'] ?? 20)); ?>" /></td>
                </tr>
                <tr>
                    <th><label>Block window (seconds)</label></th>
                    <td><input type="number" min="60" name="<?php echo $opt; ?>[abuse_block_window]"
                        value="<?php echo esc_attr((int) ($s['abuse_block_window'] ?? 600)); ?>" /></td>
                </tr>
                <tr>
                    <th><label>Manual blocklist</label></th>
                    <td>
                        <textarea name="<?php echo $opt; ?>[abuse_blocklist]" rows="4" class="large-text code"
                            placeholder="One fingerprint or agent_id per line"><?php
                            echo esc_textarea($s['abuse_blocklist'] ?? '');
                        ?></textarea>
                    </td>
                </tr>
            </table>

            <h2>Plugin fee</h2>
            <p class="description">A 1% fee per settled transaction supports continued development of AgentPay. Accumulated fees sweep on a schedule. See the <strong>Fees</strong> tab for details and manual control.</p>
            <table class="form-table" role="presentation">
                <tr>
                    <th><label>Auto-sweep enabled</label></th>
                    <td>
                        <label><input type="checkbox" name="<?php echo $opt; ?>[fee_sweep_enabled]" value="1" <?php checked(!empty($s['fee_sweep_enabled'])); ?> />
                        Sweep accumulated fees daily once threshold is met</label>
                    </td>
                </tr>
                <tr>
                    <th><label>Sweep threshold (atomic)</label></th>
                    <td>
                        <input type="number" min="0" step="1" name="<?php echo $opt; ?>[fee_sweep_threshold]"
                            value="<?php echo esc_attr((int) ($s['fee_sweep_threshold'] ?? 1000000)); ?>" />
                        <p class="description">Don't sweep until pending fees reach this amount. 1,000,000 = $1.00 (default).</p>
                    </td>
                </tr>
            </table>

            <?php submit_button(); ?>
        </form>
        <?php
    }

    protected static function render_dashboard() {
        global $wpdb;
        $tbl = $wpdb->prefix . 'agentpay_transactions';
        $totals = $wpdb->get_row("
            SELECT
                COUNT(*) AS total,
                COALESCE(SUM(CASE WHEN status='paid' THEN amount_atomic ELSE 0 END),0) AS net_atomic,
                COALESCE(SUM(amount_atomic),0) AS gross_atomic,
                COALESCE(SUM(CASE WHEN status='refunded' THEN amount_atomic ELSE 0 END),0) AS refunded_atomic,
                COALESCE(SUM(CASE WHEN fee_reversed=0 THEN fee_atomic ELSE 0 END),0) AS fee_atomic,
                COUNT(DISTINCT agent_id) AS agents
            FROM {$tbl}
        ", ARRAY_A);

        $today = $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(amount_atomic),0) FROM {$tbl} WHERE status='paid' AND created_at > %s",
            gmdate('Y-m-d 00:00:00')
        ));

        $net_after_fees = (int) $totals['net_atomic'] - (int) $totals['fee_atomic'];

        $cards = [
            ['Net after fees',  '$' . number_format($net_after_fees / 1000000, 4)],
            ['Gross',           '$' . number_format($totals['gross_atomic'] / 1000000, 4)],
            ['Refunded',        '$' . number_format($totals['refunded_atomic'] / 1000000, 4)],
            ['Fees (1%)',       '$' . number_format($totals['fee_atomic'] / 1000000, 4)],
            ['Today',           '$' . number_format(((int) $today) / 1000000, 4)],
            ['Total requests',  number_format((int) $totals['total'])],
            ['Unique agents',   number_format((int) $totals['agents'])],
        ];

        echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin:20px 0;">';
        foreach ($cards as [$label, $value]) {
            echo '<div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:14px;">';
            echo '<div style="font-size:12px;color:#646970;">' . esc_html($label) . '</div>';
            echo '<div style="font-size:22px;font-weight:500;margin-top:4px;">' . esc_html($value) . '</div>';
            echo '</div>';
        }
        echo '</div>';

        $top_agents = $wpdb->get_results("
            SELECT agent_id, COUNT(*) AS n, SUM(amount_atomic) AS spent
            FROM {$tbl} GROUP BY agent_id ORDER BY spent DESC LIMIT 10
        ", ARRAY_A);

        echo '<h2>Top agents (by spend)</h2>';
        echo '<table class="widefat striped"><thead><tr><th>Agent</th><th>Requests</th><th>Spent (USDC)</th></tr></thead><tbody>';
        foreach ($top_agents as $r) {
            echo '<tr><td><code>' . esc_html($r['agent_id']) . '</code></td>';
            echo '<td>' . esc_html($r['n']) . '</td>';
            echo '<td>$' . esc_html(number_format($r['spent'] / 1000000, 4)) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    protected static function render_fees() {
        global $wpdb;

        $pending     = \AgentPay\FeeProcessor::pending_atomic();
        $swept       = \AgentPay\FeeProcessor::swept_total_atomic();
        $last_sweep  = \AgentPay\FeeProcessor::last_sweep_at();
        $rate        = \AgentPay\FeeProcessor::fee_rate_percent();
        $wallet      = \AgentPay\FeeProcessor::fee_wallet();
        $threshold   = (int) \AgentPay\Installer::setting('fee_sweep_threshold', 1000000);
        $sweep_enabled = (int) \AgentPay\Installer::setting('fee_sweep_enabled', 1);
        $fee_status  = \AgentPay\FeeConfig::status();

        echo '<h2>Plugin fee</h2>';
        echo '<p>AgentPay is open source and free to use. A <strong>' . esc_html(number_format($rate, 2)) .
             '%</strong> fee on each settled USDC transaction is accumulated and periodically transferred from your wallet to support continued development.';
        echo ' The fee is paid from your configured USDC wallet — no third party touches your funds in between.</p>';

        // Surface the FeeConfig status so operators understand if/why sweeps are paused.
        if (!$wallet) {
            echo '<div class="notice notice-warning inline" style="margin: 12px 0;"><p>';
            echo '<strong>Fee sweeps are currently paused.</strong> The plugin couldn\'t fetch a cryptographically verified fee wallet from ClearDesk SEO. Fees continue to accumulate in your wallet normally; they\'ll sweep automatically once the config endpoint is reachable again.';
            if (!empty($fee_status['last_error'])) {
                echo '<br><em>Last error: ' . esc_html($fee_status['last_error']) . '</em>';
            }
            echo '</p></div>';
        } elseif ($fee_status['source'] === 'grace') {
            echo '<div class="notice notice-info inline" style="margin: 12px 0;"><p>';
            echo 'Using last-known-good fee config (cleardeskseo.com endpoint temporarily unreachable). Plugin will retry on next sweep cycle.';
            echo '</p></div>';
        }

        echo '<table class="form-table" role="presentation">';
        echo '<tr><th>Fee rate</th><td>' . esc_html(number_format($rate, 2)) . '% per settled transaction</td></tr>';
        echo '<tr><th>Destination wallet (Base USDC)</th><td>';
        echo $wallet
            ? '<code>' . esc_html($wallet) . '</code>'
            : '<em>Not available — fee config endpoint unreachable. Sweeps paused.</em>';
        echo '</td></tr>';
        echo '<tr><th>Pending (not yet swept)</th><td><strong>$' . esc_html(number_format($pending / 1000000, 6)) . '</strong> USDC</td></tr>';
        echo '<tr><th>Lifetime swept</th><td>$' . esc_html(number_format($swept / 1000000, 6)) . ' USDC</td></tr>';
        echo '<tr><th>Last sweep</th><td>' . esc_html($last_sweep ?: '(never)') . '</td></tr>';
        echo '<tr><th>Sweep threshold</th><td>$' . esc_html(number_format($threshold / 1000000, 6)) . ' USDC';
        echo ' <span class="description">(configure in main settings)</span></td></tr>';
        echo '<tr><th>Auto-sweep</th><td>' . ($sweep_enabled ? 'Enabled (daily via WP-Cron)' : '<span style="color:#a62a2a;">Disabled</span>') . '</td></tr>';
        echo '</table>';

        $sweep_url = wp_nonce_url(
            admin_url('admin-post.php?action=agentpay_sweep_fees'),
            'agentpay_sweep_fees'
        );
        echo '<p style="margin-top:20px;">';
        if ($pending > 0) {
            echo '<a href="' . esc_url($sweep_url) . '" class="button button-primary">Sweep ' .
                 esc_html('$' . number_format($pending / 1000000, 6)) . ' now</a>';
        } else {
            echo '<button class="button" disabled>Sweep now (nothing pending)</button>';
        }
        echo '</p>';

        $result_msg = get_transient('agentpay_sweep_result');
        if ($result_msg) {
            delete_transient('agentpay_sweep_result');
            echo '<div class="notice notice-info"><p>' . esc_html($result_msg) . '</p></div>';
        }

        $sweep_rows = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}agentpay_fee_sweeps ORDER BY id DESC LIMIT 50",
            ARRAY_A
        );

        echo '<h2 style="margin-top:30px;">Sweep history</h2>';
        if (!$sweep_rows) {
            echo '<p>No sweeps yet.</p>';
            return;
        }
        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>ID</th><th>Amount</th><th>Transactions</th><th>Period</th>';
        echo '<th>Status</th><th>On-chain TX</th><th>Created</th>';
        echo '</tr></thead><tbody>';
        foreach ($sweep_rows as $r) {
            echo '<tr>';
            echo '<td>' . esc_html($r['id']) . '</td>';
            echo '<td>$' . esc_html(number_format(((int) $r['amount_atomic']) / 1000000, 6)) . '</td>';
            echo '<td>' . esc_html($r['tx_count']) . '</td>';
            echo '<td style="font-size:11px;">' . esc_html(substr($r['period_start'], 0, 10)) .
                 ' → ' . esc_html(substr($r['period_end'], 0, 10)) . '</td>';
            $status_color = ['completed' => '#007a3d', 'failed' => '#a62a2a', 'pending' => '#9c6f00'][$r['status']] ?? '#646970';
            echo '<td><span style="color:' . $status_color . ';">' . esc_html($r['status']) . '</span></td>';
            echo '<td><code style="font-size:11px;">' . esc_html(substr($r['sweep_tx'] ?: '', 0, 20)) .
                 ($r['sweep_tx'] && strlen($r['sweep_tx']) > 20 ? '…' : '') . '</code></td>';
            echo '<td style="font-size:11px;">' . esc_html($r['created_at']) . '</td>';
            echo '</tr>';
            if (!empty($r['error_msg'])) {
                echo '<tr><td colspan="7" style="background:#fcf0f0;font-family:monospace;font-size:11px;">'
                    . esc_html($r['error_msg']) . '</td></tr>';
            }
        }
        echo '</tbody></table>';
    }

    protected static function render_disputes() {
        global $wpdb;
        $rows = $wpdb->get_results("
            SELECT d.*, t.resource, t.amount_atomic, t.agent_address
            FROM {$wpdb->prefix}agentpay_disputes d
            LEFT JOIN {$wpdb->prefix}agentpay_transactions t ON t.tx_hash = d.tx_hash
            ORDER BY d.id DESC LIMIT 100
        ", ARRAY_A);

        echo '<h2>Disputes</h2>';
        if (!$rows) { echo '<p>No disputes filed.</p>'; return; }

        echo '<table class="widefat striped"><thead><tr>
            <th>ID</th><th>TX</th><th>Agent</th><th>Reason</th><th>Amount</th><th>Status</th><th>Filed</th><th>Action</th>
        </tr></thead><tbody>';
        foreach ($rows as $r) {
            $url = wp_nonce_url(
                admin_url('admin-post.php?action=agentpay_resolve_dispute&id=' . $r['id']),
                'agentpay_resolve_' . $r['id']
            );
            echo '<tr>';
            echo '<td>' . esc_html($r['id']) . '</td>';
            echo '<td><code>' . esc_html(substr($r['tx_hash'], 0, 16)) . '…</code></td>';
            echo '<td><code>' . esc_html($r['agent_id']) . '</code></td>';
            echo '<td>' . esc_html($r['reason']) . '</td>';
            echo '<td>$' . esc_html(number_format(((int) $r['amount_atomic']) / 1000000, 4)) . '</td>';
            echo '<td>' . esc_html($r['status']) . ($r['resolution'] ? ' (' . esc_html($r['resolution']) . ')' : '') . '</td>';
            echo '<td>' . esc_html($r['created_at']) . '</td>';
            echo '<td>';
            if ($r['status'] === 'open') {
                echo '<a class="button button-primary" href="' . esc_url($url . '&resolution=refund') . '">Refund</a> ';
                echo '<a class="button" href="' . esc_url($url . '&resolution=deny') . '">Deny</a>';
            } else {
                echo '—';
            }
            echo '</td></tr>';
            if (!empty($r['evidence'])) {
                echo '<tr><td colspan="8" style="background:#f6f7f7;font-family:monospace;font-size:12px;">' . esc_html($r['evidence']) . '</td></tr>';
            }
        }
        echo '</tbody></table>';
    }

    protected static function render_logs() {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}agentpay_transactions ORDER BY id DESC LIMIT 200",
            ARRAY_A
        );
        echo '<h2>Recent transactions</h2>';
        if (!$rows) { echo '<p>No transactions yet.</p>'; return; }
        echo '<table class="widefat striped"><thead><tr>
            <th>TX</th><th>Agent</th><th>Resource</th><th>Amount</th><th>Status</th><th>Refund</th><th>When</th>
        </tr></thead><tbody>';
        foreach ($rows as $r) {
            echo '<tr>';
            echo '<td><code>' . esc_html(substr($r['tx_hash'], 0, 12)) . '…</code></td>';
            echo '<td><code style="font-size:11px;">' . esc_html($r['agent_id']) . '</code></td>';
            echo '<td style="max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><code style="font-size:11px;">' . esc_html($r['resource']) . '</code></td>';
            echo '<td>$' . esc_html(number_format(((int) $r['amount_atomic']) / 1000000, 4)) . '</td>';
            echo '<td>' . esc_html($r['status']) . '</td>';
            echo '<td>' . esc_html($r['refund_reason'] ?: '') . '</td>';
            echo '<td>' . esc_html($r['created_at']) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    protected static function render_setup() {
        // Delegates to the dedicated AdminSetup class (since v1.1.0) which
        // implements the in-admin wallet provisioning flow: three-field credential
        // form, "Create wallet" vs "Use existing", loud Wallet Secret modal, and
        // success state with the receiving address — all without requiring the
        // operator to touch Node, the CDP SDK, or the CDP CLI.
        if (class_exists('\AgentPay\AdminSetup')) {
            \AgentPay\AdminSetup::render_setup_tab();
            return;
        }
        echo '<p>Setup tab unavailable: AdminSetup class missing. Reinstall the plugin.</p>';
    }


    protected static function render_docs() {
        $rate_url = rest_url('agentpay/v1/rate-card');
        $disp_url = rest_url('agentpay/v1/dispute');
        ?>
        <h2>Endpoints for agents</h2>
        <p>Publish these to your <code>.well-known</code> or robots.txt so agents can discover the paywall:</p>
        <table class="widefat">
            <tr><td><strong>Rate card</strong></td><td><code><?php echo esc_html($rate_url); ?></code></td></tr>
            <tr><td><strong>Dispute submission</strong></td><td><code>POST <?php echo esc_html($disp_url); ?></code></td></tr>
            <tr><td><strong>Dispute status</strong></td><td><code>GET <?php echo esc_html($disp_url); ?>?tx_hash=…</code></td></tr>
        </table>
        <h3>Payment flow</h3>
        <ol>
            <li>Agent requests any URL. Plugin detects via Web Bot Auth signature or known UA.</li>
            <li>Plugin returns <code>HTTP 402</code> with x402 requirements JSON.</li>
            <li>Agent signs USDC transfer authorization, retries with <code>X-PAYMENT</code> header.</li>
            <li>Plugin POSTs to Coinbase facilitator for verify and settle.</li>
            <li>Plugin issues HMAC session token in <code>X-PAYMENT-RESPONSE</code> header.</li>
            <li>Subsequent requests use <code>Authorization: Bearer &lt;token&gt;</code> until budget/TTL expires.</li>
        </ol>
        <h3>Refund triggers</h3>
        <ul style="list-style:disc;padding-left:20px;">
            <li><strong>Automatic</strong>: 404, 5xx, or response time > timeout threshold.</li>
            <li><strong>On dispute</strong>: agent POSTs to dispute endpoint with reason and evidence.</li>
            <li><strong>Auto-approval</strong>: disputes below threshold for qualifying reasons refund immediately.</li>
        </ul>
        <?php
    }

    public static function handle_test_facilitator() {
        if (!current_user_can('manage_options')) { wp_die('Forbidden'); }
        check_admin_referer('agentpay_test');

        $url = rtrim(Installer::setting('facilitator_url', ''), '/') . '/supported';
        $resp = wp_remote_get($url, ['timeout' => 10]);

        if (is_wp_error($resp)) {
            $msg = 'Error: ' . $resp->get_error_message();
        } else {
            $code = wp_remote_retrieve_response_code($resp);
            $body = wp_remote_retrieve_body($resp);
            $msg = "HTTP {$code}: " . substr($body, 0, 200);
        }

        set_transient('agentpay_test_result', $msg, 60);
        wp_safe_redirect(admin_url('tools.php?page=agentpay&tab=config'));
        exit;
    }

    public static function handle_resolve_dispute() {
        if (!current_user_can('manage_options')) { wp_die('Forbidden'); }
        $id = (int) ($_GET['id'] ?? 0);
        check_admin_referer('agentpay_resolve_' . $id);

        $resolution = ($_GET['resolution'] ?? '') === 'refund' ? 'refund' : 'deny';
        Dispute::resolve($id, $resolution, 'manual:' . wp_get_current_user()->user_login);

        wp_safe_redirect(admin_url('tools.php?page=agentpay&tab=disputes'));
        exit;
    }

    public static function handle_sweep_fees() {
        if (!current_user_can('manage_options')) { wp_die('Forbidden'); }
        check_admin_referer('agentpay_sweep_fees');

        $result = FeeProcessor::sweep();

        if (!empty($result['ok'])) {
            $msg = sprintf(
                'Sweep complete: $%s sent in tx %s (covered %d transactions).',
                number_format(((int) $result['amount_atomic']) / 1000000, 6),
                $result['tx_hash'] ?: '(pending)',
                (int) ($result['tx_count'] ?? 0)
            );
        } elseif (!empty($result['skipped'])) {
            $msg = 'No sweep performed: ' . $result['skipped'];
        } else {
            $msg = 'Sweep failed: ' . ($result['message'] ?? $result['error'] ?? 'unknown error');
        }

        set_transient('agentpay_sweep_result', $msg, 60);
        wp_safe_redirect(admin_url('tools.php?page=agentpay&tab=fees'));
        exit;
    }
}
