<?php
namespace AgentPay\HttpSig;

class Jwk {

    public static function parse(array $jwk) {
        $kty = $jwk['kty'] ?? '';

        if ($kty === 'OKP') {
            $crv = $jwk['crv'] ?? '';
            if ($crv !== 'Ed25519') { return null; }
            $x = self::b64url_decode($jwk['x'] ?? '');
            if ($x === null) { return null; }
            if (defined('SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES') &&
                strlen($x) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                return null;
            }
            return ['alg' => 'ed25519', 'key' => $x, 'kid' => $jwk['kid'] ?? null];
        }

        if ($kty === 'RSA') {
            $n = self::b64url_decode($jwk['n'] ?? '');
            $e = self::b64url_decode($jwk['e'] ?? '');
            if ($n === null || $e === null) { return null; }
            $pem = self::rsaJwkToPem($n, $e);
            if ($pem === null) { return null; }
            $alg = $jwk['alg'] ?? 'rsa-v1_5-sha256';
            $alg_map = [
                'RS256' => 'rsa-v1_5-sha256',
                'PS512' => 'rsa-pss-sha512',
            ];
            $alg = $alg_map[$alg] ?? $alg;
            return ['alg' => $alg, 'key' => $pem, 'kid' => $jwk['kid'] ?? null];
        }

        if ($kty === 'EC') {
            $crv = $jwk['crv'] ?? '';
            $x = self::b64url_decode($jwk['x'] ?? '');
            $y = self::b64url_decode($jwk['y'] ?? '');
            if ($x === null || $y === null) { return null; }
            $pem = self::ecJwkToPem($crv, $x, $y);
            if ($pem === null) { return null; }
            $alg_map = ['P-256' => 'ecdsa-p256-sha256', 'P-384' => 'ecdsa-p384-sha384'];
            if (!isset($alg_map[$crv])) { return null; }
            return ['alg' => $alg_map[$crv], 'key' => $pem, 'kid' => $jwk['kid'] ?? null];
        }

        return null;
    }

    public static function b64url_decode($input) {
        if (!is_string($input) || $input === '') { return null; }
        $padded = strtr($input, '-_', '+/');
        $rem = strlen($padded) % 4;
        if ($rem) { $padded .= str_repeat('=', 4 - $rem); }
        $result = base64_decode($padded, true);
        return $result === false ? null : $result;
    }

    private static function rsaJwkToPem($n, $e) {
        $nDer = self::asn1Integer($n);
        $eDer = self::asn1Integer($e);
        $rsaPubKey = self::asn1Sequence($nDer . $eDer);
        $bitString = "\x03" . self::asn1Length(strlen($rsaPubKey) + 1) . "\x00" . $rsaPubKey;
        $algId = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";
        $spki = self::asn1Sequence($algId . $bitString);
        return "-----BEGIN PUBLIC KEY-----\n" .
               chunk_split(base64_encode($spki), 64) .
               "-----END PUBLIC KEY-----\n";
    }

    private static function ecJwkToPem($crv, $x, $y) {
        $curveSize = ['P-256' => 32, 'P-384' => 48];
        if (!isset($curveSize[$crv])) { return null; }
        $sz = $curveSize[$crv];
        $x = str_pad($x, $sz, "\x00", STR_PAD_LEFT);
        $y = str_pad($y, $sz, "\x00", STR_PAD_LEFT);
        $oidMap = [
            'P-256' => "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07",
            'P-384' => "\x06\x05\x2b\x81\x04\x00\x22",
        ];
        $point = "\x04" . $x . $y;
        $bitString = "\x03" . self::asn1Length(strlen($point) + 1) . "\x00" . $point;
        $ecPubOid = "\x06\x07\x2a\x86\x48\xce\x3d\x02\x01";
        $algId = self::asn1Sequence($ecPubOid . $oidMap[$crv]);
        $spki = self::asn1Sequence($algId . $bitString);
        return "-----BEGIN PUBLIC KEY-----\n" .
               chunk_split(base64_encode($spki), 64) .
               "-----END PUBLIC KEY-----\n";
    }

    private static function asn1Integer($bytes) {
        $bytes = ltrim($bytes, "\x00");
        if ($bytes === '') { $bytes = "\x00"; }
        if (ord($bytes[0]) & 0x80) { $bytes = "\x00" . $bytes; }
        return "\x02" . self::asn1Length(strlen($bytes)) . $bytes;
    }

    private static function asn1Sequence($bytes) {
        return "\x30" . self::asn1Length(strlen($bytes)) . $bytes;
    }

    private static function asn1Length($length) {
        if ($length < 128) { return chr($length); }
        $bytes = '';
        while ($length > 0) {
            $bytes = chr($length & 0xff) . $bytes;
            $length >>= 8;
        }
        return chr(0x80 | strlen($bytes)) . $bytes;
    }
}
