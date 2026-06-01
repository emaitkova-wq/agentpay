<?php
/**
 * CDP Client — Coinbase Developer Platform REST API
 *
 * Direct PHP implementation of the CDP v2 REST API authentication, so the
 * WordPress operator never needs Node.js, the CDP SDK, or the CDP CLI to
 * provision a wallet for AgentPay.
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
 * @package AgentPay
 * @since   1.1.0
 */

namespace AgentPay;

if ( ! defined( 'ABSPATH' ) && ! defined( 'AGENTPAY_TESTING' ) ) {
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
	 * Provision a new EVM account on Base within the operator's CDP project.
	 *
	 * @param string $name 2-36 chars, alphanumeric + hyphens. Auto-sanitized.
	 * @return array|\WP_Error { address, name, policies, created_at, updated_at }
	 */
	public function create_evm_account( $name = 'agentpay' ) {
		if ( empty( $this->wallet_secret ) ) {
			return self::error( 'agentpay_wallet_secret_required',
				'Creating a wallet requires a Wallet Secret. Generate one in the Coinbase Developer Portal.' );
		}

		$sanitized = self::sanitize_account_name( $name );
		$body      = array( 'name' => $sanitized );
		$result    = $this->request( 'POST', '/evm/accounts', $body, true );

		if ( self::is_error( $result ) ) {
			return $result;
		}

		if ( empty( $result['address'] ) ) {
			return self::error( 'agentpay_cdp_no_address',
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
			return self::error( 'agentpay_invalid_address',
				'That doesn\'t look like a Base/Ethereum address. It should start with 0x and have 40 hex characters after.' );
		}

		$result = $this->request( 'GET', '/evm/accounts/' . rawurlencode( $address ), null, false );
		if ( self::is_error( $result ) ) {
			$data = method_exists( $result, 'get_error_data' ) ? $result->get_error_data() : null;
			if ( is_array( $data ) && isset( $data['status_code'] ) && 404 === $data['status_code'] ) {
				return self::error( 'agentpay_address_not_in_project',
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
			$args['body'] = wp_json_encode( $body );
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
			$msg = $this->translate_cdp_error( $code, $data );
			$this->last_error = $msg;
			return self::error(
				'agentpay_cdp_http_' . $code,
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
	public function translate_cdp_error( $code, $data ) {
		if ( 401 === $code ) {
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
			return self::error( 'agentpay_no_wallet_secret', 'Wallet Secret not configured.' );
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
			return self::error( 'agentpay_no_sodium', 'PHP sodium extension required for Ed25519 keys. Use an ES256 API key in CDP or upgrade PHP.' );
		}

		$decoded = base64_decode( $secret_b64, true );
		if ( false === $decoded || 64 !== strlen( $decoded ) ) {
			return self::error( 'agentpay_bad_ed25519_key',
				'API Key Secret is not a valid Ed25519 key. Expected base64 that decodes to 64 bytes.' );
		}

		// CDP packs seed||pubkey; sodium's sign_detached wants the 64-byte combined form,
		// which is exactly what CDP gives us. No further parsing needed.
		try {
			return sodium_crypto_sign_detached( $message, $decoded );
		} catch ( \Throwable $e ) {
			return self::error( 'agentpay_ed25519_sign_failed', 'Ed25519 signing failed: ' . $e->getMessage() );
		}
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
			return self::error( 'agentpay_bad_es256_pem',
				'Could not parse the EC private key. Verify it includes the BEGIN/END lines exactly as exported.' );
		}

		$sig_der = '';
		$ok      = openssl_sign( $message, $sig_der, $pkey, OPENSSL_ALGO_SHA256 );
		if ( ! $ok ) {
			return self::error( 'agentpay_es256_sign_failed', 'ES256 signing failed.' );
		}

		return self::ecdsa_der_to_raw( $sig_der, 32 );
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
		if ( false !== strpos( $secret, '-----BEGIN' ) ) {
			$secret = str_replace( array( "\\n", "\r\n", "\r" ), array( "\n", "\n", "\n" ), $secret );
			return trim( $secret ) . "\n";
		}
		return trim( $secret );
	}

	/**
	 * Wrap a base64-encoded PKCS8 DER key in PEM boundaries so openssl_pkey_get_private accepts it.
	 * The CDP Wallet Secret is the inner base64 of a PKCS8 EC private key.
	 * @internal Public for test coverage.
	 */
	public function wallet_secret_to_pem( $b64 ) {
		$decoded = base64_decode( str_replace( array( "\n", "\r", ' ' ), '', $b64 ), true );
		if ( false === $decoded || strlen( $decoded ) < 32 ) {
			return self::error( 'agentpay_bad_wallet_secret',
				'Wallet Secret is not a valid base64-encoded private key.' );
		}
		$pem = "-----BEGIN PRIVATE KEY-----\n"
			. chunk_split( base64_encode( $decoded ), 64, "\n" )
			. "-----END PRIVATE KEY-----\n";
		return $pem;
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
			return self::error( 'agentpay_bad_der', 'ECDSA DER missing SEQUENCE tag.' );
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
			return self::error( 'agentpay_bad_der', 'ECDSA DER missing R or S integer.' );
		}
		if ( strlen( $r ) > $coord_len || strlen( $s ) > $coord_len ) {
			return self::error( 'agentpay_bad_der', 'ECDSA R/S exceeds coordinate length.' );
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
			$name = 'agentpay-' . substr( md5( uniqid( '', true ) ), 0, 8 );
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


