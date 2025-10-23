<?php
namespace App\\Services\\RestApi;

class JsonPathHelper
{
    /**
     * Lightweight JSONPath extractor supporting:
     * - $.items[*].field
     * - $.results[*].field
     * - $.data[*].field
     * - $.field.subfield
     * - Hyphenated keys via bracket notation: $.['field-name'] or $['field-name']
     */
    public static function extract(array $data, string $path)
    {
        // Normalize starting '$.'
        $p = trim($path);
        if (strpos($p, '$') === 0) { $p = substr($p, 1); }
        if (strpos($p, '.') === 0) { $p = substr($p, 1); }

        // Support bracketed keys
        $segments = [];
        $buf = '';
        $inBracket = false;
        $inQuote = false;
        $quoteChar = '';
        for ($i = 0; $i < strlen($p); $i++) {
            $ch = $p[$i];
            if ($inBracket) {
                $buf .= $ch;
                if ($inQuote) {
                    if ($ch === $quoteChar) { $inQuote = false; }
                } else {
                    if ($ch === '"' || $ch === "'") { $inQuote = true; $quoteChar = $ch; }
                    if ($ch === ']') { $inBracket = false; }
                }
            } else {
                if ($ch === '[') { $inBracket = true; $buf .= $ch; }
                elseif ($ch === '.') { $segments[] = $buf; $buf = ''; }
                else { $buf .= $ch; }
            }
        }
        if ($buf !== '') { $segments[] = $buf; }

        $current = $data;

        foreach ($segments as $seg) {
            // Wildcard arrays like items[*]
            if (preg_match('/^([\\w-]+)\\[\\*\\]$/', $seg, $m)) {
                $key = $m[1];
                if (!is_array($current) || !isset($current[$key]) || !is_array($current[$key])) {
                    return null;
                }
                $results = [];
                foreach ($current[$key] as $item) {
                    $results[] = $item;
                }
                return $results; // Return array of items for group processing
            }

            // Bracketed key e.g., ['field-name']
            if (preg_match('/^\\[\\s*[\'\\"]([^\'\\"]+)[\'\\"]\\s*\\]$/', $seg, $m)) {
                $key = $m[1];
                if (!is_array($current) || !array_key_exists($key, $current)) {
                    return null;
                }
                $current = $current[$key];
                continue;
            }

            // Key with index like items[0]
            if (preg_match('/^([\\w-]+)\\[(\\d+)\\]$/', $seg, $m)) {
                $key = $m[1]; $idx = (int)$m[2];
                if (!is_array($current) || !isset($current[$key][$idx])) {
                    return null;
                }
                $current = $current[$key][$idx];
                continue;
            }

            // Plain key
            $key = $seg;
            if (!is_array($current) || !array_key_exists($key, $current)) {
                return null;
            }
            $current = $current[$key];
        }

        return $current;
    }
}