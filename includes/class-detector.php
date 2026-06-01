<?php
namespace ClearWallet;

use ClearWallet\HttpSig\Verifier;
use ClearWallet\HttpSig\KeyResolver;

if (!defined('ABSPATH')) { exit; }

class Detector {

    const KNOWN_AGENTS = [
        'ClaudeBot'           => 'anthropic',
        'Claude-User'         => 'anthropic',
        'GPTBot'              => 'openai',
        'ChatGPT-User'        => 'openai',
        'OAI-SearchBot'       => 'openai',
        'PerplexityBot'       => 'perplexity',
        'Perplexity-User'     => 'perplexity',
        'Google-Extended'     => 'google',
        'Applebot-Extended'   => 'apple',
        'CCBot'               => 'common-crawl',
        'Bytespider'          => 'bytedance',
        'cohere-ai'           => 'cohere',
        'Amazonbot'           => 'amazon',
        'Meta-ExternalAgent'  => 'meta',
    ];

    public static function is_agent(array $server) {
        return self::detect($server)['is_agent'];
    }

    public static function detect(array $server) {
        $headers = self::headers_from_server($server);

        if (!empty($headers['signature-input']) && !empty($headers['signature'])) {
            $verifier = self::build_verifier();
            $message = self::message_from_server($server, $headers);
            $result = $verifier->verify($message);
            if (!empty($result['ok'])) {
                return [
                    'is_agent'    => true,
                    'source'      => 'web-bot-auth',
                    'agent_id'    => ($result['operator'] ?: 'verified') . ':' . $result['keyid'],
                    'fingerprint' => $result['keyid'],
                    'operator'    => $result['operator'] ?: 'verified',
                    'verified'    => true,
                    'sig_label'   => $result['label'],
                    'sig_alg'     => $result['alg'],
                    'sig_tag'     => $result['tag'] ?? null,
                ];
            }
            self::record_sig_failure($result);
        }

        $ua = $server['HTTP_USER_AGENT'] ?? '';
        foreach (self::KNOWN_AGENTS as $needle => $operator) {
            if (stripos($ua, $needle) !== false) {
                return [
                    'is_agent'    => true,
                    'source'      => 'user-agent',
                    'agent_id'    => $operator . ':ua:' . md5($ua),
                    'fingerprint' => 'ua:' . md5($ua),
                    'operator'    => $operator,
                    'verified'    => false,
                ];
            }
        }

        $strict = (int) Installer::setting('detector_strict', 0);
        if ($strict && self::looks_headless($server, $ua)) {
            return [
                'is_agent'    => true,
                'source'      => 'heuristic',
                'agent_id'    => 'unknown:' . md5(($server['REMOTE_ADDR'] ?? '') . ':' . $ua),
                'fingerprint' => 'heur:' . md5($ua),
                'operator'    => 'unknown',
                'verified'    => false,
            ];
        }

        return ['is_agent' => false];
    }

    public static function headers_from_server(array $server) {
        $out = [];
        foreach ($server as $k => $v) {
            if (strpos($k, 'HTTP_') === 0) {
                $name = strtolower(str_replace('_', '-', substr($k, 5)));
                $out[$name] = $v;
            }
        }
        if (isset($server['CONTENT_TYPE']))   { $out['content-type']   = $server['CONTENT_TYPE']; }
        if (isset($server['CONTENT_LENGTH'])) { $out['content-length'] = $server['CONTENT_LENGTH']; }
        return $out;
    }

    protected static function message_from_server(array $server, array $headers) {
        $scheme = (!empty($server['HTTPS']) && $server['HTTPS'] !== 'off') ? 'https' : 'http';
        $uri = $server['REQUEST_URI'] ?? '/';
        $path = wp_parse_url($uri, PHP_URL_PATH) ?: '/';
        $query = wp_parse_url($uri, PHP_URL_QUERY);
        return [
            'method'    => $server['REQUEST_METHOD'] ?? 'GET',
            'scheme'    => $scheme,
            'authority' => $server['HTTP_HOST'] ?? '',
            'path'      => $path,
            'query'     => $query === null ? '' : $query,
            'headers'   => $headers,
        ];
    }

    protected static function build_verifier() {
        $resolver = new KeyResolver(function ($keyid) {
            return self::lookup_key($keyid);
        });
        $verifier = new Verifier($resolver);
        $verifier->clockSkew = (int) apply_filters('clearwallet_sig_clock_skew', 30);
        $verifier->maxAge    = (int) apply_filters('clearwallet_sig_max_age', 600);
        if (apply_filters('clearwallet_require_web_bot_auth_tag', false)) {
            $verifier->requiredTag = 'web-bot-auth';
        }
        return $verifier;
    }

    protected static function lookup_key($keyid) {
        $cache_key = 'clearwallet_jwk_' . md5($keyid);
        $cached = get_transient($cache_key);
        if (is_array($cached) && !empty($cached)) { return $cached; }

        $entries = [];
        foreach (self::directories() as $url => $operator) {
            $resp = wp_remote_get($url, ['timeout' => 5, 'redirection' => 2]);
            if (is_wp_error($resp)) { continue; }
            if (wp_remote_retrieve_response_code($resp) !== 200) { continue; }
            $body = json_decode(wp_remote_retrieve_body($resp), true);
            if (!isset($body['keys']) || !is_array($body['keys'])) { continue; }
            foreach ($body['keys'] as $jwk) {
                if (($jwk['kid'] ?? null) === $keyid) {
                    $entries[] = ['jwk' => $jwk, 'operator' => $operator];
                }
            }
            if (!empty($entries)) { break; }
        }

        $ttl = !empty($entries) ? HOUR_IN_SECONDS : 5 * MINUTE_IN_SECONDS;
        set_transient($cache_key, $entries, $ttl);
        return $entries;
    }

    public static function directories() {
        return apply_filters('clearwallet_known_directories', [
            'https://anthropic.com/.well-known/http-message-signatures-directory'  => 'anthropic',
            'https://openai.com/.well-known/http-message-signatures-directory'     => 'openai',
            'https://perplexity.ai/.well-known/http-message-signatures-directory'  => 'perplexity',
        ]);
    }

    protected static function record_sig_failure($result) {
        $err = $result['error'] ?? 'unknown';
        $detail = $result['detail'] ?? '';
        if (class_exists('\ClearWallet\Abuse')) {
            $fp = 'sig_fail:' . md5( ( isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '' ) . ':' . $err );
            Abuse::record($fp, 'bad_signature', $err . ($detail ? ':' . substr($detail, 0, 100) : ''));
        }
    }

    protected static function looks_headless(array $server, $ua) {
        if (empty($ua)) { return true; }
        if (stripos($ua, 'HeadlessChrome') !== false) { return true; }
        if (stripos($ua, 'PhantomJS') !== false) { return true; }
        return false;
    }
}
