<?php
namespace AgentPay\HttpSig;

class Verifier {

    private $keyResolver;
    public $clockSkew = 30;
    public $maxAge = 300;
    public $requiredTag = null;
    public $requiredComponents = [];

    public function __construct(KeyResolver $keyResolver) {
        $this->keyResolver = $keyResolver;
    }

    public function verify(array $message) {
        $headers = $message['headers'] ?? [];
        $sigInputRaw = $this->getHeader($headers, 'signature-input');
        $sigRaw      = $this->getHeader($headers, 'signature');
        if (!$sigInputRaw || !$sigRaw) {
            return ['ok' => false, 'error' => 'missing_headers'];
        }

        try {
            $sigInputs = StructuredFields::parseSignatureInput($sigInputRaw);
            $sigs      = StructuredFields::parseSignature($sigRaw);
        } catch (\Exception $e) {
            return ['ok' => false, 'error' => 'parse_error', 'detail' => $e->getMessage()];
        }

        $lastError = 'no_matching_signature';

        foreach ($sigInputs as $label => $sigInput) {
            if (!isset($sigs[$label])) { continue; }

            $check = $this->verifyOne($label, $sigInput, $sigs[$label]['value'], $message);
            if ($check['ok']) { return $check; }
            $lastError = $check['error'];
        }

        return ['ok' => false, 'error' => $lastError];
    }

    private function verifyOne($label, array $sigInput, $sigBytes, array $message) {
        $params = $sigInput['params'];
        $components = $sigInput['components'];

        if ($this->requiredTag !== null) {
            if (($params['tag'] ?? null) !== $this->requiredTag) {
                return ['ok' => false, 'error' => 'tag_mismatch'];
            }
        }

        foreach ($this->requiredComponents as $required) {
            $found = false;
            foreach ($components as $c) {
                if ($c['name'] === $required) { $found = true; break; }
            }
            if (!$found) {
                return ['ok' => false, 'error' => 'missing_component:' . $required];
            }
        }

        if (isset($params['expires'])) {
            if (!is_int($params['expires'])) {
                return ['ok' => false, 'error' => 'invalid_expires'];
            }
            if ($params['expires'] < time() - $this->clockSkew) {
                return ['ok' => false, 'error' => 'expired'];
            }
        }
        if (isset($params['created'])) {
            if (!is_int($params['created'])) {
                return ['ok' => false, 'error' => 'invalid_created'];
            }
            if ($params['created'] > time() + $this->clockSkew) {
                return ['ok' => false, 'error' => 'future_created'];
            }
            if (time() - $params['created'] > $this->maxAge + $this->clockSkew) {
                return ['ok' => false, 'error' => 'too_old'];
            }
        }

        $keyid = $params['keyid'] ?? null;
        if (!$keyid) {
            return ['ok' => false, 'error' => 'missing_keyid'];
        }

        $key = $this->keyResolver->resolve($keyid);
        if (!$key) {
            return ['ok' => false, 'error' => 'key_not_found'];
        }

        $alg = $params['alg'] ?? $key['alg'];
        if ($alg !== $key['alg']) {
            return ['ok' => false, 'error' => 'algorithm_mismatch'];
        }

        try {
            $base = (new SignatureBase($message))->build(
                $components, $params, $sigInput['raw'] ?? null
            );
        } catch (\Exception $e) {
            return ['ok' => false, 'error' => 'base_build_failed', 'detail' => $e->getMessage()];
        }

        if (!$this->verifySignature($alg, $base, $sigBytes, $key['key'])) {
            return ['ok' => false, 'error' => 'invalid_signature', 'base' => $base];
        }

        return [
            'ok'         => true,
            'label'      => $label,
            'keyid'      => $keyid,
            'alg'        => $alg,
            'params'     => $params,
            'components' => array_map(function ($c) { return $c['name']; }, $components),
            'operator'   => $key['operator'] ?? null,
            'tag'        => $params['tag'] ?? null,
        ];
    }

    private function verifySignature($alg, $base, $sig, $key) {
        switch ($alg) {
            case 'ed25519':
                if (!function_exists('sodium_crypto_sign_verify_detached')) { return false; }
                if (strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES) { return false; }
                try {
                    return sodium_crypto_sign_verify_detached($sig, $base, $key);
                } catch (\Exception $e) { return false; }

            case 'rsa-v1_5-sha256':
                return openssl_verify($base, $sig, $key, OPENSSL_ALGO_SHA256) === 1;

            case 'rsa-pss-sha512':
                return self::verifyRsaPss($base, $sig, $key, 'sha512');

            case 'ecdsa-p256-sha256':
                $derSig = self::ecdsaRawToDer($sig, 32);
                if ($derSig === null) { return false; }
                return openssl_verify($base, $derSig, $key, OPENSSL_ALGO_SHA256) === 1;

            case 'ecdsa-p384-sha384':
                $derSig = self::ecdsaRawToDer($sig, 48);
                if ($derSig === null) { return false; }
                return openssl_verify($base, $derSig, $key, OPENSSL_ALGO_SHA384) === 1;

            case 'hmac-sha256':
                $mac = hash_hmac('sha256', $base, $key, true);
                return hash_equals($mac, $sig);
        }
        return false;
    }

    private static function ecdsaRawToDer($raw, $sz) {
        if (strlen($raw) !== $sz * 2) { return null; }
        $r = ltrim(substr($raw, 0, $sz), "\x00");
        $s = ltrim(substr($raw, $sz), "\x00");
        if ($r === '') { $r = "\x00"; }
        if ($s === '') { $s = "\x00"; }
        if (ord($r[0]) & 0x80) { $r = "\x00" . $r; }
        if (ord($s[0]) & 0x80) { $s = "\x00" . $s; }
        $rDer = "\x02" . chr(strlen($r)) . $r;
        $sDer = "\x02" . chr(strlen($s)) . $s;
        $seq = $rDer . $sDer;
        if (strlen($seq) < 128) {
            return "\x30" . chr(strlen($seq)) . $seq;
        }
        return null;
    }

    private static function verifyRsaPss($data, $sig, $pem, $hash) {
        $pubkey = openssl_pkey_get_public($pem);
        if (!$pubkey) { return false; }
        $details = openssl_pkey_get_details($pubkey);
        if (!$details || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA) { return false; }
        $modBits = $details['bits'];
        $emLen = (int) ceil(($modBits - 1) / 8);

        $em = '';
        $ok = openssl_public_decrypt($sig, $em, $pubkey, OPENSSL_NO_PADDING);
        if (!$ok) { return false; }
        if (strlen($em) < $emLen) { $em = str_pad($em, $emLen, "\x00", STR_PAD_LEFT); }
        return self::pssVerify($data, $em, $modBits, $hash);
    }

    private static function pssVerify($data, $em, $modBits, $hash) {
        $hLen = ($hash === 'sha512') ? 64 : 32;
        $sLen = $hLen;
        $emLen = (int) ceil(($modBits - 1) / 8);
        if (strlen($em) !== $emLen) { return false; }
        if ($em[strlen($em) - 1] !== "\xbc") { return false; }
        $maskedDB = substr($em, 0, $emLen - $hLen - 1);
        $h        = substr($em, $emLen - $hLen - 1, $hLen);
        $leftBits = 8 * $emLen - ($modBits - 1);
        if ($leftBits > 0) {
            $firstByte = ord($maskedDB[0]);
            $mask = (1 << (8 - $leftBits)) - 1;
            if (($firstByte & ~$mask) !== 0) { return false; }
        }
        $dbMask = self::mgf1($h, $emLen - $hLen - 1, $hash);
        $db = $maskedDB ^ $dbMask;
        if ($leftBits > 0) {
            $mask = (1 << (8 - $leftBits)) - 1;
            $db[0] = chr(ord($db[0]) & $mask);
        }
        $psLen = $emLen - $hLen - $sLen - 2;
        for ($i = 0; $i < $psLen; $i++) {
            if ($db[$i] !== "\x00") { return false; }
        }
        if ($db[$psLen] !== "\x01") { return false; }
        $salt = substr($db, $psLen + 1, $sLen);
        $mHash = hash($hash, $data, true);
        $mPrime = "\x00\x00\x00\x00\x00\x00\x00\x00" . $mHash . $salt;
        $hPrime = hash($hash, $mPrime, true);
        return hash_equals($h, $hPrime);
    }

    private static function mgf1($seed, $maskLen, $hash) {
        $hLen = ($hash === 'sha512') ? 64 : 32;
        $out = '';
        $counter = 0;
        while (strlen($out) < $maskLen) {
            $C = pack('N', $counter);
            $out .= hash($hash, $seed . $C, true);
            $counter++;
        }
        return substr($out, 0, $maskLen);
    }

    private function getHeader(array $headers, $name) {
        $lowerName = strtolower($name);
        $values = [];
        foreach ($headers as $hname => $hvalue) {
            if (strtolower($hname) === $lowerName) {
                if (is_array($hvalue)) {
                    foreach ($hvalue as $v) { $values[] = trim($v); }
                } else {
                    $values[] = trim($hvalue);
                }
            }
        }
        if (empty($values)) { return null; }
        return implode(', ', $values);
    }
}
