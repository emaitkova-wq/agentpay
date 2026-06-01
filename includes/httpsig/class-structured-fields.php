<?php
namespace ClearWallet\HttpSig;

class StructuredFields {

    public static function parseSignatureInput($input) {
        $result = [];
        $pos = 0;
        $len = strlen($input);
        self::skipSpaces($input, $pos);

        while ($pos < $len) {
            $label = self::parseKey($input, $pos);
            if ($label === null) {
                throw new \InvalidArgumentException(esc_html("Expected dictionary key at position {$pos}"));
            }
            if ($pos >= $len || $input[$pos] !== '=') {
                throw new \InvalidArgumentException(esc_html("Expected '=' at position {$pos}"));
            }
            $pos++;

            $value_start = $pos;
            if ($pos >= $len || $input[$pos] !== '(') {
                throw new \InvalidArgumentException(esc_html("Expected '(' for inner list at position {$pos}"));
            }
            $components = self::parseInnerListItems($input, $pos);
            $params = self::parseParameters($input, $pos);
            $value_end = $pos;

            $result[$label] = [
                'components' => $components,
                'params'     => $params,
                'raw'        => substr($input, $value_start, $value_end - $value_start),
            ];

            self::skipSpaces($input, $pos);
            if ($pos < $len && $input[$pos] === ',') {
                $pos++;
                self::skipSpaces($input, $pos);
            } elseif ($pos < $len) {
                throw new \InvalidArgumentException(esc_html("Unexpected character '{$input[$pos]}' at position {$pos}"));
            }
        }
        return $result;
    }

    public static function parseSignature($input) {
        $result = [];
        $pos = 0;
        $len = strlen($input);
        self::skipSpaces($input, $pos);

        while ($pos < $len) {
            $label = self::parseKey($input, $pos);
            if ($label === null) {
                throw new \InvalidArgumentException(esc_html("Expected dictionary key at position {$pos}"));
            }
            if ($pos >= $len || $input[$pos] !== '=') {
                throw new \InvalidArgumentException(esc_html("Expected '=' at position {$pos}"));
            }
            $pos++;
            if ($pos >= $len || $input[$pos] !== ':') {
                throw new \InvalidArgumentException(esc_html("Expected ':' for byte sequence at position {$pos}"));
            }
            $pos++;
            $end = strpos($input, ':', $pos);
            if ($end === false) {
                throw new \InvalidArgumentException(esc_html("Unterminated byte sequence"));
            }
            $b64 = substr($input, $pos, $end - $pos);
            $bytes = base64_decode($b64, true);
            if ($bytes === false) {
                throw new \InvalidArgumentException(esc_html("Invalid base64 in signature value"));
            }
            $pos = $end + 1;
            $params = self::parseParameters($input, $pos);

            $result[$label] = ['value' => $bytes, 'params' => $params];

            self::skipSpaces($input, $pos);
            if ($pos < $len && $input[$pos] === ',') {
                $pos++;
                self::skipSpaces($input, $pos);
            } elseif ($pos < $len) {
                throw new \InvalidArgumentException(esc_html("Unexpected character '{$input[$pos]}' at position {$pos}"));
            }
        }
        return $result;
    }

    public static function parseDictionary($input) {
        $result = [];
        $pos = 0;
        $len = strlen($input);
        self::skipSpaces($input, $pos);

        while ($pos < $len) {
            $key = self::parseKey($input, $pos);
            if ($key === null) {
                throw new \InvalidArgumentException(esc_html("Expected key at position {$pos}"));
            }
            $value = true;
            if ($pos < $len && $input[$pos] === '=') {
                $pos++;
                if ($pos < $len && $input[$pos] === '(') {
                    $items = self::parseInnerListItems($input, $pos);
                    $value = ['inner_list' => $items];
                } else {
                    $value = self::parseBareItem($input, $pos);
                }
            }
            $params = self::parseParameters($input, $pos);
            $result[$key] = ['value' => $value, 'params' => $params];
            self::skipSpaces($input, $pos);
            if ($pos < $len && $input[$pos] === ',') {
                $pos++;
                self::skipSpaces($input, $pos);
            }
        }
        return $result;
    }

    public static function serializeInnerList(array $components, array $params) {
        $parts = [];
        foreach ($components as $c) {
            $item = '"' . $c['name'] . '"';
            foreach ($c['params'] as $k => $v) {
                $item .= ';' . self::serializeParam($k, $v);
            }
            $parts[] = $item;
        }
        $out = '(' . implode(' ', $parts) . ')';
        foreach ($params as $k => $v) {
            $out .= ';' . self::serializeParam($k, $v);
        }
        return $out;
    }

    public static function serializeParam($key, $value) {
        if ($value === true)  { return $key; }
        if ($value === false) { return $key . '=?0'; }
        if (is_int($value))   { return $key . '=' . $value; }
        if (is_float($value)) { return $key . '=' . self::serializeDecimal($value); }
        if (is_string($value)) {
            $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
            return $key . '="' . $escaped . '"';
        }
        return $key . '=' . $value;
    }

    private static function serializeDecimal($f) {
        $s = number_format($f, 3, '.', '');
        return rtrim(rtrim($s, '0'), '.') ?: '0';
    }

    private static function parseKey($input, &$pos) {
        $len = strlen($input);
        $start = $pos;
        if ($pos < $len) {
            $c = $input[$pos];
            if (!(ctype_lower($c) || $c === '*')) {
                return null;
            }
            $pos++;
        }
        while ($pos < $len) {
            $c = $input[$pos];
            if (ctype_lower($c) || ctype_digit($c) ||
                $c === '_' || $c === '-' || $c === '.' || $c === '*') {
                $pos++;
            } else {
                break;
            }
        }
        if ($pos === $start) { return null; }
        return substr($input, $start, $pos - $start);
    }

    private static function parseInnerListItems($input, &$pos) {
        if ($input[$pos] !== '(') {
            throw new \InvalidArgumentException(esc_html("Expected '('"));
        }
        $pos++;
        $items = [];
        $len = strlen($input);
        self::skipSpaces($input, $pos);

        while ($pos < $len && $input[$pos] !== ')') {
            if ($input[$pos] !== '"') {
                throw new \InvalidArgumentException(esc_html("Expected quoted string at position {$pos}"));
            }
            $pos++;
            $name = '';
            while ($pos < $len && $input[$pos] !== '"') {
                if ($input[$pos] === '\\' && $pos + 1 < $len) {
                    $next = $input[$pos + 1];
                    if ($next === '"' || $next === '\\') {
                        $name .= $next;
                        $pos += 2;
                        continue;
                    }
                    throw new \InvalidArgumentException(esc_html("Invalid escape sequence"));
                }
                $name .= $input[$pos];
                $pos++;
            }
            if ($pos >= $len) {
                throw new \InvalidArgumentException(esc_html("Unterminated string"));
            }
            $pos++;
            $itemParams = self::parseParameters($input, $pos);
            $items[] = ['name' => $name, 'params' => $itemParams];
            self::skipSpaces($input, $pos);
        }
        if ($pos >= $len) {
            throw new \InvalidArgumentException(esc_html("Unterminated inner list"));
        }
        $pos++;
        return $items;
    }

    private static function parseParameters($input, &$pos) {
        $params = [];
        $len = strlen($input);
        self::skipSpaces($input, $pos);
        while ($pos < $len && $input[$pos] === ';') {
            $pos++;
            self::skipSpaces($input, $pos);
            $key = self::parseKey($input, $pos);
            if ($key === null) { break; }
            if ($pos < $len && $input[$pos] === '=') {
                $pos++;
                $value = self::parseBareItem($input, $pos);
            } else {
                $value = true;
            }
            $params[$key] = $value;
            self::skipSpaces($input, $pos);
        }
        return $params;
    }

    private static function parseBareItem($input, &$pos) {
        $len = strlen($input);
        if ($pos >= $len) { throw new \InvalidArgumentException(esc_html("Unexpected end")); }
        $c = $input[$pos];

        if ($c === '"') {
            $pos++;
            $val = '';
            while ($pos < $len && $input[$pos] !== '"') {
                if ($input[$pos] === '\\' && $pos + 1 < $len) {
                    $next = $input[$pos + 1];
                    if ($next === '"' || $next === '\\') {
                        $val .= $next;
                        $pos += 2;
                        continue;
                    }
                }
                $val .= $input[$pos];
                $pos++;
            }
            if ($pos >= $len) { throw new \InvalidArgumentException(esc_html("Unterminated string")); }
            $pos++;
            return $val;
        }
        if ($c === ':') {
            $pos++;
            $end = strpos($input, ':', $pos);
            if ($end === false) { throw new \InvalidArgumentException(esc_html("Unterminated byte sequence")); }
            $val = base64_decode(substr($input, $pos, $end - $pos), true);
            if ($val === false) { throw new \InvalidArgumentException(esc_html("Invalid base64")); }
            $pos = $end + 1;
            return $val;
        }
        if ($c === '?') {
            $pos++;
            if ($pos < $len && $input[$pos] === '0') { $pos++; return false; }
            if ($pos < $len && $input[$pos] === '1') { $pos++; return true; }
            throw new \InvalidArgumentException(esc_html("Invalid boolean"));
        }
        if (ctype_digit($c) || $c === '-') {
            $start = $pos;
            if ($c === '-') { $pos++; }
            while ($pos < $len && (ctype_digit($input[$pos]) || $input[$pos] === '.')) {
                $pos++;
            }
            $str = substr($input, $start, $pos - $start);
            return strpos($str, '.') !== false ? (float) $str : (int) $str;
        }
        if (ctype_alpha($c) || $c === '*') {
            $start = $pos;
            $pos++;
            while ($pos < $len) {
                $cc = $input[$pos];
                if (ctype_alpha($cc) || ctype_digit($cc) ||
                    strpos("_-.:%*/!#$&+^`|~", $cc) !== false) {
                    $pos++;
                } else { break; }
            }
            return substr($input, $start, $pos - $start);
        }
        throw new \InvalidArgumentException(esc_html("Unexpected character '{$c}' at position {$pos}"));
    }

    private static function skipSpaces($input, &$pos) {
        $len = strlen($input);
        while ($pos < $len && ($input[$pos] === ' ' || $input[$pos] === "\t")) {
            $pos++;
        }
    }
}
