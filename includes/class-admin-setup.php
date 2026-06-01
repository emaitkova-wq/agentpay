<?php
/**
 * Setup Tab — Wallet provisioning UX
 *
 * Replaces the old credential-only Setup tab with a guided three-field
 * connection flow that provisions a fresh CDP wallet (or attaches to an
 * existing address) without any terminal, SDK, or CLI work.
 *
 * Integration:
 *   1. Drop this file into agentpay/includes/.
 *   2. In agentpay/agentpay.php (or wherever AGENTPAY classes are loaded), add:
 *        require_once AGENTPAY_PATH . 'includes/class-admin-setup.php';
 *        new \AgentPay\AdminSetup();
 *   3. In your existing class-admin.php, replace the body of render_setup_tab()
 *      with a single call: AdminSetup::render_setup_tab();
 *
 * Stored options:
 *   agentpay_cdp_api_key_id      Plaintext (UUID, not a secret).
 *   agentpay_cdp_api_key_secret  Encrypted via wp_salt-derived key.
 *   agentpay_cdp_wallet_secret   Encrypted, same scheme.
 *   agentpay_receiving_address   Plaintext (0x... address — public info).
 *   agentpay_wallet_created_at   Unix timestamp.
 *
 * @package AgentPay
 * @since   1.1.0
 */

namespace AgentPay;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AdminSetup {

	const NONCE_ACTION   = 'agentpay_setup';
	const CAPABILITY     = 'manage_options';
	const PORTAL_URL     = 'https://portal.cdp.coinbase.com/';
	const HELP_API_KEY   = 'https://portal.cdp.coinbase.com/access/api';
	const HELP_WALLET    = 'https://portal.cdp.coinbase.com/access/api/wallet-secret';

	public function __construct() {
		add_action( 'wp_ajax_agentpay_test_connection', array( $this, 'ajax_test_connection' ) );
		add_action( 'wp_ajax_agentpay_create_wallet',   array( $this, 'ajax_create_wallet' ) );
		add_action( 'wp_ajax_agentpay_use_existing',    array( $this, 'ajax_use_existing' ) );
		add_action( 'wp_ajax_agentpay_disconnect',      array( $this, 'ajax_disconnect' ) );
		add_action( 'admin_enqueue_scripts',            array( $this, 'enqueue_assets' ) );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Tab renderer — call from existing Admin class
	// ─────────────────────────────────────────────────────────────────────────

	public static function render_setup_tab() {
		$address = get_option( 'agentpay_receiving_address', '' );
		echo '<div class="agentpay-setup">';
		if ( $address ) {
			self::render_configured_state( $address );
		} else {
			self::render_empty_state();
		}
		self::render_modals();
		echo '</div>';
	}

	private static function render_empty_state() {
		$mode = isset( $_GET['agentpay_mode'] ) && 'existing' === $_GET['agentpay_mode'] ? 'existing' : 'create';
		?>
		<header class="ap-setup-header">
			<h2><?php esc_html_e( 'Connect your Coinbase wallet', 'agentpay-by-cleardesk-seo' ); ?></h2>
			<p class="ap-setup-lede">
				<?php esc_html_e( 'AgentPay needs a wallet to receive payments from AI agents and to issue refunds when pages break. We\'ll create one for you in your own Coinbase Developer account — you keep custody, we just point the plugin at it.', 'agentpay-by-cleardesk-seo' ); ?>
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
			<?php wp_nonce_field( self::NONCE_ACTION, 'agentpay_nonce' ); ?>

			<p class="ap-setup-section-lede">
				<?php esc_html_e( 'Paste the three values from your Coinbase Developer Portal. We\'ll provision a fresh wallet on Base in your project.', 'agentpay-by-cleardesk-seo' ); ?>
			</p>

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
			<?php wp_nonce_field( self::NONCE_ACTION, 'agentpay_nonce' ); ?>

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
		$created_at = (int) get_option( 'agentpay_wallet_created_at', 0 );
		$basescan   = 'https://basescan.org/address/' . $address;
		?>
		<header class="ap-setup-header">
			<h2><?php esc_html_e( 'Your wallet is ready', 'agentpay-by-cleardesk-seo' ); ?></h2>
			<p class="ap-setup-lede">
				<?php esc_html_e( 'AgentPay is connected to a wallet in your Coinbase Developer account. This is where AI agents pay you, where refunds originate, and where the 1% fee sweep runs from.', 'agentpay-by-cleardesk-seo' ); ?>
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
			<a href="<?php echo esc_url( admin_url( 'tools.php?page=agentpay&tab=configuration' ) ); ?>"
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
							wp_kses_post( __( 'Sign in to the <a href="%s" target="_blank" rel="noopener">Coinbase Developer Portal</a>.', 'agentpay-by-cleardesk-seo' ) ),
							esc_url( self::PORTAL_URL )
						);
					?></li>
					<li><?php esc_html_e( 'Select your project (create one if you don\'t have any).', 'agentpay-by-cleardesk-seo' ); ?></li>
					<li><?php esc_html_e( 'In the left sidebar, click Access → API Keys.', 'agentpay-by-cleardesk-seo' ); ?></li>
					<li><?php esc_html_e( 'Click "Create API Key", give it a name like "AgentPay", and enable the Wallet scope.', 'agentpay-by-cleardesk-seo' ); ?></li>
					<li><?php esc_html_e( 'Copy the API Key ID (a UUID) and download the full key. The Secret is the PEM block inside the downloaded file — paste the entire block including BEGIN/END lines.', 'agentpay-by-cleardesk-seo' ); ?></li>
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
				<p><?php esc_html_e( 'The API Key Secret comes in the JSON or PEM file you downloaded when you created the API Key. Open it and copy the value labeled "privateKey" — including the BEGIN EC PRIVATE KEY and END EC PRIVATE KEY lines.', 'agentpay-by-cleardesk-seo' ); ?></p>
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
					<li><span class="dashicons dashicons-yes"></span><?php esc_html_e( 'This AgentPay setup tab open in another window, ready to paste', 'agentpay-by-cleardesk-seo' ); ?></li>
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
		$result = $client->create_evm_account( 'agentpay-' . wp_generate_password( 6, false ) );

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
		delete_option( 'agentpay_cdp_api_key_id' );
		delete_option( 'agentpay_cdp_api_key_secret' );
		delete_option( 'agentpay_cdp_wallet_secret' );
		delete_option( 'agentpay_receiving_address' );
		delete_option( 'agentpay_wallet_created_at' );
		wp_send_json_success( array( 'reload' => true ) );
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

	private function extract_creds( $require_wallet_secret ) {
		$id     = isset( $_POST['api_key_id'] )     ? sanitize_text_field( wp_unslash( $_POST['api_key_id'] ) ) : '';
		$secret = isset( $_POST['api_key_secret'] ) ? wp_unslash( $_POST['api_key_secret'] )                    : '';
		$wallet = isset( $_POST['wallet_secret'] )  ? sanitize_text_field( wp_unslash( $_POST['wallet_secret'] ) ) : '';

		if ( empty( $id ) || empty( $secret ) ) {
			wp_send_json_error( array( 'message' => __( 'API Key ID and Secret are both required.', 'agentpay-by-cleardesk-seo' ) ), 400 );
		}
		if ( $require_wallet_secret && empty( $wallet ) ) {
			wp_send_json_error( array( 'message' => __( 'Wallet Secret is required for wallet operations.', 'agentpay-by-cleardesk-seo' ) ), 400 );
		}

		return array(
			'api_key_id'     => $id,
			'api_key_secret' => $secret,
			'wallet_secret'  => $wallet,
		);
	}

	private function save_credentials( $creds, $address ) {
		update_option( 'agentpay_cdp_api_key_id', $creds['api_key_id'], false );
		update_option( 'agentpay_cdp_api_key_secret', self::encrypt( $creds['api_key_secret'] ), false );
		if ( ! empty( $creds['wallet_secret'] ) ) {
			update_option( 'agentpay_cdp_wallet_secret', self::encrypt( $creds['wallet_secret'] ), false );
		}
		update_option( 'agentpay_receiving_address', $address, true );
		update_option( 'agentpay_wallet_created_at', time(), false );
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
		$key = hash( 'sha256', wp_salt( 'auth' ) . 'agentpay-cdp', true );
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
		$key = hash( 'sha256', wp_salt( 'auth' ) . 'agentpay-cdp', true );
		$iv  = substr( $blob, 0, 16 );
		$ct  = substr( $blob, 16 );
		$pt  = openssl_decrypt( $ct, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
		return false === $pt ? '' : $pt;
	}

	private static function tab_url( $mode ) {
		return add_query_arg(
			array(
				'page'          => 'agentpay',
				'tab'           => 'setup',
				'agentpay_mode' => $mode,
			),
			admin_url( 'tools.php' )
		);
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Asset enqueue
	// ─────────────────────────────────────────────────────────────────────────

	public function enqueue_assets( $hook ) {
		if ( false === strpos( (string) $hook, 'agentpay-by-cleardesk-seo' ) ) {
			return;
		}

		// CSS lives in admin/css/admin-setup.css; JS in admin/js/admin-setup.js.
		// Loading them as real files (instead of inline heredocs) satisfies
		// WP.org plugin-check, keeps them browser-cacheable, and lets editors
		// like VS Code give us syntax highlighting.
		$base_url = defined( 'AGENTPAY_URL' ) ? AGENTPAY_URL : plugin_dir_url( __FILE__ ) . '../';
		$version  = defined( 'AGENTPAY_VERSION' ) ? AGENTPAY_VERSION : '1.0.0';

		wp_enqueue_style(
			'agentpay-admin-setup',
			$base_url . 'admin/css/admin-setup.css',
			array(),
			$version
		);

		wp_enqueue_script(
			'agentpay-admin-setup',
			$base_url . 'admin/js/admin-setup.js',
			array( 'jquery' ),
			$version,
			true
		);

		wp_localize_script( 'agentpay-admin-setup', 'AgentPaySetup', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
			'strings' => array(
				'testing'            => __( 'Testing…', 'agentpay-by-cleardesk-seo' ),
				'creating'           => __( 'Provisioning wallet on Base…', 'agentpay-by-cleardesk-seo' ),
				'attaching'          => __( 'Verifying address…', 'agentpay-by-cleardesk-seo' ),
				'copied'             => __( 'Copied', 'agentpay-by-cleardesk-seo' ),
				'confirm_disconnect' => __( 'Disconnect this wallet from AgentPay? This stops new payments and refunds until you reconnect. In-flight transactions will continue.', 'agentpay-by-cleardesk-seo' ),
			),
		) );
	}
}
