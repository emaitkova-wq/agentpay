<?php
namespace ClearWallet;

if (!defined('ABSPATH')) { exit; }

class Session {

    public static function issue(string $agent_id, string $tx_hash, array $opts = []) {
        $ttl    = (int) ($opts['ttl']    ?? Installer::setting('session_ttl', 3600));
        $budget = (int) ($opts['budget'] ?? Installer::setting('session_page_budget', 50));

        $jti = bin2hex(random_bytes(8));
        $claims = [
            'jti'    => $jti,
            'sub'    => $agent_id,
            'tx'     => $tx_hash,
            'iat'    => time(),
            'exp'    => time() + $ttl,
            'budget' => $budget,
        ];

        set_transient('clearwallet_sess_' . $jti, ['budget' => $budget, 'tx' => $tx_hash], $ttl);

        return self::encode($claims);
    }

    public static function verify(string $token) {
        $claims = self::decode($token);
        if (!$claims) { return null; }
        if (($claims['exp'] ?? 0) < time()) { return null; }

        $state = get_transient('clearwallet_sess_' . ($claims['jti'] ?? ''));
        if (!is_array($state)) { return null; }
        if (($state['budget'] ?? 0) <= 0) { return null; }

        return $claims + ['_state' => $state];
    }

    public static function consume(array $claims) {
        $jti = $claims['jti'] ?? '';
        if (!$jti) { return false; }
        $key = 'clearwallet_sess_' . $jti;
        $state = get_transient($key);
        if (!is_array($state)) { return false; }
        $state['budget'] = max(0, ((int) $state['budget']) - 1);
        $ttl = max(1, (int) $claims['exp'] - time());
        set_transient($key, $state, $ttl);
        return $state['budget'];
    }

    public static function revoke(string $jti) {
        delete_transient('clearwallet_sess_' . $jti);
    }

    protected static function encode(array $claims) {
        $payload = self::b64url(wp_json_encode($claims));
        $sig     = self::b64url(self::hmac($payload));
        return $payload . '.' . $sig;
    }

    protected static function decode(string $token) {
        $parts = explode('.', $token);
        if (count($parts) !== 2) { return null; }
        [$payload, $sig] = $parts;

        $expected = self::b64url(self::hmac($payload));
        if (!hash_equals($expected, $sig)) { return null; }

        $json = base64_decode(strtr($payload, '-_', '+/'), true);
        if ($json === false) { return null; }
        $claims = json_decode($json, true);
        return is_array($claims) ? $claims : null;
    }

    protected static function hmac(string $data) {
        $secret = get_option('clearwallet_session_secret');
        if (!$secret) {
            Installer::ensure_session_secret();
            $secret = get_option('clearwallet_session_secret');
        }
        return hash_hmac('sha256', $data, $secret, true);
    }

    protected static function b64url(string $bin) {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }
}
