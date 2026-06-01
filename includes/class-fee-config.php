<?php
/**
 * FeeConfig — remote-fetched, cryptographically signed fee wallet
 *
 * The 1% destination wallet for AgentPay fees is no longer hardcoded in the
 * source. Instead the plugin fetches a signed JSON document from cleardeskseo.com
 * and verifies the Ed25519 signature against an embedded ClearDesk public key.
 *
 * This means:
 *   - The fee wallet address is not visible in the public GPL source
 *   - ClearDesk can rotate the wallet (compromise, custodian change, upgrade)
 *     without shipping a new plugin release
 *   - The signed response means a network attacker who intercepts the fetch
 *     can't redirect fees — they'd need the private key, which only lives on
 *     the cleardeskseo.com server
 *   - If verification fails OR the endpoint is unreachable past the grace
 *     period, the plugin fails CLOSED: sweeps are skipped, fees accumulate
 *     in the operator's wallet, no money moves until config recovers
 *
 * Endpoint contract:
 *   GET https://cleardeskseo.com/api/agentpay/fee-config
 *   Response:
 *     {
 *       "data": {
 *         "fee_wallet": "0x...",
 *         "fee_bps":    100,
 *         "version":    1,
 *         "issued_at":  <unix ts>,
 *         "expires_at": <unix ts>
 *       },
 *       "signature": "<base64 Ed25519 sig over canonical JSON of data>"
 *     }
 *
 *   The signature covers the canonical JSON of the data object, sorted
 *   alphabetically at every level, no whitespace. Same scheme as the CDP
 *   wallet-auth reqHash to keep the codebase symmetric.
 *
 * Operator safety:
 *   - The local FEE_BPS = 100 constant in FeeProcessor is a HARD CEILING.
 *     Even if the remote response says 500 (5%), this class clamps it to 100.
 *     ClearDesk can promote the fee DOWN, never UP.
 *
 * @package AgentPay
 * @since   1.2.0
 */

namespace AgentPay;

if ( ! defined( 'ABSPATH' ) && ! defined( 'AGENTPAY_TESTING' ) ) {
	exit;
}

class FeeConfig {

	const ENDPOINT_URL    = 'https://cleardeskseo.com/api/agentpay/fee-config';

	/**
	 * ClearDesk SEO's Ed25519 public verification key, base64-encoded.
	 *
	 * The matching private key never leaves cleardeskseo.com. Updating this
	 * constant requires a plugin release; updating the wallet address it
	 * verifies does not.
	 *
	 * Format: 32-byte Ed25519 public key, base64-encoded.
	 */
	const CLEARDESK_PUBLIC_KEY = 'Mi9FA3O0fm6jHVN5da2hNdBUD1Euvgpri/vescSmJHg=';

	const CACHE_OPT       = 'agentpay_fee_config_cache';
	const LAST_FETCH_OPT  = 'agentpay_fee_config_last_fetch';
	const LAST_ERROR_OPT  = 'agentpay_fee_config_last_error';

	const DEFAULT_TTL_SEC = 86400;          // 1 day — used if response omits expires_at
	const MAX_TTL_SEC     = 31536000;       // 365 days — refuse responses claiming longer
	const GRACE_SEC       = 604800;         // 7 days — keep using last-known-good if endpoint down
	const FETCH_TIMEOUT   = 10;             // seconds
	const MIN_FETCH_GAP   = 300;            // throttle: don't refetch within 5 minutes of last attempt
	const MAX_FEE_BPS     = 100;            // ceiling enforced client-side. Remote can only lower.

	/** Test injection point. Skips all network + cache when set. */
	private static $test_override = null;

	/** Test endpoint override. Used by the test suite to stub the URL. */
	private static $endpoint_override = null;

	/** In-memory request cache so a single request only fetches once. */
	private static $request_cache = null;

	// ─────────────────────────────────────────────────────────────────────────
	// Public API
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Current fee wallet address. Returns null if no valid config available
	 * (callers must treat null as "skip sweep").
	 *
	 * @return string|null  0x-prefixed EVM address, or null if config invalid.
	 */
	public static function get_fee_wallet() {
		if ( null !== self::$test_override ) {
			return self::$test_override['wallet'];
		}
		$cfg = self::get_validated_config();
		return $cfg ? $cfg['fee_wallet'] : null;
	}

	/**
	 * Current fee in basis points. Clamped to MAX_FEE_BPS (the local ceiling).
	 * Returns null if no valid config available; callers should fall back to
	 * the local FeeProcessor::FEE_BPS constant for fee math.
	 *
	 * @return int|null  0-100 inclusive.
	 */
	public static function get_fee_bps() {
		if ( null !== self::$test_override ) {
			return min( self::MAX_FEE_BPS, (int) self::$test_override['bps'] );
		}
		$cfg = self::get_validated_config();
		if ( ! $cfg ) {
			return null;
		}
		return min( self::MAX_FEE_BPS, (int) $cfg['fee_bps'] );
	}

	/**
	 * Force a refetch on next call, bypassing cache. Used by admin "Refresh
	 * fee config" action.
	 */
	public static function flush_cache() {
		delete_option( self::CACHE_OPT );
		delete_option( self::LAST_FETCH_OPT );
		self::$request_cache = null;
	}

	/**
	 * Operator-facing status for the Fees admin tab.
	 *
	 * @return array { configured: bool, source: 'remote'|'grace'|'none',
	 *                wallet: ?string, fee_bps: ?int, expires_at: ?int,
	 *                last_error: ?string }
	 */
	public static function status() {
		$cached = self::cache_get();
		$err    = get_option( self::LAST_ERROR_OPT, '' );

		if ( ! $cached ) {
			return array(
				'configured' => false,
				'source'     => 'none',
				'wallet'     => null,
				'fee_bps'    => null,
				'expires_at' => null,
				'last_error' => $err ?: 'No fee config fetched yet.',
			);
		}

		$now    = time();
		$source = ( $cached['expires_at'] > $now ) ? 'remote' : 'grace';

		return array(
			'configured' => true,
			'source'     => $source,
			'wallet'     => $cached['fee_wallet'],
			'fee_bps'    => min( self::MAX_FEE_BPS, (int) $cached['fee_bps'] ),
			'expires_at' => (int) $cached['expires_at'],
			'last_error' => $err ?: '',
		);
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Internal config resolution
	// ─────────────────────────────────────────────────────────────────────────

	private static function get_validated_config() {
		if ( null !== self::$request_cache ) {
			return self::$request_cache;
		}

		$now    = time();
		$cached = self::cache_get();

		// Fresh cache hit → use it.
		if ( $cached && $cached['expires_at'] > $now ) {
			self::$request_cache = $cached;
			return $cached;
		}

		// Throttle: don't refetch more often than MIN_FETCH_GAP seconds.
		$last_fetch = (int) get_option( self::LAST_FETCH_OPT, 0 );
		if ( $last_fetch && ( $now - $last_fetch ) < self::MIN_FETCH_GAP ) {
			// Within throttle window. Use cache if we have it (even stale, within grace).
			if ( $cached && ( $cached['expires_at'] + self::GRACE_SEC ) > $now ) {
				self::$request_cache = $cached;
				return $cached;
			}
			return null;
		}

		// Attempt fresh fetch.
		update_option( self::LAST_FETCH_OPT, $now, false );
		$fresh = self::fetch_remote();

		if ( $fresh ) {
			self::cache_set( $fresh );
			delete_option( self::LAST_ERROR_OPT );
			self::$request_cache = $fresh;
			return $fresh;
		}

		// Fetch failed. Fall back to stale cache if within grace period.
		if ( $cached && ( $cached['expires_at'] + self::GRACE_SEC ) > $now ) {
			self::$request_cache = $cached;
			return $cached;
		}

		// Everything failed and grace expired. Return null → sweep will skip.
		self::$request_cache = null;
		return null;
	}

	private static function fetch_remote() {
		$url = self::$endpoint_override ?: self::ENDPOINT_URL;

		$response = wp_remote_get( $url, array(
			'timeout'   => self::FETCH_TIMEOUT,
			'sslverify' => true,
			'headers'   => array(
				'Accept'     => 'application/json',
				'User-Agent' => 'AgentPay/' . ( defined( 'AGENTPAY_VERSION' ) ? AGENTPAY_VERSION : 'dev' ),
			),
		) );

		if ( is_wp_error( $response ) ) {
			self::record_error( 'fetch failed: ' . $response->get_error_message() );
			return null;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( $code !== 200 ) {
			self::record_error( "endpoint returned HTTP {$code}" );
			return null;
		}

		$parsed = json_decode( $body, true );
		if ( ! is_array( $parsed ) || ! isset( $parsed['data'] ) || ! isset( $parsed['signature'] ) ) {
			self::record_error( 'response is not the expected { data, signature } shape' );
			return null;
		}

		if ( ! self::verify_signature( $parsed['data'], $parsed['signature'] ) ) {
			self::record_error( 'signature verification FAILED — refusing to use this config' );
			return null;
		}

		$validation = self::validate_data( $parsed['data'] );
		if ( true !== $validation ) {
			self::record_error( 'data validation failed: ' . $validation );
			return null;
		}

		return $parsed['data'];
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Signature verification + data validation
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Verify Ed25519 signature over canonical JSON of $data.
	 * @internal Public for direct test coverage.
	 */
	public static function verify_signature( $data, $signature_b64 ) {
		if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
			self::record_error( 'PHP sodium extension required for signature verification' );
			return false;
		}

		$public_key = base64_decode( self::CLEARDESK_PUBLIC_KEY, true );
		if ( false === $public_key || 32 !== strlen( $public_key ) ) {
			self::record_error( 'embedded ClearDesk public key is malformed' );
			return false;
		}

		$signature = base64_decode( $signature_b64, true );
		if ( false === $signature || 64 !== strlen( $signature ) ) {
			return false;
		}

		$message = self::canonicalize( $data );

		try {
			return sodium_crypto_sign_verify_detached( $signature, $message, $public_key );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Canonical JSON serialization: keys sorted alphabetically at every level,
	 * no whitespace, JSON_UNESCAPED_*. Must match exactly what the signing tool
	 * uses on the server side.
	 * @internal Public for direct test coverage.
	 */
	public static function canonicalize( $data ) {
		if ( class_exists( '\AgentPay\CdpClient' ) ) {
			$sorted = CdpClient::sort_keys_deep( $data );
		} else {
			$sorted = self::sort_keys_deep_local( $data );
		}
		return wp_json_encode( $sorted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	private static function sort_keys_deep_local( $value ) {
		if ( is_array( $value ) ) {
			$is_list = empty( $value ) || array_keys( $value ) === range( 0, count( $value ) - 1 );
			if ( $is_list ) {
				return array_map( array( __CLASS__, 'sort_keys_deep_local' ), $value );
			}
			ksort( $value );
			foreach ( $value as $k => $v ) {
				$value[ $k ] = self::sort_keys_deep_local( $v );
			}
		}
		return $value;
	}

	/**
	 * Validate the fields inside $data. Returns true on success, error string on failure.
	 * @internal Public for direct test coverage.
	 */
	public static function validate_data( $data ) {
		if ( ! is_array( $data ) ) {
			return 'data is not an object';
		}

		// Required fields.
		foreach ( array( 'fee_wallet', 'fee_bps', 'version', 'issued_at', 'expires_at' ) as $field ) {
			if ( ! array_key_exists( $field, $data ) ) {
				return "missing required field: {$field}";
			}
		}

		// Address shape.
		$wallet = (string) $data['fee_wallet'];
		if ( ! preg_match( '/^0x[a-fA-F0-9]{40}$/', $wallet ) ) {
			return 'fee_wallet is not a valid EVM address';
		}

		// Reject known-malicious / placeholder addresses.
		$lower = strtolower( $wallet );
		$forbidden = array(
			'0x0000000000000000000000000000000000000000', // zero address
			'0x000000000000000000000000000000000000dead', // burn
			'0xdeaddeaddeaddeaddeaddeaddeaddeaddeaddead', // burn
			'0xeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee', // eth placeholder
			'0xffffffffffffffffffffffffffffffffffffffff', // max address
			'0x0000000000000000000000000000000000000fee', // the old placeholder constant
		);
		if ( in_array( $lower, $forbidden, true ) ) {
			return 'fee_wallet is a known placeholder/burn address';
		}

		// Fee bps range.
		$bps = (int) $data['fee_bps'];
		if ( $bps < 0 || $bps > 1000 ) {
			return 'fee_bps must be between 0 and 1000 (max 10%)';
		}

		// Timestamps.
		$now      = time();
		$issued   = (int) $data['issued_at'];
		$expires  = (int) $data['expires_at'];

		if ( $issued > $now + 300 ) {
			return 'issued_at is more than 5 minutes in the future (clock skew?)';
		}
		if ( $expires <= $issued ) {
			return 'expires_at must be after issued_at';
		}
		if ( $expires <= $now ) {
			return 'config has already expired';
		}
		if ( ( $expires - $now ) > self::MAX_TTL_SEC ) {
			return 'expires_at is more than 365 days in the future (refusing)';
		}

		// Version sanity.
		$version = (int) $data['version'];
		if ( $version < 1 || $version > 10 ) {
			return 'version field outside known range';
		}

		return true;
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Cache + error logging
	// ─────────────────────────────────────────────────────────────────────────

	private static function cache_get() {
		$cached = get_option( self::CACHE_OPT, null );
		if ( ! is_array( $cached ) || ! isset( $cached['fee_wallet'] ) ) {
			return null;
		}
		return $cached;
	}

	private static function cache_set( $data ) {
		update_option( self::CACHE_OPT, $data, false );
	}

	private static function record_error( $msg ) {
		update_option( self::LAST_ERROR_OPT, $msg, false );
		if ( function_exists( 'error_log' ) ) {
			error_log( 'AgentPay FeeConfig: ' . $msg );
		}
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Test helpers — only callable when AGENTPAY_TESTING is defined.
	// ─────────────────────────────────────────────────────────────────────────

	public static function set_test_override( $wallet, $bps = 100 ) {
		if ( ! defined( 'AGENTPAY_TESTING' ) ) {
			return;
		}
		self::$test_override = array( 'wallet' => $wallet, 'bps' => (int) $bps );
		self::$request_cache = null;
	}

	public static function clear_test_override() {
		self::$test_override = null;
		self::$request_cache = null;
	}

	public static function set_endpoint_override( $url ) {
		if ( ! defined( 'AGENTPAY_TESTING' ) ) {
			return;
		}
		self::$endpoint_override = $url;
		self::$request_cache     = null;
	}

	public static function set_public_key_for_test( $public_key_b64 ) {
		if ( ! defined( 'AGENTPAY_TESTING' ) ) {
			return;
		}
		self::$test_public_key_override = $public_key_b64;
	}
	private static $test_public_key_override = null;
}


