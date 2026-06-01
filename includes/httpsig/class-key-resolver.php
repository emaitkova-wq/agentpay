<?php
namespace AgentPay\HttpSig;

class KeyResolver {

    private $directoryProvider;
    private $cache = [];

    public function __construct(callable $directoryProvider) {
        $this->directoryProvider = $directoryProvider;
    }

    public function resolve($keyid) {
        if (isset($this->cache[$keyid])) {
            return $this->cache[$keyid];
        }
        $keys = call_user_func($this->directoryProvider, $keyid);
        if (!is_array($keys)) { return null; }
        foreach ($keys as $entry) {
            if (!is_array($entry)) { continue; }
            $jwk = $entry['jwk'] ?? $entry;
            if (($jwk['kid'] ?? null) !== $keyid) { continue; }
            $parsed = Jwk::parse($jwk);
            if (!$parsed) { continue; }
            if (!empty($entry['operator'])) {
                $parsed['operator'] = $entry['operator'];
            }
            $this->cache[$keyid] = $parsed;
            return $parsed;
        }
        $this->cache[$keyid] = null;
        return null;
    }
}
