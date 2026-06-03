<?php
namespace ClearWallet;

if (!defined('ABSPATH')) { exit; }

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- transactional plugin; wp_cache would yield stale reads of in-flight transactions/disputes/fee sweeps. Hot paths already use $wpdb->prepare() for all user data.
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$tbl}/{$prefix} interpolation is the plugin's own table name ($wpdb->prefix . 'clearwallet_*'), not user input. WP 6.0 baseline can't use the %i identifier placeholder added in 6.2.
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter -- same rationale as InterpolatedNotPrepared above.


class Admin {

    public static function add_menu() {
        add_management_page(
            'ClearWallet',
            'ClearWallet',
            'manage_options',
            'clearwallet',
            [__CLASS__, 'render_page']
        );
    }

    public static function register_settings() {
        register_setting('clearwallet_group', CLEARWALLET_OPT, [
            'sanitize_callback' => [__CLASS__, 'sanitize'],
        ]);
    }

    public static function sanitize($input) {
        $out = get_option(CLEARWALLET_OPT, []);
        if (!is_array($input)) { return $out; }

        $bool = ['enabled', 'auto_refund_404', 'auto_refund_5xx', 'auto_refund_timeout',
                 'detector_strict'];
        foreach ($bool as $k) { $out[$k] = !empty($input[$k]) ? 1 : 0; }

        // Per-field sanitization. sanitize_text_field is wrong for URLs (strips
        // some valid chars), emails (doesn't validate format), and secrets/keys
        // (collapses the newlines that PEM-encoded keys structurally require).
        $url_fields    = ['facilitator_url'];
        $email_fields  = ['dispute_email'];
        $wallet_fields = ['payto_wallet'];
        $secret_fields = [];
        $text_fields   = ['network', 'usdc_contract'];
        $textarea_fields = ['abuse_blocklist'];

        foreach ($url_fields as $k) {
            if (isset($input[$k])) { $out[$k] = esc_url_raw(wp_unslash($input[$k])); }
        }
        foreach ($email_fields as $k) {
            if (isset($input[$k])) {
                $email = sanitize_email(wp_unslash($input[$k]));
                $out[$k] = is_email($email) ? $email : '';
            }
        }
        foreach ($wallet_fields as $k) {
            if (isset($input[$k])) {
                // Wallet addresses are alphanumeric only (EVM hex or Stripe acct_/wallet_)
                $val = sanitize_text_field(wp_unslash($input[$k]));
                $out[$k] = preg_replace('/[^A-Za-z0-9_\-]/', '', $val);
            }
        }
        foreach ($secret_fields as $k) {
            // Keys/secrets may contain PEM newlines, base64, or hex. Strip only
            // null bytes and control chars (except LF/TAB); keep everything else
            // so the underlying format isn't corrupted before encryption.
            if (isset($input[$k])) { $out[$k] = self::sanitize_secret(wp_unslash($input[$k])); }
        }
        foreach ($text_fields as $k) {
            if (isset($input[$k])) { $out[$k] = sanitize_text_field(wp_unslash($input[$k])); }
        }
        foreach ($textarea_fields as $k) {
            if (isset($input[$k])) { $out[$k] = sanitize_textarea_field(wp_unslash($input[$k])); }
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

    /**
     * Sanitize a secret/key value while preserving PEM newlines and base64
     * characters. Strips only null bytes and control chars other than LF/TAB.
     * sanitize_text_field collapses whitespace and would corrupt PEM keys.
     */
    private static function sanitize_secret($value) {
        if (!is_string($value)) { return ''; }
        $value = str_replace("\0", '', $value);                 // null bytes
        $value = str_replace("\r\n", "\n", $value);             // normalize line endings
        $value = preg_replace('/[^\x09\x0A\x20-\x7E]/', '', $value);  // tab/LF/printable ASCII only
        if (strlen($value) > 8192) {
            $value = substr($value, 0, 8192);
        }
        return trim($value);
    }

    public static function render_page() {
        if (!current_user_can('manage_options')) { wp_die('Forbidden'); }
        // Whitelist allowed tab values to prevent any reflected-XSS or path
        // tampering via $_GET['tab']. UI-only router parameter, no state change.
        $allowed_tabs = ['config', 'dashboard', 'fees', 'disputes', 'logs', 'setup', 'docs'];
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- UI-only tab router, no state change
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'config';
        if (!in_array($tab, $allowed_tabs, true)) { $tab = 'config'; }

        echo '<div class="wrap"><h1>ClearWallet <span style="font-size:14px;color:#646970;font-weight:normal;">by ClearDesk SEO</span></h1>';
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
            'setup'     => 'Wallet Setup',
            'docs'      => 'Agent docs',
        ];
        echo '<h2 class="nav-tab-wrapper">';
        foreach ($tabs as $key => $label) {
            $active = $key === $current ? ' nav-tab-active' : '';
            $url = admin_url('tools.php?page=clearwallet&tab=' . $key);
            echo '<a href="' . esc_url($url) . '" class="nav-tab' . esc_attr($active) . '">' . esc_html($label) . '</a>';
        }
        echo '</h2>';
    }

    protected static function render_config() {
        $s = get_option(CLEARWALLET_OPT, []);
        $opt = CLEARWALLET_OPT;
        ?>
        <form method="post" action="options.php">
            <?php settings_fields('clearwallet_group'); ?>

            <h2>General</h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th><label>Enable agent paywall</label></th>
                    <td>
                        <label><input type="checkbox" name="<?php echo esc_attr($opt); ?>[enabled]" value="1" <?php checked(!empty($s['enabled'])); ?> />
                        Intercept agent requests and require payment</label>
                    </td>
                </tr>
                <tr>
                    <th><label for="payto_wallet">PayTo wallet (Base USDC)</label></th>
                    <td>
                        <input id="payto_wallet" type="text" class="regular-text code"
                            name="<?php echo esc_attr($opt); ?>[payto_wallet]"
                            value="<?php echo esc_attr($s['payto_wallet'] ?? ''); ?>"
                            placeholder="0x..." />
                        <p class="description">Your USDC-on-Base wallet address. Funds settle here before off-ramp.
                            <a href="<?php echo esc_url(admin_url('tools.php?page=clearwallet&tab=setup#payto-wallet')); ?>">Where do I find this?</a></p>
                    </td>
                </tr>
                <tr>
                    <th><label>Network</label></th>
                    <td>
                        <select name="<?php echo esc_attr($opt); ?>[network]">
                            <option value="base" <?php selected($s['network'] ?? 'base', 'base'); ?>>Base mainnet</option>
                            <option value="base-sepolia" <?php selected($s['network'] ?? '', 'base-sepolia'); ?>>Base Sepolia (testnet)</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label>USDC contract</label></th>
                    <td>
                        <input type="text" class="regular-text code" name="<?php echo esc_attr($opt); ?>[usdc_contract]"
                            value="<?php echo esc_attr($s['usdc_contract'] ?? ''); ?>" />
                    </td>
                </tr>
                <tr>
                    <th><label>Strict detector</label></th>
                    <td>
                        <label><input type="checkbox" name="<?php echo esc_attr($opt); ?>[detector_strict]" value="1" <?php checked(!empty($s['detector_strict'])); ?> />
                        Also gate on headless-browser heuristics (may catch false positives)</label>
                    </td>
                </tr>
            </table>

            <h2>Coinbase facilitator</h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th><label>Facilitator URL</label></th>
                    <td>
                        <input type="url" class="regular-text code" name="<?php echo esc_attr($opt); ?>[facilitator_url]"
                            value="<?php echo esc_attr($s['facilitator_url'] ?? ''); ?>" />
                        <p class="description">Leave blank to auto-select by network: <code>www.x402.org</code> on Base Sepolia (no auth), <code>api.cdp.coinbase.com</code> on Base mainnet (uses your CDP credentials from the Setup tab). Override only for a custom facilitator.</p>
                    </td>
                </tr>
                <tr>
                    <th></th>
                    <td>
                        <?php
                        $url = wp_nonce_url(
                            admin_url('admin-post.php?action=clearwallet_test_facilitator'),
                            'clearwallet_test'
                        );
                        ?>
                        <a href="<?php echo esc_url($url); ?>" class="button">Test facilitator connection</a>
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
                    <th><label><?php echo esc_html(ucfirst($action)); ?> (atomic)</label></th>
                    <td>
                        <input type="number" min="0" step="1" name="<?php echo esc_attr($opt); ?>[rate_card][<?php echo esc_attr($action); ?>]"
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
                    <td><input type="number" min="60" name="<?php echo esc_attr($opt); ?>[session_ttl]"
                        value="<?php echo esc_attr((int) ($s['session_ttl'] ?? 3600)); ?>" /></td>
                </tr>
                <tr>
                    <th><label>Pages per session</label></th>
                    <td><input type="number" min="1" name="<?php echo esc_attr($opt); ?>[session_page_budget]"
                        value="<?php echo esc_attr((int) ($s['session_page_budget'] ?? 50)); ?>" /></td>
                </tr>
            </table>

            <h2>Refund policy</h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th>Auto-refund</th>
                    <td>
                        <label><input type="checkbox" name="<?php echo esc_attr($opt); ?>[auto_refund_404]" value="1" <?php checked(!empty($s['auto_refund_404'])); ?> /> 404 (not found)</label><br>
                        <label><input type="checkbox" name="<?php echo esc_attr($opt); ?>[auto_refund_5xx]" value="1" <?php checked(!empty($s['auto_refund_5xx'])); ?> /> 5xx (server error)</label><br>
                        <label><input type="checkbox" name="<?php echo esc_attr($opt); ?>[auto_refund_timeout]" value="1" <?php checked(!empty($s['auto_refund_timeout'])); ?> /> Timeout (slow response)</label>
                    </td>
                </tr>
                <tr>
                    <th><label>Timeout threshold (ms)</label></th>
                    <td><input type="number" min="1000" step="500" name="<?php echo esc_attr($opt); ?>[timeout_threshold_ms]"
                        value="<?php echo esc_attr((int) ($s['timeout_threshold_ms'] ?? 30000)); ?>" /></td>
                </tr>
                <tr>
                    <th><label>Auto-approve disputes ≤ (atomic)</label></th>
                    <td>
                        <input type="number" min="0" step="100" name="<?php echo esc_attr($opt); ?>[auto_approve_threshold]"
                            value="<?php echo esc_attr((int) ($s['auto_approve_threshold'] ?? 10000)); ?>" />
                        <p class="description">Below this amount, qualifying disputes auto-refund without manual review.</p>
                    </td>
                </tr>
                <tr>
                    <th><label>Dispute notification email</label></th>
                    <td><input type="email" class="regular-text" name="<?php echo esc_attr($opt); ?>[dispute_email]"
                        value="<?php echo esc_attr($s['dispute_email'] ?? get_option('admin_email')); ?>" /></td>
                </tr>
            </table>

            <h2>Abuse handling</h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th><label>Rate limit (req/min/agent)</label></th>
                    <td><input type="number" min="0" name="<?php echo esc_attr($opt); ?>[abuse_rate_per_min]"
                        value="<?php echo esc_attr((int) ($s['abuse_rate_per_min'] ?? 120)); ?>" /></td>
                </tr>
                <tr>
                    <th><label>Auto-block after N abuse events</label></th>
                    <td><input type="number" min="1" name="<?php echo esc_attr($opt); ?>[abuse_block_threshold]"
                        value="<?php echo esc_attr((int) ($s['abuse_block_threshold'] ?? 20)); ?>" /></td>
                </tr>
                <tr>
                    <th><label>Block window (seconds)</label></th>
                    <td><input type="number" min="60" name="<?php echo esc_attr($opt); ?>[abuse_block_window]"
                        value="<?php echo esc_attr((int) ($s['abuse_block_window'] ?? 600)); ?>" /></td>
                </tr>
                <tr>
                    <th><label>Manual blocklist</label></th>
                    <td>
                        <textarea name="<?php echo esc_attr($opt); ?>[abuse_blocklist]" rows="4" class="large-text code"
                            placeholder="One fingerprint or agent_id per line"><?php
                            echo esc_textarea($s['abuse_blocklist'] ?? '');
                        ?></textarea>
                    </td>
                </tr>
            </table>

            <h2>Plugin fee</h2>
            <p class="description">A 1% fee per settled transaction supports continued development of ClearWallet. Accumulated fees sweep on a schedule. See the <strong>Fees</strong> tab for details and manual control.</p>
            <table class="form-table" role="presentation">
                <tr>
                    <th><label>Auto-sweep enabled</label></th>
                    <td>
                        <label><input type="checkbox" name="<?php echo esc_attr($opt); ?>[fee_sweep_enabled]" value="1" <?php checked(!empty($s['fee_sweep_enabled'])); ?> />
                        Sweep accumulated fees daily once threshold is met</label>
                    </td>
                </tr>
                <tr>
                    <th><label>Sweep threshold (atomic)</label></th>
                    <td>
                        <input type="number" min="0" step="1" name="<?php echo esc_attr($opt); ?>[fee_sweep_threshold]"
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
        $tbl = $wpdb->prefix . 'clearwallet_transactions';
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

        $pending     = \ClearWallet\FeeProcessor::pending_atomic();
        $swept       = \ClearWallet\FeeProcessor::swept_total_atomic();
        $last_sweep  = \ClearWallet\FeeProcessor::last_sweep_at();
        $rate        = \ClearWallet\FeeProcessor::fee_rate_percent();
        $wallet      = \ClearWallet\FeeProcessor::fee_wallet();
        $threshold   = (int) \ClearWallet\Installer::setting('fee_sweep_threshold', 1000000);
        $sweep_enabled = (int) \ClearWallet\Installer::setting('fee_sweep_enabled', 1);
        $fee_status  = \ClearWallet\FeeConfig::status();

        echo '<h2>Plugin fee</h2>';
        echo '<p>ClearWallet is open source and free to use. A <strong>' . esc_html(number_format($rate, 2)) .
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
        if ($wallet) {
            echo '<code>' . esc_html($wallet) . '</code>';
        } else {
            echo '<em>Not available — fee config endpoint unreachable. Sweeps paused.</em>';
        }
        echo '</td></tr>';
        echo '<tr><th>Pending (not yet swept)</th><td><strong>$' . esc_html(number_format($pending / 1000000, 6)) . '</strong> USDC</td></tr>';
        echo '<tr><th>Lifetime swept</th><td>$' . esc_html(number_format($swept / 1000000, 6)) . ' USDC</td></tr>';
        echo '<tr><th>Last sweep</th><td>' . esc_html($last_sweep ?: '(never)') . '</td></tr>';
        echo '<tr><th>Sweep threshold</th><td>$' . esc_html(number_format($threshold / 1000000, 6)) . ' USDC';
        echo ' <span class="description">(configure in main settings)</span></td></tr>';
        echo '<tr><th>Auto-sweep</th><td>' . ($sweep_enabled ? 'Enabled (daily via WP-Cron)' : '<span style="color:#a62a2a;">Disabled</span>') . '</td></tr>';
        echo '</table>';

        $sweep_url = wp_nonce_url(
            admin_url('admin-post.php?action=clearwallet_sweep_fees'),
            'clearwallet_sweep_fees'
        );
        echo '<p style="margin-top:20px;">';
        if ($pending > 0) {
            echo '<a href="' . esc_url($sweep_url) . '" class="button button-primary">Sweep ' .
                 esc_html('$' . number_format($pending / 1000000, 6)) . ' now</a>';
        } else {
            echo '<button class="button" disabled>Sweep now (nothing pending)</button>';
        }
        echo '</p>';

        $result_msg = get_transient('clearwallet_sweep_result');
        if ($result_msg) {
            delete_transient('clearwallet_sweep_result');
            echo '<div class="notice notice-info"><p>' . esc_html($result_msg) . '</p></div>';
        }

        $sweep_rows = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}clearwallet_fee_sweeps ORDER BY id DESC LIMIT 50",
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
            echo '<td><span style="color:' . esc_attr($status_color) . ';">' . esc_html($r['status']) . '</span></td>';
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
            FROM {$wpdb->prefix}clearwallet_disputes d
            LEFT JOIN {$wpdb->prefix}clearwallet_transactions t ON t.tx_hash = d.tx_hash
            ORDER BY d.id DESC LIMIT 100
        ", ARRAY_A);

        echo '<h2>Disputes</h2>';
        if (!$rows) { echo '<p>No disputes filed.</p>'; return; }

        echo '<table class="widefat striped"><thead><tr>
            <th>ID</th><th>TX</th><th>Agent</th><th>Reason</th><th>Amount</th><th>Status</th><th>Filed</th><th>Action</th>
        </tr></thead><tbody>';
        foreach ($rows as $r) {
            $url = wp_nonce_url(
                admin_url('admin-post.php?action=clearwallet_resolve_dispute&id=' . $r['id']),
                'clearwallet_resolve_' . $r['id']
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
        // Cash-out panel (moved here from the Wallet Setup tab in v1.4.14). It
        // renders nothing until a receiving wallet is connected.
        if (class_exists('\\ClearWallet\\AdminSetup')) {
            \ClearWallet\AdminSetup::render_cashout_panel();
        }

        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}clearwallet_transactions ORDER BY id DESC LIMIT 200",
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
        if (class_exists('\ClearWallet\AdminSetup')) {
            \ClearWallet\AdminSetup::render_setup_tab();
            return;
        }
        echo '<p>Setup tab unavailable: AdminSetup class missing. Reinstall the plugin.</p>';
    }


    protected static function render_docs() {
        $rate_url = rest_url('clearwallet/v1/rate-card');
        $disp_url = rest_url('clearwallet/v1/dispute');
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
        check_admin_referer('clearwallet_test');

        $url = Facilitator::facilitator_base() . '/supported';
        $resp = wp_remote_get($url, ['timeout' => 10]);

        if (is_wp_error($resp)) {
            $msg = 'Error: ' . $resp->get_error_message();
        } else {
            $code = wp_remote_retrieve_response_code($resp);
            $body = wp_remote_retrieve_body($resp);
            $msg = "Facilitator " . esc_html($url) . " — HTTP {$code}: " . substr($body, 0, 200);
        }

        set_transient('clearwallet_test_result', $msg, 60);
        wp_safe_redirect(admin_url('tools.php?page=clearwallet&tab=config'));
        exit;
    }

    public static function handle_resolve_dispute() {
        if (!current_user_can('manage_options')) { wp_die('Forbidden'); }
        // Sanitize id first so the nonce check uses a known-clean value.
        $id = isset($_GET['id']) ? absint(wp_unslash($_GET['id'])) : 0;
        check_admin_referer('clearwallet_resolve_' . $id);

        // Resolution is whitelisted against two valid values, so anything else
        // collapses to 'deny'. wp_unslash + sanitize_key keeps PluginCheck happy.
        $resolution_raw = isset($_GET['resolution']) ? sanitize_key(wp_unslash($_GET['resolution'])) : '';
        $resolution = ($resolution_raw === 'refund') ? 'refund' : 'deny';
        Dispute::resolve($id, $resolution, 'manual:' . wp_get_current_user()->user_login);

        wp_safe_redirect(admin_url('tools.php?page=clearwallet&tab=disputes'));
        exit;
    }

    public static function handle_sweep_fees() {
        if (!current_user_can('manage_options')) { wp_die('Forbidden'); }
        check_admin_referer('clearwallet_sweep_fees');

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

        set_transient('clearwallet_sweep_result', $msg, 60);
        wp_safe_redirect(admin_url('tools.php?page=clearwallet&tab=fees'));
        exit;
    }
}
