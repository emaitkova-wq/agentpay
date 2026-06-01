<?php
/**
 * sign-fee-config.php  —  Server-side signing tool for ClearDesk SEO
 *
 * This script is for the ClearDesk team's internal use ONLY. It produces a
 * signed JSON document containing the current fee wallet address. The matching
 * public verification key is embedded in the published plugin (in
 * AgentPay\FeeConfig::CLEARDESK_PUBLIC_KEY), so installed plugins can verify
 * authenticity without needing to know the private key.
 *
 * This file is shipped in the plugin so the code is auditable by anyone who
 * downloads it (transparency: operators can confirm exactly what signing
 * algorithm and canonicalization scheme is used).
 *
 * Workflow:
 *
 *   1. ONE-TIME: generate the Ed25519 keypair on a trusted machine.
 *        php tools/sign-fee-config.php --generate-keys
 *      This prints the public key (paste into FeeConfig::CLEARDESK_PUBLIC_KEY,
 *      release a new plugin version) and the private key (store in a secrets
 *      manager — Vault, AWS Secrets Manager, 1Password, etc).
 *
 *   2. EVERY ROTATION: sign a new fee-config.json with the private key.
 *        php tools/sign-fee-config.php \
 *          --wallet 0xYourActualBaseUsdcAddress \
 *          --bps 100 \
 *          --ttl-days 90 \
 *          --private-key-file /path/to/cleardesk-fee-config.key \
 *          > fee-config.json
 *      Then upload fee-config.json to:
 *        https://cleardeskseo.com/api/agentpay/fee-config
 *
 *   3. Plugins fetch on next sweep cycle (max 24h propagation by default,
 *      faster if operators trigger the admin "Refresh fee config" action).
 *
 * Security notes:
 *   - Never commit the private key to the plugin repo.
 *   - Generate the keypair on an air-gapped machine if possible.
 *   - Keep at least one offline backup of the private key.
 *   - To rotate the keypair, you must release a new plugin version with the
 *     updated public key — there's no way to migrate live installs to a new
 *     verification key without a code push.
 *   - The ttl-days controls how long plugins will trust the response without
 *     re-fetching. Shorter = faster rotation propagation, more HTTP load on
 *     cleardeskseo.com. 30-90 days is a reasonable balance.
 *
 * @package AgentPay
 * @since   1.2.0
 */

if ( PHP_SAPI !== 'cli' ) {
	// STDERR is undefined outside CLI, so we can't fwrite to it without
	// causing a fatal. Emit a plain-text 403 response and exit. Operators
	// who land here have likely accidentally exposed the script through a
	// local web server (Local by Flywheel, PHP built-in server, or a
	// "Run PHP" VS Code extension). Run from a terminal instead.
	if ( ! headers_sent() ) {
		header( 'HTTP/1.1 403 Forbidden' );
		header( 'Content-Type: text/plain; charset=utf-8' );
	}
	echo "This tool is CLI-only. Run it from a terminal:\n";
	echo "  php sign-fee-config.php --help\n";
	exit( 1 );
}

// Parse CLI args.
$opts = getopt( '', array(
	'generate-keys',
	'keys-dir:',
	'wallet:',
	'bps:',
	'ttl-days:',
	'version:',
	'private-key:',
	'private-key-file:',
	'help',
) );

if ( isset( $opts['help'] ) ) {
	print_usage();
	exit( 0 );
}

if ( isset( $opts['generate-keys'] ) ) {
	generate_keypair( $opts );
	exit( 0 );
}

// Default to signing mode.
if ( ! isset( $opts['wallet'] ) ) {
	fwrite( STDERR, "Error: --wallet is required when signing.\n\n" );
	print_usage();
	exit( 1 );
}

sign_config( $opts );

// ─────────────────────────────────────────────────────────────────────────────
// Functions
// ─────────────────────────────────────────────────────────────────────────────

function print_usage() {
	$bin = basename( __FILE__ );
	echo "sign-fee-config.php — ClearDesk fee config signing tool\n\n";
	echo "ONE-TIME SETUP — generate the Ed25519 keypair:\n\n";
	echo "  php {$bin} --generate-keys\n\n";
	echo "  Then paste the public key into includes/class-fee-config.php\n";
	echo "  (constant CLEARDESK_PUBLIC_KEY) and store the private key in\n";
	echo "  a secrets manager.\n\n";
	echo "EVERY ROTATION — sign a new fee-config.json:\n\n";
	echo "  php {$bin} \\\n";
	echo "    --wallet 0xYourBaseUsdcReceivingAddress \\\n";
	echo "    --bps 100 \\\n";
	echo "    --ttl-days 90 \\\n";
	echo "    --private-key-file /path/to/cleardesk-fee-config.key \\\n";
	echo "    > fee-config.json\n\n";
	echo "  Then upload fee-config.json to:\n";
	echo "    https://cleardeskseo.com/api/agentpay/fee-config\n\n";
	echo "Options:\n";
	echo "  --wallet <0x...>           EVM address (required). 0x + 40 hex chars.\n";
	echo "  --bps <int>                Fee in basis points. Default: 100 (= 1.00%).\n";
	echo "                             Clamped client-side to MAX 100 (the plugin's hard ceiling).\n";
	echo "  --ttl-days <int>           How many days the signature is valid. Default: 90.\n";
	echo "  --version <int>            Schema version. Default: 1.\n";
	echo "  --private-key <b64>        Ed25519 private key, base64-encoded.\n";
	echo "  --private-key-file <path>  Read the private key from a file instead.\n";
	echo "  --keys-dir <path>          With --generate-keys: write fee-config.key and fee-config.pub directly.\n";
	echo "  --generate-keys            Generate a new Ed25519 keypair and exit.\n";
	echo "  --help                     Show this message.\n";
}

function generate_keypair( $opts = array() ) {
	if ( ! function_exists( 'sodium_crypto_sign_keypair' ) ) {
		fwrite( STDERR, "Error: PHP sodium extension required.\n" );
		exit( 1 );
	}
	$kp     = sodium_crypto_sign_keypair();
	$secret = sodium_crypto_sign_secretkey( $kp );
	$public = sodium_crypto_sign_publickey( $kp );

	$pub_b64 = base64_encode( $public );
	$sec_b64 = base64_encode( $secret );

	// Write directly to files if --keys-dir was passed. This avoids the
	// (very common) copy-paste corruption — extra whitespace, BOM bytes from
	// Notepad, accidentally including the labels — that breaks key loading.
	if ( ! empty( $opts['keys-dir'] ) ) {
		$dir = rtrim( $opts['keys-dir'], "/\\" );
		if ( ! is_dir( $dir ) ) {
			if ( ! @mkdir( $dir, 0700, true ) ) {
				fwrite( STDERR, "Error: could not create directory: {$dir}\n" );
				exit( 1 );
			}
		}
		$priv_path = $dir . DIRECTORY_SEPARATOR . 'fee-config.key';
		$pub_path  = $dir . DIRECTORY_SEPARATOR . 'fee-config.pub';

		// LOCK_EX + no trailing newline + ASCII-only contents.
		if ( false === file_put_contents( $priv_path, $sec_b64, LOCK_EX ) ) {
			fwrite( STDERR, "Error: could not write {$priv_path}\n" );
			exit( 1 );
		}
		if ( false === file_put_contents( $pub_path, $pub_b64, LOCK_EX ) ) {
			fwrite( STDERR, "Error: could not write {$pub_path}\n" );
			exit( 1 );
		}
		// chmod is a no-op on Windows but harmless.
		@chmod( $priv_path, 0600 );

		echo "Generated Ed25519 keypair for ClearDesk SEO fee config signing.\n\n";
		echo "PUBLIC KEY  (paste into FeeConfig::CLEARDESK_PUBLIC_KEY):\n";
		echo "  {$pub_b64}\n\n";
		echo "Files written:\n";
		echo "  Private key: {$priv_path}\n";
		echo "  Public key:  {$pub_path}\n\n";
		echo "Next steps:\n";
		echo "  1. Update includes/class-fee-config.php -> CLEARDESK_PUBLIC_KEY constant with the value above.\n";
		echo "  2. Verify private key is exactly 88 bytes:\n";
		echo "     dir \"{$priv_path}\"\n";
		echo "  3. Use --private-key-file \"{$priv_path}\" on future sign runs.\n";
		echo "  4. Back up the private key file to your secrets manager.\n";
		return;
	}

	echo "Generated Ed25519 keypair for ClearDesk SEO fee config signing.\n";
	echo "\n";
	echo "PUBLIC KEY  (paste into FeeConfig::CLEARDESK_PUBLIC_KEY):\n";
	echo "  {$pub_b64}\n";
	echo "\n";
	echo "PRIVATE KEY (store in secrets manager; NEVER commit):\n";
	echo "  {$sec_b64}\n";
	echo "\n";
	echo "Next steps:\n";
	echo "  1. Update includes/class-fee-config.php -> CLEARDESK_PUBLIC_KEY constant.\n";
	echo "  2. Save the private key to /etc/cleardesk/fee-config.key (mode 0600, root-only).\n";
	echo "  3. Release a new plugin version so installs pick up the new public key.\n";
	echo "  4. Use --private-key-file /etc/cleardesk/fee-config.key on future sign runs.\n";
	echo "\n";
	echo "TIP: pass --keys-dir <path> to write the keys directly to files and avoid\n";
	echo "     copy-paste corruption (Notepad BOMs, stray whitespace, etc).\n";
}

function sign_config( $opts ) {
	if ( ! function_exists( 'sodium_crypto_sign_detached' ) ) {
		fwrite( STDERR, "Error: PHP sodium extension required.\n" );
		exit( 1 );
	}

	$wallet = trim( (string) $opts['wallet'] );
	if ( ! preg_match( '/^0x[a-fA-F0-9]{40}$/', $wallet ) ) {
		fwrite( STDERR, "Error: --wallet must be 0x + 40 hex characters.\n" );
		exit( 1 );
	}

	$bps      = isset( $opts['bps'] )      ? (int) $opts['bps']      : 100;
	$ttl_days = isset( $opts['ttl-days'] ) ? (int) $opts['ttl-days'] : 90;
	$version  = isset( $opts['version'] )  ? (int) $opts['version']  : 1;

	if ( $bps < 0 || $bps > 100 ) {
		fwrite( STDERR, "Error: --bps must be 0-100 (the plugin clamps at 100 anyway).\n" );
		exit( 1 );
	}
	if ( $ttl_days < 1 || $ttl_days > 365 ) {
		fwrite( STDERR, "Error: --ttl-days must be 1-365.\n" );
		exit( 1 );
	}

	$private_key_b64 = '';
	if ( isset( $opts['private-key-file'] ) ) {
		$path = $opts['private-key-file'];
		if ( ! is_readable( $path ) ) {
			fwrite( STDERR, "Error: Cannot read private key file: {$path}\n" );
			exit( 1 );
		}
		$contents = file_get_contents( $path );
		// Strip UTF-8 BOM (Windows Notepad adds one when saving "as UTF-8").
		$contents = preg_replace( '/^\xEF\xBB\xBF/', '', $contents );
		$private_key_b64 = trim( $contents );
	} elseif ( isset( $opts['private-key'] ) ) {
		$private_key_b64 = trim( $opts['private-key'] );
	} else {
		fwrite( STDERR, "Error: Either --private-key or --private-key-file is required.\n" );
		exit( 1 );
	}

	$private_key = base64_decode( $private_key_b64, true );
	if ( false === $private_key ) {
		fwrite( STDERR, "Error: private key is not valid base64.\n" );
		fwrite( STDERR, "  Loaded {" . strlen( $private_key_b64 ) . "} characters.\n" );
		fwrite( STDERR, "  First 30: " . substr( $private_key_b64, 0, 30 ) . "\n" );
		fwrite( STDERR, "  Expected: 88 base64 characters ending in ==\n" );
		fwrite( STDERR, "  Tip: re-run with --generate-keys --keys-dir <path> to write a clean file.\n" );
		exit( 1 );
	}
	if ( 64 !== strlen( $private_key ) ) {
		fwrite( STDERR, "Error: private key decoded to " . strlen( $private_key ) . " bytes; expected exactly 64.\n" );
		fwrite( STDERR, "  Input was " . strlen( $private_key_b64 ) . " base64 characters.\n" );
		fwrite( STDERR, "  Make sure the file contains ONLY the base64 string,\n" );
		fwrite( STDERR, "  not labels like 'PRIVATE_KEY_BASE64=' or extra lines.\n" );
		fwrite( STDERR, "  Tip: re-run with --generate-keys --keys-dir <path> to write a clean file.\n" );
		exit( 1 );
	}

	$now = time();
	$data = array(
		'fee_wallet' => $wallet,
		'fee_bps'    => $bps,
		'version'    => $version,
		'issued_at'  => $now,
		'expires_at' => $now + ( $ttl_days * 86400 ),
	);

	// Canonicalize: sort keys alphabetically at every level, no whitespace,
	// JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE. MUST match exactly
	// what FeeConfig::canonicalize() does on the verification side.
	$canonical = canonicalize( $data );

	$signature = sodium_crypto_sign_detached( $canonical, $private_key );

	$payload = array(
		'data'      => $data,
		'signature' => base64_encode( $signature ),
	);

	// Pretty-print for the file uploaded to cleardeskseo.com — readable for
	// debugging, but the signature was computed over canonical JSON of $data
	// (NOT over this pretty form), so verifiers must re-canonicalize.
	echo json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";

	// Helpful summary to STDERR so it doesn't pollute the JSON on STDOUT.
	$expires_human = gmdate( 'Y-m-d H:i:s \U\T\C', $data['expires_at'] );
	fwrite( STDERR, "\nSigned fee config:\n" );
	fwrite( STDERR, "  wallet:     {$wallet}\n" );
	fwrite( STDERR, "  fee:        {$bps} bps ({$bps}/100 of a percent)\n" );
	fwrite( STDERR, "  version:    {$version}\n" );
	fwrite( STDERR, "  expires:    {$expires_human}\n" );
	fwrite( STDERR, "  ttl:        {$ttl_days} days\n" );
	fwrite( STDERR, "\nUpload the JSON output to:\n" );
	fwrite( STDERR, "  https://cleardeskseo.com/api/agentpay/fee-config\n" );
}

function canonicalize( $data ) {
	$sorted = sort_keys_deep( $data );
	return json_encode( $sorted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
}

function sort_keys_deep( $value ) {
	if ( is_array( $value ) ) {
		$is_list = empty( $value ) || array_keys( $value ) === range( 0, count( $value ) - 1 );
		if ( $is_list ) {
			return array_map( 'sort_keys_deep', $value );
		}
		ksort( $value );
		foreach ( $value as $k => $v ) {
			$value[ $k ] = sort_keys_deep( $v );
		}
	}
	return $value;
}
