<?php
namespace ClearWallet\HttpSig;

class SignatureBase {

    private $message;

    public function __construct(array $message) {
        $this->message = $message;
    }

    public function build(array $components, array $params, $raw_params = null) {
        $lines = [];
        $seen = [];

        foreach ($components as $c) {
            $key = $c['name'] . '|' . self::paramsHash($c['params']);
            if (isset($seen[$key])) {
                throw new \RuntimeException(esc_html("Duplicate component: {$c['name']}"));
            }
            $seen[$key] = true;

            $line = $this->renderComponent($c);
            if ($line === null) {
                throw new \RuntimeException(esc_html("Cannot resolve component: {$c['name']}"));
            }
            $lines[] = $line;
        }

        if ($raw_params !== null) {
            $lines[] = '"@signature-params": ' . $raw_params;
        } else {
            $lines[] = '"@signature-params": ' . StructuredFields::serializeInnerList($components, $params);
        }

        return implode("\n", $lines);
    }

    private function renderComponent(array $c) {
        $name = $c['name'];
        $params = $c['params'];

        if (substr($name, 0, 1) === '@') {
            $value = $this->getDerived($name, $params);
        } else {
            $value = $this->getField($name, $params);
        }
        if ($value === null) { return null; }

        $identifier = '"' . $name . '"';
        foreach ($params as $k => $v) {
            $identifier .= ';' . StructuredFields::serializeParam($k, $v);
        }
        return $identifier . ': ' . $value;
    }

    private function getDerived($name, array $params) {
        switch ($name) {
            case '@method':
                return strtoupper($this->message['method'] ?? '');

            case '@target-uri':
                $scheme = strtolower($this->message['scheme'] ?? 'https');
                $auth   = strtolower($this->message['authority'] ?? '');
                $path   = $this->message['path'] ?? '';
                $query  = $this->message['query'] ?? '';
                $uri = $scheme . '://' . $auth . $path;
                if ($query !== '' && $query !== null) { $uri .= '?' . $query; }
                return $uri;

            case '@authority':
                return strtolower($this->message['authority'] ?? '');

            case '@scheme':
                return strtolower($this->message['scheme'] ?? 'https');

            case '@request-target':
                $path  = $this->message['path'] ?? '';
                $query = $this->message['query'] ?? '';
                $rt = $path;
                if ($query !== '' && $query !== null) { $rt .= '?' . $query; }
                return $rt;

            case '@path':
                return $this->message['path'] ?? '';

            case '@query':
                $q = $this->message['query'] ?? '';
                return '?' . $q;

            case '@query-param':
                $pname = $params['name'] ?? null;
                if ($pname === null) { return null; }
                parse_str($this->message['query'] ?? '', $parsed);
                if (!array_key_exists($pname, $parsed)) { return null; }
                $val = $parsed[$pname];
                if (is_array($val)) { $val = (string) reset($val); }
                return rawurlencode((string) $val);

            case '@status':
                return (string) ($this->message['status'] ?? '');

            default:
                return null;
        }
    }

    private function getField($name, array $params) {
        $lower = strtolower($name);
        $headers = $this->message['headers'] ?? [];
        $values = [];

        foreach ($headers as $hname => $hvalue) {
            if (strtolower($hname) === $lower) {
                if (is_array($hvalue)) {
                    foreach ($hvalue as $v) { $values[] = $v; }
                } else {
                    $values[] = $hvalue;
                }
            }
        }

        if (empty($values)) {
            if (isset($params['req'])) { return null; }
            return null;
        }

        $canonical = array_map(function ($v) {
            $v = preg_replace('/\r?\n[ \t]+/', ' ', $v);
            return trim($v);
        }, $values);

        $combined = implode(', ', $canonical);

        if (isset($params['key'])) {
            try {
                $dict = StructuredFields::parseDictionary($combined);
            } catch (\Exception $e) {
                return null;
            }
            $k = (string) $params['key'];
            if (!isset($dict[$k])) { return null; }
            $entry = $dict[$k];
            $val = $entry['value'];
            if (is_array($val) && isset($val['inner_list'])) {
                $parts = [];
                foreach ($val['inner_list'] as $it) {
                    $piece = '"' . $it['name'] . '"';
                    foreach ($it['params'] as $pk => $pv) {
                        $piece .= ';' . StructuredFields::serializeParam($pk, $pv);
                    }
                    $parts[] = $piece;
                }
                $out = '(' . implode(' ', $parts) . ')';
            } elseif (is_string($val)) {
                $out = '"' . str_replace(['\\','"'], ['\\\\','\\"'], $val) . '"';
            } elseif (is_int($val) || is_float($val)) {
                $out = (string) $val;
            } elseif ($val === true) {
                $out = '?1';
            } elseif ($val === false) {
                $out = '?0';
            } else {
                $out = (string) $val;
            }
            foreach ($entry['params'] as $pk => $pv) {
                $out .= ';' . StructuredFields::serializeParam($pk, $pv);
            }
            return $out;
        }

        if (isset($params['bs'])) {
            $encoded = [];
            foreach ($canonical as $v) {
                $encoded[] = ':' . base64_encode($v) . ':';
            }
            return implode(', ', $encoded);
        }

        if (isset($params['sf'])) {
            return $combined;
        }

        return $combined;
    }

    private static function paramsHash(array $params) {
        ksort($params);
        return md5(serialize($params));
    }
}
