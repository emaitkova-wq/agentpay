<?php
/**
 * Discovery — Agent endpoint advertisement
 *
 * Publishes ClearWallet's REST endpoints in two machine-readable locations so
 * AI agents can discover the paywall without prior knowledge of the plugin:
 *
 *   GET /.well-known/clearwallet   → JSON discovery document (RFC 8615)
 *   GET /robots.txt             → comments pointing at the discovery doc
 *
 * .well-known is the standardized location for site metadata. robots.txt
 * comments aren't a formal discovery spec, but well-behaved scrapers and
 * crawlers read them, and including the discovery URL there gives agents
 * a fallback path if they don't probe .well-known first.
 *
 * Operators can:
 *   - Disable entirely: add_filter('clearwallet_discovery_enabled', '__return_false')
 *   - Customize the JSON payload: add_filter('clearwallet_discovery_payload', $cb)
 *   - Customize the robots.txt lines: add_filter('clearwallet_robots_txt_lines', $cb)
 *
 * @package ClearWallet
 * @since   1.3.0
 */

namespace ClearWallet;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Discovery {

	const QUERY_VAR = 'clearwallet_well_known';
	const VERSION   = 1;
	const REST_NS   = 'clearwallet/v1';

	public static function init() {
		add_action( 'init',              array( __CLASS__, 'register_rewrite' ) );
		add_filter( 'query_vars',        array( __CLASS__, 'register_query_var' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_serve_discovery' ), 1 );
		add_filter( 'robots_txt',        array( __CLASS__, 'inject_robots_lines' ), 10, 2 );
	}

	/**
	 * Register the rewrite rule that maps /.well-known/clearwallet to our handler.
	 * Must run on `init` so WordPress sees it during route resolution.
	 */
	public static function register_rewrite() {
		add_rewrite_rule(
			'^\.well-known/clearwallet/?$',
			'index.php?' . self::QUERY_VAR . '=1',
			'top'
		);
	}

	public static function register_query_var( $vars ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Fires on template_redirect. If the request hit our rewrite rule, output
	 * the discovery JSON and exit. Otherwise no-op (let WP serve the page).
	 */
	public static function maybe_serve_discovery() {
		if ( ! get_query_var( self::QUERY_VAR ) ) {
			return;
		}

		if ( ! apply_filters( 'clearwallet_discovery_enabled', true ) ) {
			status_header( 404 );
			exit;
		}

		$payload = self::build_discovery_payload();

		// Kill any output buffering and cached headers from earlier in the stack.
		while ( ob_get_level() ) { ob_end_clean(); }

		status_header( 200 );
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Cache-Control: public, max-age=3600' );
		header( 'X-Robots-Tag: noindex' );  // Don't index the discovery file in search engines

		echo wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		exit;
	}

	/**
	 * Build the discovery JSON payload. The shape is versioned (top-level
	 * `clearwallet` integer) so future plugin releases can add fields without
	 * breaking agents that parsed an earlier version.
	 *
	 * @return array
	 */
	public static function build_discovery_payload() {
		$base = rest_url( self::REST_NS );

		$network = self::get_setting( 'network', 'base' );
		$usdc    = self::get_setting( 'usdc_contract', '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913' );

		$payload = array(
			'clearwallet'      => self::VERSION,
			'name'          => 'ClearWallet',
			'protocol'      => 'x402',
			'detection'     => 'RFC 9421 Web Bot Auth (HTTP Message Signatures)',
			'currency'      => 'USDC',
			'network'       => $network,
			'usdc_contract' => $usdc,
			'endpoints'     => array(
				'rate_card' => array(
					'method'      => 'GET',
					'url'         => self::canonicalize_url( $base . '/rate-card' ),
					'description' => 'Current pricing for all monetized routes on this site. Returns JSON.',
					'auth'        => 'none',
				),
				'dispute_submit' => array(
					'method'      => 'POST',
					'url'         => self::canonicalize_url( $base . '/dispute' ),
					'description' => 'Submit a dispute for a paid request that returned a failure. Body: { tx_hash, reason, evidence_url? }',
					'auth'        => 'none',
				),
				'dispute_status' => array(
					'method'      => 'GET',
					'url'         => self::canonicalize_url( $base . '/dispute' ),
					'description' => 'Check status of a dispute. Query param: ?tx_hash=0x...',
					'auth'        => 'none',
				),
			),
			'documentation' => 'https://cleardeskseo.com/wp-plugins',
		);

		return apply_filters( 'clearwallet_discovery_payload', $payload );
	}

	/**
	 * Append a small block of comments to robots.txt that points crawlers and
	 * scrapers at the discovery document. Comments are non-standard for
	 * discovery but widely parsed (similar to how `Sitemap:` lines work).
	 */
	public static function inject_robots_lines( $output, $public ) {
		if ( ! $public ) {
			// Site is in "discourage search engines" mode — respect that.
			return $output;
		}
		if ( ! apply_filters( 'clearwallet_discovery_enabled', true ) ) {
			return $output;
		}

		$well_known    = self::canonicalize_url( home_url( '/.well-known/clearwallet' ) );
		$rate_card_url = self::canonicalize_url( rest_url( self::REST_NS . '/rate-card' ) );
		$dispute_url   = self::canonicalize_url( rest_url( self::REST_NS . '/dispute' ) );

		$lines = array(
			'',
			'# ClearWallet — AI agent payment endpoints (x402 protocol over RFC 9421)',
			'# Machine-readable discovery doc: ' . $well_known,
			'# Rate card:      GET  ' . $rate_card_url,
			'# Submit dispute: POST ' . $dispute_url,
			'# Dispute status: GET  ' . $dispute_url . '?tx_hash=0x...',
			'',
		);

		$lines = apply_filters( 'clearwallet_robots_txt_lines', $lines );

		return rtrim( $output ) . "\n" . implode( "\n", $lines ) . "\n";
	}

	/**
	 * Activation hook — registers the rewrite rule and flushes so the new
	 * URL is recognized immediately.
	 */
	public static function activate() {
		self::register_rewrite();
		flush_rewrite_rules();
	}

	/**
	 * Deactivation — flush rules so the .well-known URL stops resolving.
	 */
	public static function deactivate() {
		flush_rewrite_rules();
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Helpers
	// ─────────────────────────────────────────────────────────────────────────

	private static function get_setting( $key, $default = null ) {
		if ( ! class_exists( '\ClearWallet\Installer' ) ) {
			return $default;
		}
		return Installer::setting( $key, $default );
	}

	/**
	 * Ensure URLs always have a scheme + host and use forward slashes.
	 * rest_url() and home_url() handle this on most installs but some
	 * sites with weird reverse-proxy configs produce relative URLs.
	 */
	private static function canonicalize_url( $url ) {
		if ( 0 === strpos( $url, '//' ) ) {
			$url = ( is_ssl() ? 'https:' : 'http:' ) . $url;
		}
		if ( 0 !== strpos( $url, 'http' ) ) {
			$url = home_url( $url );
		}
		return $url;
	}
}
