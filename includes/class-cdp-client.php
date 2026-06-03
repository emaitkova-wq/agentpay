<?php
/**
 * CDP Client — Coinbase Developer Platform REST API
 *
 * Direct PHP implementation of the CDP v2 REST API authentication, so the
 * WordPress operator never needs Node.js, the CDP SDK, or the CDP CLI to
 * provision a wallet for ClearWallet.
 *
 * Authentication primer (verified against docs.cdp.coinbase.com Jan 2026):
 *
 *   Every request needs a Bearer Token (JWT signed with the API Secret).
 *   POST/DELETE on /v2/evm/accounts and /v2/evm/accounts/.../sign|export
 *   also need an X-Wallet-Auth header (JWT signed with the Wallet Secret).
 *
 *   API Secret comes in two flavours and we accept both:
 *     - Ed25519 (default since 2025): base64 64-byte string (seed + pubkey).
 *       Signed with EdDSA via the sodium extension.
 *     - ES256 (legacy): PEM block beginning with -----BEGIN EC PRIVATE KEY-----.
 *       Signed with ECDSA P-256 via openssl_sign.
 *
 *   Wallet Secret is a base64-encoded PKCS8 DER private key (P-256). Always
 *   signed with ES256. The Wallet Auth payload carries:
 *     - uris: ["METHOD api.cdp.coinbase.com/platform/v2/..."]   (array, one element)
 *     - reqHash: sha256_hex(canonical_json(body))               (only when body present)
 *     - iat, nbf, jti                                            (standard)
 *
 *   Canonical JSON for reqHash = JSON with object keys sorted alphabetically at
 *   every level, no whitespace, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES.
 *
 * Endpoints used here:
 *   GET  /platform/v2/evm/accounts                  list accounts (test creds)
 *   POST /platform/v2/evm/accounts                  create a new EVM account
 *   GET  /platform/v2/evm/accounts/{address}        verify a specific account
 *
 * @package ClearWallet
 * @since   1.1.0
 */

namespace ClearWallet;

if ( ! defined( 'ABSPATH' ) && ! defined( 'CLEARWALLET_TESTING' ) ) {
	exit;
}

class CdpClient {

	const API_HOST          = 'api.cdp.coinbase.com';
	const API_BASE          = '/platform/v2';
	const BEARER_TTL_SEC    = 120;
	const WALLET_AUTH_TTL_SEC = 60;

	// Key type constants for API Secret format detection.
	const KEY_TYPE_ED25519 = 'ed25519';
	const KEY_TYPE_ES256   = 'es256';

	// USDC contract addresses by network. Used for gasless EIP-3009
	// transfers (fee sweeps and refunds) and the EIP-712 signing domain.
	const USDC_BASE          = '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913';
	const USDC_BASE_SEPOLIA  = '0x036CbD53842c5426634e7929541eC2318f3dCF7e';

	/** @var string */
	private $api_key_id;

	/** @var string Raw input — either base64 Ed25519 string or PEM ES256 block. */
	private $api_key_secret;

	/** @var string One of self::KEY_TYPE_*. Detected from the secret format. */
	private $api_key_type;

	/** @var string|null Base64-encoded PKCS8 DER (P-256) — required for write ops only. */
	private $wallet_secret;

	/** @var string Populated when the last operation returned a WP_Error. */
	public $last_error = '';

	/**
	 * @param string      $api_key_id     UUID from CDP Portal.
	 * @param string      $api_key_secret Either base64 (Ed25519) or PEM (ES256).
	 * @param string|null $wallet_secret  Base64-encoded PKCS8 DER. Optional for read-only ops.
	 */
	public function __construct( $api_key_id, $api_key_secret, $wallet_secret = null ) {
		$this->api_key_id     = trim( (string) $api_key_id );
		$this->api_key_secret = self::normalize_secret_input( $api_key_secret );
		$this->api_key_type   = self::detect_key_type( $this->api_key_secret );
		$this->wallet_secret  = $wallet_secret ? trim( (string) $wallet_secret ) : null;
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Public API
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Read-only credential test. Hits GET /evm/accounts.
	 *
	 * @return true|\WP_Error
	 */
	public function test_connection() {
		$result = $this->request( 'GET', '/evm/accounts', null, false );
		if ( self::is_error( $result ) ) {
			return $result;
		}
		return true;
	}

	/**
	 * Read a wallet's USDC balance (atomic units, 6 decimals) straight from the
	 * Base RPC via balanceOf. This is public chain data, so it needs no CDP
	 * auth and works on front-end and admin requests alike.
	 *
	 * @param string $address 0x EVM address to check.
	 * @param string $network 'base' (mainnet) or 'base-sepolia'.
	 * @return int|\WP_Error Atomic USDC balance, or an error.
	 */
	public static function usdc_balance( $address, $network = 'base' ) {
		if ( ! preg_match( '/^0x[0-9a-fA-F]{40}$/', (string) $address ) ) {
			return self::error( 'clearwallet_bad_address', 'Wallet address is not a valid 0x address.' );
		}
		$rpc  = ( 'base-sepolia' === $network ) ? 'https://sepolia.base.org' : 'https://mainnet.base.org';
		$usdc = self::usdc_contract( $network );
		// balanceOf(address): selector 70a08231 + the address left-padded to 32 bytes.
		$call = '0x70a08231' . str_pad( strtolower( substr( $address, 2 ) ), 64, '0', STR_PAD_LEFT );

		$res = wp_remote_post( $rpc, array(
			'timeout' => 15,
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'eth_call',
				'params'  => array( array( 'to' => $usdc, 'data' => $call ), 'latest' ),
			) ),
		) );
		if ( is_wp_error( $res ) ) {
			return self::error( 'clearwallet_rpc_failed', 'Could not reach the Base network to read your balance.' );
		}
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( ! isset( $body['result'] ) || ! is_string( $body['result'] ) ) {
			$why = isset( $body['error']['message'] ) ? $body['error']['message'] : 'unexpected RPC response';
			return self::error( 'clearwallet_rpc_failed', 'Balance lookup failed: ' . $why );
		}
		$hex = ltrim( substr( $body['result'], 2 ), '0' );
		return ( '' === $hex ) ? 0 : self::hex_to_int( $hex );
	}

	/** Convert a hex string (no 0x prefix) to an integer, big-int-safe where possible. */
	private static function hex_to_int( $hex ) {
		if ( function_exists( 'gmp_init' ) ) {
			return (int) gmp_strval( gmp_init( $hex, 16 ) );
		}
		if ( function_exists( 'bcadd' ) ) {
			$dec = '0';
			$len = strlen( $hex );
			for ( $i = 0; $i < $len; $i++ ) {
				$dec = bcadd( bcmul( $dec, '16' ), (string) hexdec( $hex[ $i ] ) );
			}
			return (int) $dec;
		}
		return (int) hexdec( $hex );
	}

	/**
	 * Provision a new EVM account on Base within the operator's CDP project.
	 *
	 * @param string $name 2-36 chars, alphanumeric + hyphens. Auto-sanitized.
	 * @return array|\WP_Error { address, name, policies, created_at, updated_at }
	 */
	public function create_evm_account( $name = 'clearwallet' ) {
		if ( empty( $this->wallet_secret ) ) {
			return self::error( 'clearwallet_wallet_secret_required',
				'Creating a wallet requires a Wallet Secret. Generate one in the Coinbase Developer Portal.' );
		}

		$sanitized = self::sanitize_account_name( $name );
		$body      = array( 'name' => $sanitized );
		$result    = $this->request( 'POST', '/evm/accounts', $body, true );

		if ( self::is_error( $result ) ) {
			return $result;
		}

		if ( empty( $result['address'] ) ) {
			return self::error( 'clearwallet_cdp_no_address',
				'CDP returned a response without an address.', $result );
		}

		return array(
			'address'    => $result['address'],
			'name'       => isset( $result['name'] ) ? $result['name'] : $sanitized,
			'policies'   => isset( $result['policies'] ) ? $result['policies'] : array(),
			'created_at' => isset( $result['createdAt'] ) ? $result['createdAt'] : null,
			'updated_at' => isset( $result['updatedAt'] ) ? $result['updatedAt'] : null,
		);
	}

	/**
	 * USDC contract address for a given network.
	 *
	 * @param string $network 'base' or 'base-sepolia'.
	 * @return string Checksummed contract address.
	 */
	public static function usdc_contract( $network ) {
		return ( 'base-sepolia' === $network ) ? self::USDC_BASE_SEPOLIA : self::USDC_BASE;
	}

	/**
	 * Convert an internal network name to its CAIP-2 chain identifier, which is
	 * what the x402 v2 wire format (and the CDP facilitator) require. The
	 * internal names ('base' / 'base-sepolia') are kept everywhere else for
	 * USDC-contract and EIP-712 domain selection; only the on-the-wire
	 * `network` fields are converted. Idempotent if already a CAIP-2 string.
	 *
	 * @param string $network 'base', 'base-sepolia', or an eip155:* string.
	 * @return string e.g. 'eip155:8453' (Base mainnet) or 'eip155:84532' (Base Sepolia).
	 */
	public static function to_caip2( $network ) {
		if ( 0 === strpos( (string) $network, 'eip155:' ) ) {
			return $network; // already CAIP-2
		}
		return ( 'base-sepolia' === $network ) ? 'eip155:84532' : 'eip155:8453';
	}

	/**
	 * Build a CdpClient from the credentials saved in the WordPress options
	 * table. Decrypts the API Key Secret and Wallet Secret via AdminSetup's
	 * at-rest encryption. Returns a ready-to-use client or a WP_Error if the
	 * operator hasn't connected CDP yet.
	 *
	 * @return CdpClient|\WP_Error
	 */
	public static function from_stored_credentials() {
		$id         = get_option( 'clearwallet_cdp_api_key_id', '' );
		$secret_enc = get_option( 'clearwallet_cdp_api_key_secret', '' );
		$wallet_enc = get_option( 'clearwallet_cdp_wallet_secret', '' );

		if ( empty( $id ) || empty( $secret_enc ) ) {
			return self::error(
				'clearwallet_no_credentials',
				'CDP credentials are not configured. Connect your Coinbase Developer Platform keys in ClearWallet → Setup.'
			);
		}

		// Decrypt the at-rest secrets with our OWN decryptor rather than
		// AdminSetup::decrypt(): credentials are read during FRONT-END agent
		// requests, where the admin-only AdminSetup class is NOT loaded. The
		// previous reliance on it meant the still-encrypted "v1:..." value was
		// handed to the signer, which failed as "not valid base64".
		$secret = self::decrypt_stored( $secret_enc );
		if ( '' === $secret && '' !== (string) $secret_enc ) {
			return self::error( 'clearwallet_decrypt_failed',
				'Stored CDP credentials could not be decrypted. This usually means the site security '
				. 'keys (salts) changed since the key was saved. Re-enter your CDP API key in ClearWallet -> Setup.' );
		}
		$wallet = '';
		if ( ! empty( $wallet_enc ) ) {
			$wallet = self::decrypt_stored( $wallet_enc );
		}

		return new self( $id, $secret, $wallet ?: null );
	}

	/**
	 * Decrypt a secret produced by AdminSetup::encrypt(). Implemented here so it
	 * works on front-end requests (where the admin class is not loaded). MUST
	 * stay in sync with AdminSetup::encrypt()/decrypt(): AES-256-CBC keyed by
	 * sha256( wp_salt('auth') . 'clearwallet-cdp' ), payload "v1:" . base64(iv.ct).
	 * Values without the "v1:" prefix are treated as legacy plaintext.
	 *
	 * @param string $stored Stored option value.
	 * @return string Decrypted plaintext, or '' if it could not be decrypted.
	 */
	private static function decrypt_stored( $stored ) {
		$stored = (string) $stored;
		if ( 0 !== strpos( $stored, 'v1:' ) || ! function_exists( 'openssl_decrypt' ) ) {
			return $stored; // legacy plaintext, or no OpenSSL to decrypt with
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

	/**
	 * Sign an EIP-3009 transferWithAuthorization message as this wallet, via
	 * CDP's /sign/typed-data endpoint. The signed authorization can be relayed
	 * by any party (the x402 facilitator, here) to execute the USDC transfer
	 * on-chain — this wallet never needs ETH for gas.
	 *
	 * This is the same primitive an agent uses to pay a merchant in x402: the
	 * token holder signs an off-chain typed-data authorization and a relayer
	 * broadcasts the on-chain transferWithAuthorization() call, paying gas.
	 *
	 * @param string $from          This wallet's address (0x...).
	 * @param string $to            Destination address (0x...).
	 * @param int    $amount_atomic Amount in USDC atomic units (6 decimals).
	 * @param string $network       'base' or 'base-sepolia'.
	 * @return array|\WP_Error { authorization: array, signature: string } or error.
	 */
	public function sign_eip3009_authorization( $from, $to, $amount_atomic, $network ) {
		if ( empty( $this->wallet_secret ) ) {
			return self::error( 'clearwallet_no_wallet_secret',
				'Wallet Secret is required to sign transfer authorizations.' );
		}

		$now = time();
		$authorization = array(
			'from'        => $from,
			'to'          => $to,
			'value'       => (string) $amount_atomic,
			'validAfter'  => '0',
			'validBefore' => (string) ( $now + 300 ),
			'nonce'       => '0x' . bin2hex( random_bytes( 32 ) ),
		);

		// USDC's EIP-712 domain. The contract reports name "USDC" on Base
		// Sepolia and "USD Coin" on Base mainnet — both version "2".
		$chain_id    = ( 'base-sepolia' === $network ) ? 84532 : 8453;
		$domain_name = ( 'base-sepolia' === $network ) ? 'USDC' : 'USD Coin';

		$typed_data = array(
			'domain' => array(
				'name'              => $domain_name,
				'version'           => '2',
				'chainId'           => $chain_id,
				'verifyingContract' => self::usdc_contract( $network ),
			),
			'types' => array(
				'EIP712Domain' => array(
					array( 'name' => 'name',              'type' => 'string'  ),
					array( 'name' => 'version',           'type' => 'string'  ),
					array( 'name' => 'chainId',           'type' => 'uint256' ),
					array( 'name' => 'verifyingContract', 'type' => 'address' ),
				),
				'TransferWithAuthorization' => array(
					array( 'name' => 'from',        'type' => 'address' ),
					array( 'name' => 'to',          'type' => 'address' ),
					array( 'name' => 'value',       'type' => 'uint256' ),
					array( 'name' => 'validAfter',  'type' => 'uint256' ),
					array( 'name' => 'validBefore', 'type' => 'uint256' ),
					array( 'name' => 'nonce',       'type' => 'bytes32' ),
				),
			),
			'primaryType' => 'TransferWithAuthorization',
			'message'     => $authorization,
		);

		$path   = '/evm/accounts/' . rawurlencode( $from ) . '/sign/typed-data';
		$result = $this->request( 'POST', $path, $typed_data, true );
		if ( self::is_error( $result ) ) {
			return $result;
		}

		$signature = isset( $result['signature'] ) ? $result['signature'] : '';
		if ( empty( $signature ) || 0 !== strpos( $signature, '0x' ) ) {
			return self::error( 'clearwallet_no_signature',
				'CDP /sign/typed-data did not return a signature.', $result );
		}

		return array(
			'authorization' => $authorization,
			'signature'     => $signature,
		);
	}

	/**
	 * Verify an address actually exists inside this CDP project. Used by the
	 * "I already have a wallet" flow so operators can't accidentally point the
	 * plugin at an address the plugin can't actually sign for.
	 *
	 * @param string $address EVM address starting with 0x.
	 * @return true|\WP_Error
	 */
	public function verify_existing_address( $address ) {
		$address = trim( (string) $address );

		if ( ! preg_match( '/^0x[a-fA-F0-9]{40}$/', $address ) ) {
			return self::error( 'clearwallet_invalid_address',
				'That doesn\'t look like a Base/Ethereum address. It should start with 0x and have 40 hex characters after.' );
		}

		$result = $this->request( 'GET', '/evm/accounts/' . rawurlencode( $address ), null, false );
		if ( self::is_error( $result ) ) {
			$data = method_exists( $result, 'get_error_data' ) ? $result->get_error_data() : null;
			if ( is_array( $data ) && isset( $data['status_code'] ) && 404 === $data['status_code'] ) {
				return self::error( 'clearwallet_address_not_in_project',
					'That address exists on Base but isn\'t in this Coinbase Developer project. Check that you pasted the right address and that your API key matches the project that owns the wallet.' );
			}
			return $result;
		}
		return true;
	}

	// ─────────────────────────────────────────────────────────────────────────
	// HTTP transport
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Execute an authenticated HTTP request.
	 *
	 * @return array|\WP_Error
	 */
	private function request( $method, $path, $body, $needs_wallet_auth ) {
		$bearer = $this->generate_bearer_jwt( $method, $path );
		if ( self::is_error( $bearer ) ) {
			$this->last_error = self::error_message( $bearer );
			return $bearer;
		}

		$headers = array(
			'Authorization' => 'Bearer ' . $bearer,
			'Content-Type'  => 'application/json',
			'Accept'        => 'application/json',
		);

		if ( $needs_wallet_auth ) {
			$wallet_jwt = $this->generate_wallet_auth_jwt( $method, $path, $body );
			if ( self::is_error( $wallet_jwt ) ) {
				$this->last_error = self::error_message( $wallet_jwt );
				return $wallet_jwt;
			}
			$headers['X-Wallet-Auth'] = $wallet_jwt;
		}

		$args = array(
			'method'  => $method,
			'headers' => $headers,
			'timeout' => 30,
		);

		if ( null !== $body ) {
			// Send canonical JSON (alphabetically-sorted keys, no extra
			// whitespace) so the bytes on the wire match the reqHash computed
			// inside the wallet-auth JWT. For single-field bodies this equals
			// wp_json_encode, but for multi-field bodies (e.g. typed-data
			// signing) insertion-order encoding would hash differently than
			// the canonical reqHash and CDP would reject the X-Wallet-Auth.
			$args['body'] = self::json_encode_canonical( self::sort_keys_deep( $body ) );
		}

		$url = 'https://' . self::API_HOST . self::API_BASE . $path;
		$res = wp_remote_request( $url, $args );

		if ( self::is_error( $res ) ) {
			$this->last_error = self::error_message( $res );
			return $res;
		}

		$code = wp_remote_retrieve_response_code( $res );
		$raw  = wp_remote_retrieve_body( $res );
		$data = json_decode( $raw, true );

		if ( $code >= 400 ) {
			$msg = $this->translate_cdp_error( $code, $data, (bool) $needs_wallet_auth );
			$this->last_error = $msg;
			return self::error(
				'clearwallet_cdp_http_' . $code,
				$msg,
				array(
					'status_code' => $code,
					'response'    => $data,
				)
			);
		}

		return is_array( $data ) ? $data : array();
	}

	/**
	 * Map CDP error codes to operator-facing English.
	 * @internal Public only for direct test coverage.
	 */
	public function translate_cdp_error( $code, $data, $used_wallet_auth = false ) {
		if ( 401 === $code ) {
			if ( $used_wallet_auth ) {
				return 'Coinbase rejected the request signature. If agent payments already work, your API key '
					. 'is fine and the Wallet Secret is the likely cause — re-generate it in the Portal (it is shown '
					. 'only once) and re-enter it in ClearWallet -> Setup.';
			}
			return 'Coinbase rejected your API credentials. Double-check the API Key ID and API Key Secret in the Portal.';
		}
		if ( 403 === $code ) {
			return 'Your CDP API key is missing a permission. In the Portal, edit the key and enable the "Wallet" scope.';
		}
		if ( 404 === $code ) {
			return 'That resource doesn\'t exist in your CDP project.';
		}
		if ( 422 === $code ) {
			return isset( $data['message'] ) ? $data['message'] : 'Request data was rejected by Coinbase.';
		}
		if ( 429 === $code ) {
			return 'Coinbase rate-limited the request. Wait a moment and try again.';
		}
		if ( 500 <= $code ) {
			return 'Coinbase Developer Platform is having trouble right now. Try again in a few minutes.';
		}
		return isset( $data['message'] ) ? $data['message'] : sprintf( 'Coinbase returned HTTP %d.', $code );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Bearer JWT — for the Authorization header.
	// Ed25519 (preferred since 2025) or ES256 (legacy).
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * @return string|\WP_Error
	 */
	public function generate_bearer_jwt( $method, $path ) {
		$uri = strtoupper( $method ) . ' ' . self::API_HOST . self::API_BASE . $path;

		$alg     = ( self::KEY_TYPE_ED25519 === $this->api_key_type ) ? 'EdDSA' : 'ES256';
		$now     = time();
		$header  = array(
			'alg'   => $alg,
			'typ'   => 'JWT',
			'kid'   => $this->api_key_id,
			'nonce' => self::random_hex( 16 ),
		);
		$payload = array(
			'sub' => $this->api_key_id,
			'iss' => 'cdp',
			'aud' => array( 'cdp_service' ),
			'nbf' => $now,
			'exp' => $now + self::BEARER_TTL_SEC,
			'uri' => $uri,
		);

		return $this->sign_jwt_with_api_secret( $header, $payload );
	}

	/**
	 * @return string|\WP_Error
	 */
	private function sign_jwt_with_api_secret( $header, $payload ) {
		$segments = array(
			self::b64url( self::json_encode_canonical( $header ) ),
			self::b64url( self::json_encode_canonical( $payload ) ),
		);
		$signing_input = implode( '.', $segments );

		if ( self::KEY_TYPE_ED25519 === $this->api_key_type ) {
			$signature = $this->sign_ed25519( $signing_input, $this->api_key_secret );
		} else {
			$signature = $this->sign_es256_pem( $signing_input, $this->api_key_secret );
		}

		if ( self::is_error( $signature ) ) {
			return $signature;
		}

		$segments[] = self::b64url( $signature );
		return implode( '.', $segments );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Wallet Auth JWT — for the X-Wallet-Auth header. Always ES256.
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * @return string|\WP_Error
	 */
	public function generate_wallet_auth_jwt( $method, $path, $body ) {
		if ( empty( $this->wallet_secret ) ) {
			return self::error( 'clearwallet_no_wallet_secret', 'Wallet Secret not configured.' );
		}

		$now     = time();
		$uri     = strtoupper( $method ) . ' ' . self::API_HOST . self::API_BASE . $path;
		$header  = array( 'alg' => 'ES256', 'typ' => 'JWT' );
		$payload = array(
			'iat'  => $now,
			'nbf'  => $now,
			'jti'  => self::random_hex( 16 ),
			'uris' => array( $uri ),
		);

		if ( null !== $body ) {
			$payload['reqHash'] = self::compute_req_hash( $body );
		}

		$segments = array(
			self::b64url( self::json_encode_canonical( $header ) ),
			self::b64url( self::json_encode_canonical( $payload ) ),
		);
		$signing_input = implode( '.', $segments );

		$pem = $this->wallet_secret_to_pem( $this->wallet_secret );
		if ( self::is_error( $pem ) ) {
			return $pem;
		}

		$signature = $this->sign_es256_pem( $signing_input, $pem );
		if ( self::is_error( $signature ) ) {
			return $signature;
		}

		$segments[] = self::b64url( $signature );
		return implode( '.', $segments );
	}

	/**
	 * SHA-256 hex of canonical (alphabetically sorted, no whitespace) JSON of $body.
	 * @internal Public for test coverage — see test/run-cdp-tests.php.
	 */
	public static function compute_req_hash( $body ) {
		$sorted    = self::sort_keys_deep( $body );
		$canonical = wp_json_encode( $sorted, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		return hash( 'sha256', $canonical );
	}

	/**
	 * Recursively sort an associative array (object) by keys. Lists keep order.
	 */
	public static function sort_keys_deep( $value ) {
		if ( is_array( $value ) ) {
			if ( self::is_list( $value ) ) {
				return array_map( array( __CLASS__, 'sort_keys_deep' ), $value );
			}
			ksort( $value );
			foreach ( $value as $k => $v ) {
				$value[ $k ] = self::sort_keys_deep( $v );
			}
		}
		return $value;
	}

	private static function is_list( array $a ) {
		if ( empty( $a ) ) {
			return true;
		}
		// PHP 8.1+ has array_is_list; manual check for 7.4+.
		if ( function_exists( 'array_is_list' ) ) {
			return array_is_list( $a );
		}
		$i = 0;
		foreach ( $a as $k => $_ ) {
			if ( $k !== $i++ ) {
				return false;
			}
		}
		return true;
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Signing primitives
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Sign with Ed25519 using sodium.
	 * @param string $message
	 * @param string $secret_b64 Base64-encoded 64-byte (seed||pubkey) Ed25519 secret.
	 * @return string|\WP_Error Raw 64-byte signature.
	 */
	private function sign_ed25519( $message, $secret_b64 ) {
		if ( ! function_exists( 'sodium_crypto_sign_detached' ) ) {
			return self::error( 'clearwallet_no_sodium', 'PHP sodium extension required for Ed25519 keys. Use an ES256 API key in CDP or upgrade PHP.' );
		}

		$secret_key = self::ed25519_secret_key_from_input( $secret_b64 );
		if ( self::is_error( $secret_key ) ) {
			return $secret_key;
		}

		try {
			return sodium_crypto_sign_detached( $message, $secret_key );
		} catch ( \Throwable $e ) {
			return self::error( 'clearwallet_ed25519_sign_failed', 'Ed25519 signing failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Derive a 64-byte libsodium Ed25519 secret key (seed||pubkey) from whatever
	 * shape CDP exported the API Key Secret in. The base64 secret may decode to:
	 *   - 64 bytes: seed||pubkey (a full libsodium secret key) — used as-is
	 *   - 32 bytes: the seed only — expanded into a full keypair
	 *   - 48 bytes: Ed25519 PKCS#8 DER — the 16-byte prefix is stripped, then the
	 *     32-byte seed is expanded
	 * Accepts standard or URL-safe base64 and ignores surrounding whitespace.
	 *
	 * @return string|\WP_Error 64-byte secret key, or an error.
	 */
	private static function ed25519_secret_key_from_input( $secret_b64 ) {
		$raw = preg_replace( '/\s+/', '', (string) $secret_b64 );

		// Try standard base64, then URL-safe (CDP uses standard, but be lenient).
		$decoded = base64_decode( $raw, true );
		if ( false === $decoded ) {
			$decoded = base64_decode( strtr( $raw, '-_', '+/' ), true );
		}
		if ( false === $decoded ) {
			return self::error( 'clearwallet_bad_ed25519_key',
				'API Key Secret is not valid base64' . self::describe_secret_shape( $secret_b64 )
				. '. Paste ONLY the privateKey value from your CDP API Key.' );
		}

		$len = strlen( $decoded );

		// Ed25519 PKCS#8 DER (48 bytes): 16-byte prefix + 32-byte seed.
		$pkcs8_prefix = "\x30\x2e\x02\x01\x00\x30\x05\x06\x03\x2b\x65\x70\x04\x22\x04\x20";
		if ( 48 === $len && $pkcs8_prefix === substr( $decoded, 0, 16 ) ) {
			$decoded = substr( $decoded, 16 ); // -> 32-byte seed
			$len     = 32;
		}

		// Already a full 64-byte libsodium secret key (seed||pubkey).
		if ( 64 === $len ) {
			return $decoded;
		}

		// A 32-byte seed: expand into a keypair and take the secret-key half.
		if ( 32 === $len ) {
			try {
				$keypair = sodium_crypto_sign_seed_keypair( $decoded );
				return sodium_crypto_sign_secretkey( $keypair );
			} catch ( \Throwable $e ) {
				return self::error( 'clearwallet_bad_ed25519_key', 'Could not expand the Ed25519 seed: ' . $e->getMessage() );
			}
		}

		return self::error( 'clearwallet_bad_ed25519_key',
			'API Key Secret did not decode to a recognized Ed25519 key (got ' . $len . ' bytes; expected 32, 48, or 64). '
			. 'Paste the privateKey value from your CDP API Key exactly as exported, or create an ES256 key instead.' );
	}

	/**
	 * Sign with ECDSA P-256 (ES256) using openssl. Converts ASN.1 DER output
	 * to JWT-compatible raw R||S (64 bytes for P-256).
	 *
	 * @param string $message
	 * @param string $pem PEM-encoded EC private key.
	 * @return string|\WP_Error
	 */
	private function sign_es256_pem( $message, $pem ) {
		$pkey = openssl_pkey_get_private( $pem );
		if ( false === $pkey ) {
			// The key may be a valid PEM whose body newlines were flattened by
			// the field it was pasted through. Re-wrap and retry once before
			// giving up — same robustness the Shopify build needed for CDP keys.
			$repaired = self::repair_pem( $pem );
			if ( $repaired !== $pem ) {
				$pkey = openssl_pkey_get_private( $repaired );
			}
		}
		if ( false === $pkey ) {
			return self::error( 'clearwallet_bad_es256_pem',
				'Could not parse the EC private key. Paste the privateKey value from your CDP API ' .
				'Key JSON exactly as exported (including the BEGIN/END lines).' );
		}

		$sig_der = '';
		$ok      = openssl_sign( $message, $sig_der, $pkey, OPENSSL_ALGO_SHA256 );
		if ( ! $ok ) {
			return self::error( 'clearwallet_es256_sign_failed', 'ES256 signing failed.' );
		}

		return self::ecdsa_der_to_raw( $sig_der, 32 );
	}

	/**
	 * Re-wrap a PEM whose body line breaks were stripped (e.g. pasted through a
	 * field that flattened them). Extracts the base64 between matching
	 * BEGIN/END markers and re-chunks it at 64 columns. Returns the input
	 * unchanged if it doesn't look like a PEM; idempotent for well-formed PEMs.
	 */
	private static function repair_pem( $pem ) {
		if ( ! preg_match( '/-----BEGIN ([A-Z0-9 ]+)-----(.*?)-----END \1-----/s', (string) $pem, $m ) ) {
			return $pem;
		}
		$label = $m[1];
		$body  = preg_replace( '/[^A-Za-z0-9+\/=]/', '', $m[2] );
		if ( '' === $body ) {
			return $pem;
		}
		return "-----BEGIN {$label}-----\n"
			. chunk_split( $body, 64, "\n" )
			. "-----END {$label}-----\n";
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Key parsing
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Decide whether the API Secret is Ed25519 (base64) or ES256 (PEM).
	 * @internal Public for test coverage.
	 */
	public static function detect_key_type( $secret ) {
		if ( '' === $secret ) {
			return self::KEY_TYPE_ED25519; // Default; will fail at signing time with a clear error.
		}
		if ( false !== strpos( $secret, '-----BEGIN' ) ) {
			return self::KEY_TYPE_ES256;
		}
		return self::KEY_TYPE_ED25519;
	}

	/**
	 * Normalize a multiline secret value: turn escaped \n into real newlines
	 * for PEM, trim wrapping whitespace for base64.
	 * @internal Public for test coverage.
	 */
	public static function normalize_secret_input( $secret ) {
		if ( null === $secret || '' === $secret ) {
			return '';
		}
		$secret = (string) $secret;

		// Forgive the most common paste mistake: the whole CDP API Key JSON file
		// (looks like {"id":"...","privateKey":"..."}). Pull out the key field.
		$lt = ltrim( $secret );
		if ( '' !== $lt && '{' === $lt[0] && false !== strpos( $secret, 'privateKey' ) ) {
			$decoded = json_decode( $secret, true );
			if ( is_array( $decoded ) ) {
				foreach ( array( 'privateKey', 'private_key', 'apiKeySecret', 'secret' ) as $k ) {
					if ( ! empty( $decoded[ $k ] ) && is_string( $decoded[ $k ] ) ) {
						$secret = $decoded[ $k ];
						break;
					}
				}
			}
		}

		if ( false !== strpos( $secret, '-----BEGIN' ) ) {
			$secret = str_replace( array( "\\n", "\r\n", "\r" ), array( "\n", "\n", "\n" ), $secret );
			return trim( $secret ) . "\n";
		}
		return trim( $secret );
	}

	/**
	 * Validate that a secret can actually sign, using the EXACT code paths the
	 * runtime uses — an ES256 PEM (ECDSA P-256) or an Ed25519 base64 secret in
	 * any exported shape. Returns true, or a WP_Error explaining the problem.
	 * The admin Setup form uses this so anything that saves will also work at
	 * pay time (no more "valid at save, 401 at runtime" mismatch).
	 *
	 * @return true|\WP_Error
	 */
	public static function validate_signing_secret( $secret ) {
		$secret = self::normalize_secret_input( $secret );
		if ( '' === $secret ) {
			return self::error( 'clearwallet_empty_secret', 'API Key Secret is empty.' );
		}
		if ( self::KEY_TYPE_ES256 === self::detect_key_type( $secret ) ) {
			$pkey = openssl_pkey_get_private( $secret );
			if ( false === $pkey ) {
				$pkey = openssl_pkey_get_private( self::repair_pem( $secret ) );
			}
			if ( false === $pkey ) {
				return self::error( 'clearwallet_bad_es256_pem',
					'API Key Secret looks like a PEM but could not be parsed as an EC private key. '
					. 'Paste the privateKey value exactly as exported, including the BEGIN/END lines.' );
			}
			return true;
		}
		// Ed25519: reuse the same loader the signer uses.
		$key = self::ed25519_secret_key_from_input( $secret );
		return self::is_error( $key ) ? $key : true;
	}

	/**
	 * Describe an unparseable secret WITHOUT revealing it — length, count of
	 * non-base64 characters, and whether it looks like JSON or a PEM. Mirrors
	 * the branded "shape" diagnostics that made the Shopify CDP key issues
	 * debuggable.
	 */
	private static function describe_secret_shape( $raw ) {
		$raw = (string) $raw;
		if ( '' === trim( $raw ) ) {
			return ' (the secret is empty)';
		}
		$hints = array();
		$lt    = ltrim( $raw );
		if ( '' !== $lt && '{' === $lt[0] ) {
			$hints[] = 'it looks like JSON — paste ONLY the privateKey value, not the whole file';
		}
		if ( false !== stripos( $raw, 'PRIVATE KEY' ) ) {
			$hints[] = 'it contains "PRIVATE KEY" (an ECDSA PEM) — paste it WITH its -----BEGIN/-----END lines';
		}
		$non_b64 = preg_match_all( '/[^A-Za-z0-9+\/=_-]/', $raw );
		$out     = ' (length ' . strlen( $raw ) . ', ' . (int) $non_b64 . ' non-base64 characters)';
		return $out . ( $hints ? ' — ' . implode( '; ', $hints ) : '' );
	}

	/**
	 * Wrap a base64-encoded PKCS8 DER key in PEM boundaries so openssl_pkey_get_private accepts it.
	 * The CDP Wallet Secret is the inner base64 of a PKCS8 EC private key.
	 * @internal Public for test coverage.
	 */
	public function wallet_secret_to_pem( $secret ) {
		$secret = (string) $secret;

		// Case 1: already PEM. Normalize escaped/CRLF newlines and return.
		if ( false !== strpos( $secret, '-----BEGIN' ) ) {
			$pem = str_replace( array( "\\n", "\r\n", "\r" ), array( "\n", "\n", "\n" ), $secret );
			return rtrim( $pem ) . "\n";
		}

		// Cases 2 & 3: base64 or base64url of PKCS#8 DER. Strip whitespace,
		// translate the base64url alphabet to standard, then decode.
		$clean   = str_replace( array( "\n", "\r", ' ', "\t" ), '', $secret );
		$clean   = strtr( $clean, '-_', '+/' );
		$decoded = base64_decode( $clean, true );

		if ( false === $decoded || strlen( $decoded ) < 48 ) {
			return self::error(
				'clearwallet_bad_wallet_secret',
				'Wallet Secret is not a valid base64-encoded PKCS#8 key (expected ≥48 bytes, ' .
				'typically ~138 for EC P-256). Re-copy the Wallet Secret value from the CDP portal.'
			);
		}

		return "-----BEGIN PRIVATE KEY-----\n"
			. chunk_split( base64_encode( $decoded ), 64, "\n" )
			. "-----END PRIVATE KEY-----\n";
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Helpers — public where tests need direct access.
	// ─────────────────────────────────────────────────────────────────────────

	public static function b64url( $data ) {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	public static function b64url_decode( $data ) {
		$pad = strlen( $data ) % 4;
		if ( $pad ) {
			$data .= str_repeat( '=', 4 - $pad );
		}
		return base64_decode( strtr( $data, '-_', '+/' ) );
	}

	/**
	 * Convert ASN.1 DER ECDSA signature to JWT-compatible R||S form.
	 * P-256 → 32-byte R, 32-byte S, 64 bytes total.
	 * @internal Public for test coverage.
	 */
	public static function ecdsa_der_to_raw( $der, $coord_len ) {
		$offset = 0;
		$len    = strlen( $der );
		if ( $len < 2 || "\x30" !== $der[ $offset ] ) {
			return self::error( 'clearwallet_bad_der', 'ECDSA DER missing SEQUENCE tag.' );
		}
		$offset++;

		$seq_len = ord( $der[ $offset++ ] );
		if ( $seq_len & 0x80 ) {
			$nb      = $seq_len & 0x7F;
			$seq_len = 0;
			for ( $i = 0; $i < $nb; $i++ ) {
				$seq_len = ( $seq_len << 8 ) | ord( $der[ $offset++ ] );
			}
		}

		$parse_int = function () use ( $der, &$offset ) {
			if ( "\x02" !== $der[ $offset ] ) {
				return false;
			}
			$offset++;
			$length = ord( $der[ $offset++ ] );
			$value  = substr( $der, $offset, $length );
			$offset += $length;
			while ( strlen( $value ) > 0 && "\x00" === $value[0] ) {
				$value = substr( $value, 1 );
			}
			return $value;
		};

		$r = $parse_int();
		$s = $parse_int();
		if ( false === $r || false === $s ) {
			return self::error( 'clearwallet_bad_der', 'ECDSA DER missing R or S integer.' );
		}
		if ( strlen( $r ) > $coord_len || strlen( $s ) > $coord_len ) {
			return self::error( 'clearwallet_bad_der', 'ECDSA R/S exceeds coordinate length.' );
		}

		$r = str_pad( $r, $coord_len, "\x00", STR_PAD_LEFT );
		$s = str_pad( $s, $coord_len, "\x00", STR_PAD_LEFT );
		return $r . $s;
	}

	public static function sanitize_account_name( $name ) {
		$name = strtolower( (string) $name );
		$name = preg_replace( '/[^a-z0-9-]/', '-', $name );
		$name = trim( preg_replace( '/-+/', '-', $name ), '-' );
		if ( strlen( $name ) < 2 ) {
			$name = 'clearwallet-' . substr( md5( uniqid( '', true ) ), 0, 8 );
		}
		return substr( $name, 0, 36 );
	}

	private static function random_hex( $bytes ) {
		return bin2hex( random_bytes( $bytes ) );
	}

	private static function json_encode_canonical( $data ) {
		return wp_json_encode( $data, JSON_UNESCAPED_SLASHES );
	}

	// Error helpers — abstracted so tests can run without the WP runtime.
	private static function error( $code, $message, $data = null ) {
		if ( class_exists( '\WP_Error' ) ) {
			return new \WP_Error( $code, $message, $data );
		}
		return (object) array(
			'_is_error'  => true,
			'error_code' => $code,
			'error_msg'  => $message,
			'error_data' => $data,
		);
	}

	private static function is_error( $val ) {
		if ( $val instanceof \WP_Error ) {
			return true;
		}
		return is_object( $val ) && ! empty( $val->_is_error );
	}

	private static function error_message( $val ) {
		if ( $val instanceof \WP_Error ) {
			return $val->get_error_message();
		}
		return $val->error_msg ?? '';
	}
}


