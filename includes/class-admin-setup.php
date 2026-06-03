<?php
/**
 * Setup Tab — Wallet provisioning UX
 *
 * Replaces the old credential-only Setup tab with a guided three-field
 * connection flow that provisions a fresh CDP wallet (or attaches to an
 * existing address) without any terminal, SDK, or CLI work.
 *
 * Integration:
 *   1. Drop this file into clearwallet/includes/.
 *   2. In clearwallet/clearwallet.php (or wherever CLEARWALLET classes are loaded), add:
 *        require_once CLEARWALLET_PATH . 'includes/class-admin-setup.php';
 *        new \ClearWallet\AdminSetup();
 *   3. In your existing class-admin.php, replace the body of render_setup_tab()
 *      with a single call: AdminSetup::render_setup_tab();
 *
 * Stored options:
 *   clearwallet_cdp_api_key_id      Plaintext (UUID, not a secret).
 *   clearwallet_cdp_api_key_secret  Encrypted via wp_salt-derived key.
 *   clearwallet_cdp_wallet_secret   Encrypted, same scheme.
 *   clearwallet_receiving_address   Plaintext (0x... address — public info).
 *   clearwallet_wallet_created_at   Unix timestamp.
 *
 * @package ClearWallet
 * @since   1.1.0
 */

namespace ClearWallet;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AdminSetup {

	const NONCE_ACTION   = 'clearwallet_setup';
	const CAPABILITY     = 'manage_options';
	const PORTAL_URL     = 'https://portal.cdp.coinbase.com/';
	const HELP_API_KEY   = 'https://portal.cdp.coinbase.com/access/api';
	const HELP_WALLET    = 'https://portal.cdp.coinbase.com/access/api/wallet-secret';

	public function __construct() {
		add_action( 'wp_ajax_clearwallet_test_connection', array( $this, 'ajax_test_connection' ) );
		add_action( 'wp_ajax_clearwallet_create_wallet',   array( $this, 'ajax_create_wallet' ) );
		add_action( 'wp_ajax_clearwallet_use_existing',    array( $this, 'ajax_use_existing' ) );
		add_action( 'wp_ajax_clearwallet_disconnect',      array( $this, 'ajax_disconnect' ) );
		add_action( 'wp_ajax_clearwallet_balance',         array( $this, 'ajax_balance' ) );
		add_action( 'wp_ajax_clearwallet_withdraw',        array( $this, 'ajax_withdraw' ) );
		add_action( 'admin_enqueue_scripts',            array( $this, 'enqueue_assets' ) );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Tab renderer — call from existing Admin class
	// ─────────────────────────────────────────────────────────────────────────

	public static function render_setup_tab() {
		$address = get_option( 'clearwallet_receiving_address', '' );
		echo '<div class="clearwallet-setup">';
		if ( $address ) {
			self::render_configured_state( $address );
		} else {
			self::render_empty_state();
		}
		self::render_modals();
		echo '</div>';
	}

	/**
	 * "Cash out your balance" panel. Lives on the Transactions tab. Uses the
	 * admin-setup CSS/JS (enqueued on every ClearWallet admin screen), so it is
	 * wrapped in .clearwallet-setup for styling. Renders nothing until a
	 * receiving wallet is connected.
	 */
	public static function render_cashout_panel() {
		$address = get_option( 'clearwallet_receiving_address', '' );
		if ( empty( $address ) ) {
			return;
		}
		?>
		<div class="clearwallet-setup">
			<div class="ap-cashout">
				<h3><?php esc_html_e( 'Cash out your balance', 'agentpay-by-cleardesk-seo' ); ?></h3>
				<p class="ap-cashout-lede">
					<?php esc_html_e( 'Your earnings are held as USDC in your wallet. Two ways to turn them into dollars:', 'agentpay-by-cleardesk-seo' ); ?>
				</p>
				<ul class="ap-cashout-options">
					<li><?php esc_html_e( 'Pay out to your bank directly — requires a Coinbase Business (Portal) account with payouts enabled.', 'agentpay-by-cleardesk-seo' ); ?></li>
					<li><?php esc_html_e( 'Or send your USDC to your personal Coinbase account below, then sell it for USD and withdraw to your bank. No Business account needed, and gas is free.', 'agentpay-by-cleardesk-seo' ); ?></li>
				</ul>

				<div class="ap-cashout-balance">
					<?php esc_html_e( 'Available:', 'agentpay-by-cleardesk-seo' ); ?>
					<strong id="ap-balance">…</strong> <?php esc_html_e( 'USDC', 'agentpay-by-cleardesk-seo' ); ?>
					<span id="ap-cashout-fee" class="ap-cashout-fee"></span>
				</div>

				<div class="ap-field">
					<label for="ap-withdraw-to"><?php esc_html_e( 'Your Coinbase USDC deposit address (Base network)', 'agentpay-by-cleardesk-seo' ); ?></label>
					<input type="text" id="ap-withdraw-to" class="regular-text code" placeholder="0x…" autocomplete="off" spellcheck="false" />
				</div>
				<div class="ap-field">
					<label for="ap-withdraw-amount"><?php esc_html_e( 'Amount (USDC)', 'agentpay-by-cleardesk-seo' ); ?></label>
					<input type="text" id="ap-withdraw-amount" class="regular-text code" placeholder="0.00" autocomplete="off" inputmode="decimal" />
					<button type="button" class="button-link" id="ap-withdraw-max"><?php esc_html_e( 'Max', 'agentpay-by-cleardesk-seo' ); ?></button>
				</div>

				<div class="ap-withdraw-actions">
					<button type="button" class="button button-primary" id="ap-btn-withdraw">
						<?php esc_html_e( 'Send to Coinbase', 'agentpay-by-cleardesk-seo' ); ?>
					</button>
					<span class="ap-spinner"></span>
				</div>
				<div id="ap-withdraw-status" class="ap-status" role="status" aria-live="polite"></div>

				<p class="ap-cashout-fineprint">
					<?php esc_html_e( 'On Coinbase, choose USDC and select the Base network when copying your deposit address. Selling USDC for USD can be a taxable event — keep your records.', 'agentpay-by-cleardesk-seo' ); ?>
				</p>
			</div>
		</div>
		<?php
	}

	private static function render_empty_state() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- UI-only mode toggle, no state change
		$mode_raw = isset( $_GET['clearwallet_mode'] ) ? sanitize_key( wp_unslash( $_GET['clearwallet_mode'] ) ) : '';
		$mode     = ( 'existing' === $mode_raw ) ? 'existing' : 'create';
		?>
		<header class="ap-setup-header">
			<h2><?php esc_html_e( 'Connect your Coinbase wallet', 'agentpay-by-cleardesk-seo' ); ?></h2>
			<p class="ap-setup-lede">
				<?php esc_html_e( 'ClearWallet needs a wallet to receive payments from AI agents and to issue refunds when pages break. We\'ll create one for you in your own Coinbase Developer account — you keep custody, we just point the plugin at it.', 'agentpay-by-cleardesk-seo' ); ?>
			</p>

			<nav class="ap-setup-tabs">
				<a href="<?php echo esc_url( self::tab_url( 'create' ) ); ?>"
				   class="<?php echo 'create' === $mode ? 'is-active' : ''; ?>">
					<?php esc_html_e( 'Create a new wallet', 'agentpay-by-cleardesk-seo' ); ?>
				</a>
				<a href="<?php echo esc_url( self::tab_url( 'existing' ) ); ?>"
				   class="<?php echo 'existing' === $mode ? 'is-active' : ''; ?>">
					<?php esc_html_e( 'I already have a wallet address', 'agentpay-by-cleardesk-seo' ); ?>
				</a>
			</nav>
		</header>

		<div style="background:#fef3c7;border-left:4px solid #f59e0b;padding:14px 16px;margin:0 0 18px 0;border-radius:0 4px 4px 0;line-height:1.6;">
			<strong style="display:block;margin-bottom:8px;"><?php esc_html_e( 'Before you begin — please read', 'agentpay-by-cleardesk-seo' ); ?></strong>
			<p style="margin:0 0 10px;"><?php esc_html_e( 'ClearWallet moves real USDC on Base mainnet. These points trip people up most often — skim them before connecting:', 'agentpay-by-cleardesk-seo' ); ?></p>
			<ol style="margin:0;padding-left:20px;">
				<li style="margin-bottom:8px;"><strong><?php esc_html_e( 'Network: Base mainnet (chain ID 8453).', 'agentpay-by-cleardesk-seo' ); ?></strong> <?php esc_html_e( 'Your wallet receives USDC on Base — Coinbase\'s Ethereum L2. Not Ethereum mainnet, not Base Sepolia (testnet), not Polygon. The USDC contract is 0x8335…2913 ("USD Coin" on Base).', 'agentpay-by-cleardesk-seo' ); ?></li>
				<li style="margin-bottom:8px;"><strong><?php esc_html_e( 'You receive — you never pre-fund.', 'agentpay-by-cleardesk-seo' ); ?></strong> <?php esc_html_e( 'The paying AI agent signs a gasless transfer and Coinbase\'s facilitator pays the gas. You don\'t need ETH and you don\'t fund anything up front — USDC simply lands in your wallet.', 'agentpay-by-cleardesk-seo' ); ?></li>
				<li style="margin-bottom:8px;"><strong><?php esc_html_e( 'Price at least $0.01 per request.', 'agentpay-by-cleardesk-seo' ); ?></strong> <?php esc_html_e( 'The settlement network rejects "dust" amounts below roughly $0.001, so keep your per-request rates at or above one cent. You set these on the Pricing tab.', 'agentpay-by-cleardesk-seo' ); ?></li>
				<li style="margin:0;"><strong><?php esc_html_e( 'Verify your Coinbase identity first.', 'agentpay-by-cleardesk-seo' ); ?></strong> <?php esc_html_e( 'CDP requires identity verification on your account before it will move live USDC. Complete that in the CDP portal, otherwise settlement fails even with everything else configured correctly.', 'agentpay-by-cleardesk-seo' ); ?></li>
			</ol>
		</div>

		<?php if ( 'existing' === $mode ) : ?>
			<?php self::render_existing_form(); ?>
		<?php else : ?>
			<?php self::render_create_form(); ?>
		<?php endif; ?>
		<?php
	}

	private static function render_create_form() {
		?>
		<form class="ap-setup-form" id="ap-create-form">
			<?php wp_nonce_field( self::NONCE_ACTION, 'clearwallet_nonce' ); ?>

			<p class="ap-setup-section-lede">
				<?php esc_html_e( 'Paste the three values from your Coinbase Developer Portal. We\'ll provision a fresh wallet on Base in your project.', 'agentpay-by-cleardesk-seo' ); ?>
			</p>

			<div style="background:#e0f2fe;border-left:4px solid #0284c7;padding:12px 14px;margin:0 0 16px 0;border-radius:0 4px 4px 0;">
				<strong style="display:block;margin-bottom:4px;"><?php esc_html_e( 'About the API Key signature algorithm', 'agentpay-by-cleardesk-seo' ); ?></strong>
				<?php esc_html_e( 'When creating your CDP API Key, Coinbase\'s default is Ed25519 — this works fine on WordPress (uses PHP\'s built-in sodium extension). If your host has sodium disabled and you see a "sodium required" error after pasting, recreate the key in CDP with Signature algorithm set to ECDSA (under Advanced Settings). The plugin auto-detects which algorithm you used; you don\'t need to tell it.', 'agentpay-by-cleardesk-seo' ); ?>
			</div>

			<div class="ap-field">
				<label for="ap-api-key-id">
					<span><?php esc_html_e( 'API Key ID', 'agentpay-by-cleardesk-seo' ); ?></span>
					<a href="#" class="ap-help-trigger" data-target="help-api-key">
						<?php esc_html_e( 'Where do I find this?', 'agentpay-by-cleardesk-seo' ); ?>
					</a>
				</label>
				<input type="text" id="ap-api-key-id" name="api_key_id"
					   placeholder="65a7f2b1-4c8d-4f0e-9b3a-1e2f3d4c5b6a"
					   autocomplete="off" spellcheck="false" required />
			</div>

			<div class="ap-field">
				<label for="ap-api-key-secret">
					<span><?php esc_html_e( 'API Key Secret', 'agentpay-by-cleardesk-seo' ); ?></span>
					<a href="#" class="ap-help-trigger" data-target="help-api-secret">
						<?php esc_html_e( 'Where do I find this?', 'agentpay-by-cleardesk-seo' ); ?>
					</a>
				</label>
				<textarea id="ap-api-key-secret" name="api_key_secret" rows="4"
						  placeholder="-----BEGIN EC PRIVATE KEY-----&#10;MHcCAQEE...&#10;-----END EC PRIVATE KEY-----"
						  autocomplete="off" spellcheck="false" required></textarea>
				<p class="ap-field-hint"><?php esc_html_e( 'A multi-line PEM block. Include the BEGIN and END lines exactly as shown.', 'agentpay-by-cleardesk-seo' ); ?></p>
			</div>

			<div class="ap-field">
				<label for="ap-wallet-secret">
					<span><?php esc_html_e( 'Wallet Secret', 'agentpay-by-cleardesk-seo' ); ?></span>
					<a href="#" class="ap-help-trigger ap-help-loud" data-target="help-wallet-secret">
						<?php esc_html_e( 'Read this first — shown only once', 'agentpay-by-cleardesk-seo' ); ?>
					</a>
				</label>
				<input type="password" id="ap-wallet-secret" name="wallet_secret"
					   placeholder="••••••••••••••••••••••••••••••••"
					   autocomplete="off" spellcheck="false" required />
				<p class="ap-field-hint"><?php esc_html_e( 'A long hex string. The Coinbase Portal only shows this once when you generate it.', 'agentpay-by-cleardesk-seo' ); ?></p>
			</div>

			<div class="ap-actions">
				<button type="button" class="button button-primary button-large" id="ap-btn-create">
					<?php esc_html_e( 'Connect & create wallet', 'agentpay-by-cleardesk-seo' ); ?>
				</button>
				<button type="button" class="button button-secondary" id="ap-btn-test">
					<?php esc_html_e( 'Test connection', 'agentpay-by-cleardesk-seo' ); ?>
				</button>
				<span class="spinner ap-spinner"></span>
			</div>

			<div class="ap-status" id="ap-create-status" aria-live="polite"></div>

			<div class="ap-footer-cta">
				<strong><?php esc_html_e( 'Don\'t have a Coinbase Developer account yet?', 'agentpay-by-cleardesk-seo' ); ?></strong>
				<a href="<?php echo esc_url( self::PORTAL_URL ); ?>" target="_blank" rel="noopener">
					<?php esc_html_e( 'Create one in 2 minutes', 'agentpay-by-cleardesk-seo' ); ?> →
				</a>
				<?php esc_html_e( '— we\'ll wait here.', 'agentpay-by-cleardesk-seo' ); ?>
			</div>
		</form>
		<?php
	}

	private static function render_existing_form() {
		?>
		<form class="ap-setup-form" id="ap-existing-form">
			<?php wp_nonce_field( self::NONCE_ACTION, 'clearwallet_nonce' ); ?>

			<p class="ap-setup-section-lede">
				<?php esc_html_e( 'Already have a CDP wallet on Base? Paste the address along with your API credentials. We\'ll verify the address belongs to your project and attach the plugin to it.', 'agentpay-by-cleardesk-seo' ); ?>
			</p>

			<div class="ap-field">
				<label for="ap-existing-address">
					<span><?php esc_html_e( 'Wallet address', 'agentpay-by-cleardesk-seo' ); ?></span>
				</label>
				<input type="text" id="ap-existing-address" name="address"
					   placeholder="0x3c0D84055994c3062819Ce8730869D0aDeA4c3Bf"
					   autocomplete="off" spellcheck="false" pattern="0x[a-fA-F0-9]{40}" required />
				<p class="ap-field-hint"><?php esc_html_e( 'Starts with 0x followed by 40 hex characters. Find this in your CDP Portal under the wallet\'s detail page.', 'agentpay-by-cleardesk-seo' ); ?></p>
			</div>

			<div class="ap-field">
				<label for="ap-ex-api-key-id">
					<span><?php esc_html_e( 'API Key ID', 'agentpay-by-cleardesk-seo' ); ?></span>
					<a href="#" class="ap-help-trigger" data-target="help-api-key">
						<?php esc_html_e( 'Where do I find this?', 'agentpay-by-cleardesk-seo' ); ?>
					</a>
				</label>
				<input type="text" id="ap-ex-api-key-id" name="api_key_id" required />
			</div>

			<div class="ap-field">
				<label for="ap-ex-api-key-secret">
					<span><?php esc_html_e( 'API Key Secret', 'agentpay-by-cleardesk-seo' ); ?></span>
					<a href="#" class="ap-help-trigger" data-target="help-api-secret">
						<?php esc_html_e( 'Where do I find this?', 'agentpay-by-cleardesk-seo' ); ?>
					</a>
				</label>
				<textarea id="ap-ex-api-key-secret" name="api_key_secret" rows="4"
						  placeholder="-----BEGIN EC PRIVATE KEY-----..." required></textarea>
			</div>

			<div class="ap-field">
				<label for="ap-ex-wallet-secret">
					<span><?php esc_html_e( 'Wallet Secret', 'agentpay-by-cleardesk-seo' ); ?></span>
					<a href="#" class="ap-help-trigger ap-help-loud" data-target="help-wallet-secret">
						<?php esc_html_e( 'Read this first — shown only once', 'agentpay-by-cleardesk-seo' ); ?>
					</a>
				</label>
				<input type="password" id="ap-ex-wallet-secret" name="wallet_secret" required />
				<p class="ap-field-hint"><?php esc_html_e( 'Required for refunds and the daily fee sweep, even if the wallet already exists.', 'agentpay-by-cleardesk-seo' ); ?></p>
			</div>

			<div class="ap-actions">
				<button type="button" class="button button-primary button-large" id="ap-btn-use-existing">
					<?php esc_html_e( 'Connect to existing wallet', 'agentpay-by-cleardesk-seo' ); ?>
				</button>
				<span class="spinner ap-spinner"></span>
			</div>

			<div class="ap-status" id="ap-existing-status" aria-live="polite"></div>
		</form>
		<?php
	}

	private static function render_configured_state( $address ) {
		$short      = substr( $address, 0, 6 ) . '…' . substr( $address, -4 );
		$created_at = (int) get_option( 'clearwallet_wallet_created_at', 0 );
		$basescan   = 'https://basescan.org/address/' . $address;
		?>
		<header class="ap-setup-header">
			<h2><?php esc_html_e( 'Your wallet is ready', 'agentpay-by-cleardesk-seo' ); ?></h2>
			<p class="ap-setup-lede">
				<?php esc_html_e( 'ClearWallet is connected to a wallet in your Coinbase Developer account. This is where AI agents pay you, where refunds originate, and where the 1% fee sweep runs from.', 'agentpay-by-cleardesk-seo' ); ?>
			</p>
		</header>

		<div class="ap-success-panel">
			<div class="ap-check-line">
				<span class="dashicons dashicons-yes-alt"></span>
				<?php esc_html_e( 'Connected to Coinbase Developer Platform', 'agentpay-by-cleardesk-seo' ); ?>
			</div>
			<div class="ap-check-line">
				<span class="dashicons dashicons-yes-alt"></span>
				<?php esc_html_e( 'Wallet active on Base mainnet', 'agentpay-by-cleardesk-seo' ); ?>
			</div>

			<div class="ap-address-box">
				<div class="ap-address-label"><?php esc_html_e( 'Receiving address', 'agentpay-by-cleardesk-seo' ); ?></div>
				<div class="ap-address-row">
					<code id="ap-address-full"><?php echo esc_html( $address ); ?></code>
					<button type="button" class="button button-small" id="ap-copy-address" data-address="<?php echo esc_attr( $address ); ?>">
						<span class="dashicons dashicons-admin-page"></span>
						<?php esc_html_e( 'Copy', 'agentpay-by-cleardesk-seo' ); ?>
					</button>
				</div>
				<?php if ( $created_at ) : ?>
					<div class="ap-address-meta">
						<?php
						printf(
							/* translators: %s: human-readable time-diff */
							esc_html__( 'Provisioned %s ago.', 'agentpay-by-cleardesk-seo' ),
							esc_html( human_time_diff( $created_at, time() ) )
						);
						?>
					</div>
				<?php endif; ?>
			</div>
		</div>

		<div class="ap-actions">
			<a href="<?php echo esc_url( admin_url( 'tools.php?page=clearwallet&tab=configuration' ) ); ?>"
			   class="button button-primary button-large">
				<?php esc_html_e( 'Next: set your prices', 'agentpay-by-cleardesk-seo' ); ?> →
			</a>
			<a href="<?php echo esc_url( $basescan ); ?>" target="_blank" rel="noopener" class="button">
				<?php esc_html_e( 'View on BaseScan', 'agentpay-by-cleardesk-seo' ); ?>
			</a>
			<button type="button" class="button-link ap-danger-link" id="ap-btn-disconnect">
				<?php esc_html_e( 'Disconnect wallet', 'agentpay-by-cleardesk-seo' ); ?>
			</button>
		</div>

		<details class="ap-advanced">
			<summary><?php esc_html_e( 'Advanced — switch network or rotate credentials', 'agentpay-by-cleardesk-seo' ); ?></summary>
			<p>
				<?php esc_html_e( 'To swap the receiving wallet, switch between mainnet and Sepolia, or rotate API credentials, disconnect above and reconnect with the new values. In-flight refunds and pending fee sweeps will continue against the existing wallet until they complete.', 'agentpay-by-cleardesk-seo' ); ?>
			</p>
		</details>
		<?php
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Modal markup (rendered once per page, opened via JS)
	// ─────────────────────────────────────────────────────────────────────────

	private static function render_modals() {
		?>
		<div class="ap-modal-backdrop" id="ap-modal-backdrop" hidden></div>

		<!-- Help: API Key ID -->
		<div class="ap-modal" id="help-api-key" role="dialog" aria-modal="true" hidden>
			<div class="ap-modal-head">
				<h3><?php esc_html_e( 'Finding your API Key ID and Secret', 'agentpay-by-cleardesk-seo' ); ?></h3>
				<button class="ap-modal-close" aria-label="Close">×</button>
			</div>
			<div class="ap-modal-body">
				<ol>
					<li><?php
						printf(
							/* translators: %s: Coinbase Developer Portal URL */
							wp_kses_post( __( 'Sign in to the <a href="%s" target="_blank" rel="noopener">Coinbase Developer Portal</a>.', 'agentpay-by-cleardesk-seo' ) ),
							esc_url( self::PORTAL_URL )
						);
					?></li>
					<li><?php esc_html_e( 'Select your project (create one if you don\'t have any).', 'agentpay-by-cleardesk-seo' ); ?></li>
					<li><?php esc_html_e( 'In the left sidebar, click API Keys → Secret API Keys.', 'agentpay-by-cleardesk-seo' ); ?></li>
					<li><?php esc_html_e( 'Click "Create API Key" and give it a name like "ClearWallet". Either signature algorithm (Ed25519, the default, or ECDSA under Advanced Settings) is fine — the plugin supports both.', 'agentpay-by-cleardesk-seo' ); ?></li>
					<li><?php esc_html_e( 'Click "Create & Download". Copy the API Key ID (the "name" field, looks like organizations/.../apiKeys/...) and the API Key Secret (the "privateKey" field — either a base64 string for Ed25519 or a PEM block for ECDSA). Paste both into the form.', 'agentpay-by-cleardesk-seo' ); ?></li>
				</ol>
				<p class="ap-modal-foot-note">
					<?php esc_html_e( 'The API Key ID is safe to share. The API Key Secret is sensitive — store it like a password.', 'agentpay-by-cleardesk-seo' ); ?>
				</p>
			</div>
		</div>

		<!-- Help: API Secret (alias to same modal) -->
		<div class="ap-modal" id="help-api-secret" role="dialog" aria-modal="true" hidden>
			<div class="ap-modal-head">
				<h3><?php esc_html_e( 'Finding your API Key Secret', 'agentpay-by-cleardesk-seo' ); ?></h3>
				<button class="ap-modal-close" aria-label="Close">×</button>
			</div>
			<div class="ap-modal-body">
				<p><?php esc_html_e( 'The API Key Secret is the privateKey field inside the JSON file Coinbase gave you when you created the API Key. The format depends on which signature algorithm you chose — both are supported:', 'agentpay-by-cleardesk-seo' ); ?></p>
				<ul>
					<li><strong>Ed25519</strong> (CDP default): <?php esc_html_e( 'A short base64 string with no BEGIN/END markers. Paste it directly.', 'agentpay-by-cleardesk-seo' ); ?></li>
					<li><strong>ECDSA / ES256</strong>: <?php esc_html_e( 'A multi-line PEM block starting with -----BEGIN-----. Paste it including the BEGIN and END lines.', 'agentpay-by-cleardesk-seo' ); ?></li>
				</ul>
				<p><?php esc_html_e( 'The plugin auto-detects which type you pasted. If you see a "sodium extension required" error, your PHP host has sodium disabled — recreate the API Key in CDP with the signature algorithm set to ECDSA (under Advanced Settings) and use that instead.', 'agentpay-by-cleardesk-seo' ); ?></p>
				<p><?php esc_html_e( 'If you didn\'t save the file when you created the key, you\'ll need to generate a new API Key in the Portal. The old one stops working as soon as you generate the replacement.', 'agentpay-by-cleardesk-seo' ); ?></p>
			</div>
		</div>

		<!-- Help: Wallet Secret — LOUD -->
		<div class="ap-modal ap-modal-loud" id="help-wallet-secret" role="dialog" aria-modal="true" hidden>
			<div class="ap-modal-head ap-loud-head">
				<div class="ap-loud-eyebrow">
					<span class="dashicons dashicons-warning"></span>
					<?php esc_html_e( 'Read this before you continue', 'agentpay-by-cleardesk-seo' ); ?>
				</div>
				<h3><?php esc_html_e( 'The Wallet Secret is shown once. Then it\'s gone.', 'agentpay-by-cleardesk-seo' ); ?></h3>
				<button class="ap-modal-close" aria-label="Close">×</button>
			</div>
			<div class="ap-modal-body">
				<p>
					<?php esc_html_e( 'When you create a Wallet Secret in the Coinbase Developer Portal, the full secret value appears exactly one time on screen. Once you close that page, neither you nor Coinbase can ever retrieve it again. If you lose it, you have to generate a new one — and any wallet that was signed with the old secret stops working until you reconnect.', 'agentpay-by-cleardesk-seo' ); ?>
				</p>

				<p class="ap-loud-list-intro"><?php esc_html_e( 'Before you click Generate in the Portal, have all three ready:', 'agentpay-by-cleardesk-seo' ); ?></p>

				<ul class="ap-loud-checklist">
					<li><span class="dashicons dashicons-yes"></span><?php esc_html_e( 'A password manager open and ready to save the value', 'agentpay-by-cleardesk-seo' ); ?></li>
					<li><span class="dashicons dashicons-yes"></span><?php esc_html_e( 'This ClearWallet setup tab open in another window, ready to paste', 'agentpay-by-cleardesk-seo' ); ?></li>
					<li><span class="dashicons dashicons-yes"></span><?php esc_html_e( 'A backup location in case the password manager fails', 'agentpay-by-cleardesk-seo' ); ?></li>
				</ul>

				<div class="ap-loud-amber">
					<strong><?php esc_html_e( 'Lost yours already?', 'agentpay-by-cleardesk-seo' ); ?></strong>
					<?php esc_html_e( 'That\'s fine — generate a new one. The old Wallet Secret is dead the moment you click Generate again; nothing else uses it.', 'agentpay-by-cleardesk-seo' ); ?>
				</div>

				<label class="ap-loud-ack">
					<input type="checkbox" id="ap-loud-ack" />
					<span><?php esc_html_e( 'I understand the Wallet Secret only appears once and that losing it means generating a new one.', 'agentpay-by-cleardesk-seo' ); ?></span>
				</label>

				<div class="ap-modal-actions">
					<button type="button" class="button ap-modal-close-btn"><?php esc_html_e( 'Cancel', 'agentpay-by-cleardesk-seo' ); ?></button>
					<a href="<?php echo esc_url( self::HELP_WALLET ); ?>" target="_blank" rel="noopener"
					   class="button button-primary ap-loud-open"
					   id="ap-loud-open" aria-disabled="true">
						<?php esc_html_e( 'Open Coinbase Portal', 'agentpay-by-cleardesk-seo' ); ?>
						<span class="dashicons dashicons-external"></span>
					</a>
				</div>
			</div>
		</div>
		<?php
	}

	// ─────────────────────────────────────────────────────────────────────────
	// AJAX endpoints
	// ─────────────────────────────────────────────────────────────────────────

	public function ajax_test_connection() {
		$this->check_ajax();
		$creds = $this->extract_creds( false );

		$client = new CdpClient( $creds['api_key_id'], $creds['api_key_secret'] );
		$result = $client->test_connection();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}
		wp_send_json_success( array( 'message' => __( 'Coinbase credentials verified.', 'agentpay-by-cleardesk-seo' ) ) );
	}

	public function ajax_create_wallet() {
		$this->check_ajax();
		$creds = $this->extract_creds( true );

		$client = new CdpClient( $creds['api_key_id'], $creds['api_key_secret'], $creds['wallet_secret'] );
		$result = $client->create_evm_account( 'clearwallet-' . wp_generate_password( 6, false ) );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		$this->save_credentials( $creds, $result['address'] );

		wp_send_json_success( array(
			'message'  => __( 'Wallet created and connected.', 'agentpay-by-cleardesk-seo' ),
			'address'  => $result['address'],
			'reload'   => true,
		) );
	}

	public function ajax_use_existing() {
		$this->check_ajax();
		$creds   = $this->extract_creds( true );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified via $this->check_ajax()
		$address = isset( $_POST['address'] ) ? sanitize_text_field( wp_unslash( $_POST['address'] ) ) : '';

		$client = new CdpClient( $creds['api_key_id'], $creds['api_key_secret'], $creds['wallet_secret'] );
		$result = $client->verify_existing_address( $address );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		$this->save_credentials( $creds, $address );

		wp_send_json_success( array(
			'message' => __( 'Existing wallet attached.', 'agentpay-by-cleardesk-seo' ),
			'address' => $address,
			'reload'  => true,
		) );
	}

	public function ajax_disconnect() {
		$this->check_ajax();
		delete_option( 'clearwallet_cdp_api_key_id' );
		delete_option( 'clearwallet_cdp_api_key_secret' );
		delete_option( 'clearwallet_cdp_wallet_secret' );
		delete_option( 'clearwallet_receiving_address' );
		delete_option( 'clearwallet_wallet_created_at' );
		wp_send_json_success( array( 'reload' => true ) );
	}

	/**
	 * Report the connected wallet's USDC balance (read from the Base RPC) so the
	 * cash-out panel can show "Available: X USDC" and offer a one-click Max.
	 */
	public function ajax_balance() {
		$this->check_ajax();
		$address = get_option( 'clearwallet_receiving_address', '' );
		if ( empty( $address ) ) {
			wp_send_json_error( array( 'message' => __( 'No wallet connected.', 'agentpay-by-cleardesk-seo' ) ), 400 );
		}
		$network = Installer::setting( 'network', 'base' );
		$atomic  = CdpClient::usdc_balance( $address, $network );
		if ( is_wp_error( $atomic ) ) {
			wp_send_json_error( array( 'message' => $atomic->get_error_message() ), 400 );
		}
		// Reserve the unswept 1% fee so the merchant can't withdraw funds the fee
		// sweep still needs — withdrawing into that reserve is what put the sweep
		// and the wallet balance out of sync (and caused "insufficient_funds").
		$reserved  = FeeProcessor::pending_atomic();
		$available = max( 0, (int) $atomic - $reserved );
		wp_send_json_success( array(
			'atomic'          => (string) $available,
			'usdc'            => self::fmt_usdc( $available ),
			'gross'           => self::fmt_usdc( (int) $atomic ),
			'reserved'        => self::fmt_usdc( $reserved ),
			'reserved_atomic' => (string) $reserved,
		) );
	}

	/**
	 * Send USDC from the connected wallet to a destination address (the
	 * merchant's personal Coinbase deposit address). Reuses the same gasless
	 * EIP-3009 transfer the fee sweep uses, so Coinbase covers the gas.
	 */
	public function ajax_withdraw() {
		$this->check_ajax();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified via $this->check_ajax()
		$to = isset( $_POST['to'] ) ? sanitize_text_field( wp_unslash( $_POST['to'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified via $this->check_ajax()
		$amount = isset( $_POST['amount'] ) ? sanitize_text_field( wp_unslash( $_POST['amount'] ) ) : '';

		if ( ! preg_match( '/^0x[0-9a-fA-F]{40}$/', $to ) ) {
			wp_send_json_error( array( 'message' => __( 'Enter a valid 0x destination address (your Coinbase USDC deposit address on Base).', 'agentpay-by-cleardesk-seo' ) ), 400 );
		}
		$amount_f = (float) $amount;
		if ( $amount_f <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Enter an amount of USDC to send.', 'agentpay-by-cleardesk-seo' ) ), 400 );
		}
		$atomic = (int) round( $amount_f * 1000000 );
		if ( $atomic < 10000 ) {
			wp_send_json_error( array( 'message' => __( 'The minimum send is 0.01 USDC.', 'agentpay-by-cleardesk-seo' ) ), 400 );
		}

		// Enforce the reserve at submit time: never withdraw the portion owed to
		// the fee sweep, nor more than the wallet actually holds on-chain.
		$address = get_option( 'clearwallet_receiving_address', '' );
		$network = Installer::setting( 'network', 'base' );
		$balance = CdpClient::usdc_balance( $address, $network );
		if ( ! is_wp_error( $balance ) ) {
			$available = max( 0, (int) $balance - FeeProcessor::pending_atomic() );
			if ( $atomic > $available ) {
				wp_send_json_error( array(
					'message' => sprintf(
						/* translators: %s: USDC amount available to withdraw */
						__( 'You can withdraw up to %s USDC right now — the rest of the balance is reserved for the 1%% platform fee.', 'agentpay-by-cleardesk-seo' ),
						self::fmt_usdc( $available )
					),
				), 400 );
			}
		}

		$result = Facilitator::transfer(
			$to,
			$atomic,
			'withdraw-' . wp_generate_password( 16, false ),
			array( 'type' => 'merchant_withdrawal' )
		);
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}
		$tx = isset( $result['tx_hash'] ) ? $result['tx_hash'] : '';
		wp_send_json_success( array(
			'message' => sprintf(
				/* translators: %s: amount of USDC sent */
				__( 'Sent %s USDC to your Coinbase address. It should arrive in about a minute.', 'agentpay-by-cleardesk-seo' ),
				number_format( $amount_f, 2 )
			),
			'tx'     => $tx,
			'tx_url' => $tx ? ( 'https://basescan.org/tx/' . $tx ) : '',
		) );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Helpers
	// ─────────────────────────────────────────────────────────────────────────

	private function check_ajax() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'agentpay-by-cleardesk-seo' ) ), 403 );
		}
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
	}

	/**
	 * Format an atomic USDC amount (6 decimals) for display WITHOUT rounding up.
	 * Shows the exact value, trimming trailing zeros but keeping at least two
	 * decimals: 99000 -> "0.099", 100000 -> "0.10", 1000 -> "0.001", 0 -> "0.00".
	 * Two-decimal rounding previously turned 0.099 into "0.10", which then
	 * exceeded the real balance and was rejected.
	 */
	private static function fmt_usdc( $atomic ) {
		$s = rtrim( sprintf( '%.6f', ( (int) $atomic ) / 1000000 ), '0' );
		if ( '.' === substr( $s, -1 ) ) {
			$s .= '00';                       // "0." -> "0.00"
		} elseif ( preg_match( '/\.\d$/', $s ) ) {
			$s .= '0';                        // one decimal -> two
		}
		return $s;
	}

	private function extract_creds( $require_wallet_secret ) {
		// All three $_POST reads in this method are reached only through AJAX
		// handlers that have already called $this->check_ajax(), which performs
		// check_ajax_referer() + current_user_can(). PluginCheck can't follow the
		// call graph, so nonce-verification warnings are suppressed inline.
		// api_key_secret is sanitized via self::sanitize_pem() which preserves
		// the PEM line breaks that sanitize_text_field() would corrupt.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified via $this->check_ajax()
		$id     = isset( $_POST['api_key_id'] )     ? sanitize_text_field( wp_unslash( $_POST['api_key_id'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified via $this->check_ajax(); sanitized via self::sanitize_pem()
		$secret = isset( $_POST['api_key_secret'] ) ? self::sanitize_pem( wp_unslash( $_POST['api_key_secret'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified via $this->check_ajax()
		$wallet = isset( $_POST['wallet_secret'] )  ? sanitize_text_field( wp_unslash( $_POST['wallet_secret'] ) ) : '';

		if ( empty( $id ) || empty( $secret ) ) {
			wp_send_json_error( array( 'message' => __( 'API Key ID and Secret are both required.', 'agentpay-by-cleardesk-seo' ) ), 400 );
		}
		// Validate with the real signer: accepts an ECDSA PEM or an Ed25519
		// base64 secret in any exported shape, and auto-extracts the privateKey
		// when the whole JSON file was pasted. This replaces the old PEM-only
		// check, which rejected Ed25519 keys at save even though the runtime
		// signs with them.
		$valid = CdpClient::validate_signing_secret( $secret );
		if ( is_wp_error( $valid ) ) {
			wp_send_json_error( array( 'message' => $valid->get_error_message() ), 400 );
		}
		// Persist the normalized form, so what we store is exactly the value the
		// signer consumes even if a JSON blob was pasted.
		$secret = CdpClient::normalize_secret_input( $secret );
		if ( $require_wallet_secret && empty( $wallet ) ) {
			wp_send_json_error( array( 'message' => __( 'Wallet Secret is required for wallet operations.', 'agentpay-by-cleardesk-seo' ) ), 400 );
		}

		return array(
			'api_key_id'     => $id,
			'api_key_secret' => $secret,
			'wallet_secret'  => $wallet,
		);
	}

	/**
	 * PEM-aware sanitizer. Strips null bytes and non-printable control
	 * characters while preserving the LF newlines and base64 chars that
	 * PEM structure requires. Normalizes CRLF to LF and caps total length.
	 *
	 * @param mixed $value Raw input from $_POST (already wp_unslash'd).
	 * @return string Sanitized PEM-like string.
	 */
	private static function sanitize_pem( $value ) {
		if ( ! is_string( $value ) ) { return ''; }
		$value = str_replace( "\0", '', $value );           // null bytes
		$value = str_replace( "\r\n", "\n", $value );       // normalize line endings
		$value = preg_replace( '/[^\x09\x0A\x20-\x7E]/', '', $value ); // tab/LF/printable ASCII only
		if ( strlen( $value ) > 8192 ) {
			$value = substr( $value, 0, 8192 );
		}
		return trim( $value );
	}

	/**
	 * Structural validation: a usable PEM has matching BEGIN/END markers.
	 * Does not cryptographically validate the key — that happens when CDP
	 * client uses it. Just rejects obvious garbage.
	 */
	private static function is_valid_pem( $value ) {
		return is_string( $value )
			&& strpos( $value, '-----BEGIN' ) !== false
			&& strpos( $value, '-----END' )   !== false;
	}

	private function save_credentials( $creds, $address ) {
		update_option( 'clearwallet_cdp_api_key_id', $creds['api_key_id'], false );
		update_option( 'clearwallet_cdp_api_key_secret', self::encrypt( $creds['api_key_secret'] ), false );
		if ( ! empty( $creds['wallet_secret'] ) ) {
			update_option( 'clearwallet_cdp_wallet_secret', self::encrypt( $creds['wallet_secret'] ), false );
		}
		update_option( 'clearwallet_receiving_address', $address, true );
		update_option( 'clearwallet_wallet_created_at', time(), false );
	}

	/**
	 * Lightweight at-rest encryption for secrets using wp_salt-derived key.
	 * Not a substitute for a hardware vault — meant to keep secrets out of
	 * plain DB dumps and accidental backups.
	 */
	public static function encrypt( $plaintext ) {
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return $plaintext;
		}
		$key = hash( 'sha256', wp_salt( 'auth' ) . 'clearwallet-cdp', true );
		$iv  = random_bytes( 16 );
		$ct  = openssl_encrypt( $plaintext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
		return 'v1:' . base64_encode( $iv . $ct );
	}

	public static function decrypt( $stored ) {
		if ( 0 !== strpos( $stored, 'v1:' ) || ! function_exists( 'openssl_decrypt' ) ) {
			return $stored;
		}
		$blob = base64_decode( substr( $stored, 3 ), true );
		if ( false === $blob || strlen( $blob ) < 17 ) {
			return '';
		}
		$key = hash( 'sha256', wp_salt( 'auth' ) . 'clearwallet-cdp', true );
		$iv  = substr( $blob, 0, 16 );
		$ct  = substr( $blob, 16 );
		$pt  = openssl_decrypt( $ct, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
		return false === $pt ? '' : $pt;
	}

	private static function tab_url( $mode ) {
		return add_query_arg(
			array(
				'page'          => 'clearwallet',
				'tab'           => 'setup',
				'clearwallet_mode' => $mode,
			),
			admin_url( 'tools.php' )
		);
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Asset enqueue
	// ─────────────────────────────────────────────────────────────────────────

	public function enqueue_assets( $hook ) {
		// $hook is WP-generated from the admin slug — for our Tools submenu
		// registered with slug 'clearwallet', this is 'tools_page_clearwallet'.
		// The strpos guard prevents enqueueing on other admin pages.
		if ( false === strpos( (string) $hook, 'clearwallet' ) ) {
			return;
		}

		// CSS lives in admin/css/admin-setup.css; JS in admin/js/admin-setup.js.
		// Loading them as real files (instead of inline heredocs) satisfies
		// WP.org plugin-check, keeps them browser-cacheable, and lets editors
		// like VS Code give us syntax highlighting.
		$base_url = defined( 'CLEARWALLET_URL' ) ? CLEARWALLET_URL : plugin_dir_url( __FILE__ ) . '../';
		$version  = defined( 'CLEARWALLET_VERSION' ) ? CLEARWALLET_VERSION : '1.0.0';

		wp_enqueue_style(
			'clearwallet-admin-setup',
			$base_url . 'admin/css/admin-setup.css',
			array(),
			$version
		);

		wp_enqueue_script(
			'clearwallet-admin-setup',
			$base_url . 'admin/js/admin-setup.js',
			array( 'jquery' ),
			$version,
			true
		);

		wp_localize_script( 'clearwallet-admin-setup', 'ClearWalletSetup', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
			'strings' => array(
				'testing'            => __( 'Testing…', 'agentpay-by-cleardesk-seo' ),
				'creating'           => __( 'Provisioning wallet on Base…', 'agentpay-by-cleardesk-seo' ),
				'attaching'          => __( 'Verifying address…', 'agentpay-by-cleardesk-seo' ),
				'copied'             => __( 'Copied', 'agentpay-by-cleardesk-seo' ),
				'sending'            => __( 'Sending…', 'agentpay-by-cleardesk-seo' ),
				'confirm_disconnect' => __( 'Disconnect this wallet from ClearWallet? This stops new payments and refunds until you reconnect. In-flight transactions will continue.', 'agentpay-by-cleardesk-seo' ),
			),
		) );
	}
}
