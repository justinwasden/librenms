<?php

namespace LibreNMS\Util;

use App\Models\Device;
use Illuminate\Support\Facades\Log;
use LibreNMS\Util\Normalizers\LegacyNormalizerAdapter;

/**
 * TransformRunner
 *
 * Runs payload transforms to map API responses to normalized arrays suitable for persistence.
 * Supports:
 * - Fully-qualified transform strings: "\\Namespace\\Class::method"
 * - Vendor normalizers via LegacyNormalizerAdapter (individual classes in LibreNMS\Util\Normalizers\)
 * - Generic field mapping via endpoint-provided "transform_map"
 */
class TransformRunner
{
    /**
     * Run a transform and return mapped rows.
     *
     * @param mixed  $transform Fully-qualified "Class::method" or short method name, or null
     * @param Device $device    The Device model
     * @param array  $payload   Raw payload from the endpoint (decoded JSON)
     * @param array  $endpoint  Endpoint definition (capability, method, path, transform, transform_map, headers, request_body)
     * @return array Mapped rows (flat or structured)
     */
    public static function run($transform, Device $device, array $payload, array $endpoint): array
    {
        // Case 1: Fully-qualified class method "Class::method"
        if (is_string($transform) && strpos($transform, '::') !== false) {
            [$class, $method] = explode('::', $transform, 2);
            if (class_exists($class) && method_exists($class, $method)) {
                try {
                    // Prefer new signature: ($device, $payload, $endpoint)
                    return call_user_func([$class, $method], $device, $payload, $endpoint);
                } catch (\ArgumentCountError $e) {
                    // Fallbacks for different signatures
                    try {
                        return call_user_func([$class, $method], $device, $payload);
                    } catch (\ArgumentCountError $e2) {
                        try {
                            return call_user_func([$class, $method], $payload);
                        } catch (\Throwable $e3) {
                            Log::warning("Transform FQCN {$transform} failed: " . $e3->getMessage());
                            return [];
                        }
                    }
                } catch (\TypeError $e) {
                    // Type errors - try payload-only signature
                    try {
                        return call_user_func([$class, $method], $payload);
                    } catch (\Throwable $e2) {
                        Log::warning("Transform FQCN {$transform} failed: " . $e2->getMessage());
                        return [];
                    }
                } catch (\Throwable $e) {
                    Log::warning("Transform FQCN {$transform} failed: " . $e->getMessage());
                    return [];
                }
            }
        }

        // Case 2: Short method names - Use new normalizer classes via adapter
        if (is_string($transform)) {
            try {
                return LegacyNormalizerAdapter::normalize($transform, $device, $payload);
            } catch (\Throwable $e) {
                Log::warning("Transform {$transform} failed: " . $e->getMessage());
                return [];
            }
        }

        // Case 3: Generic transform_map
        $map = $endpoint['transform_map'] ?? null;
        if (is_array($map)) {
            return self::applyMap($endpoint['capability'] ?? 'general', $payload, $map);
        }

        // Fallback: infer minimal mapping by capability
        return self::inferSimple($endpoint['capability'] ?? 'general', $payload);
    }

    private static function applyMap(string $capability, array $payload, array $map): array
    {
        $listPath = $map['list_path'] ?? null;
        $fields = $map['fields'] ?? [];

        $rows = self::extractListByPath($payload, $listPath);
        $out = [];

        foreach ($rows as $row) {
            $mappedRow = [];
            foreach ($fields as $src => $dst) {
                $mappedRow[$dst] = self::dotGet($row, $src);
            }

            switch ($capability) {
                case 'ports':
                    $mappedRow['ifPhysAddress'] = $mappedRow['ifPhysAddress'] ?? '';
                    $mappedRow['ifOperStatus'] = self::normalizeStatus($mappedRow['ifOperStatus'] ?? null);
                    $mappedRow['ifAdminStatus'] = self::normalizeStatus($mappedRow['ifAdminStatus'] ?? null);
                    break;
                case 'sensors':
                    $mappedRow['sensor_current'] = self::extractNumber($mappedRow['sensor_current'] ?? null);
                    break;
                default:
                    // no-op
            }

            $out[] = $mappedRow;
        }

        return $out;
    }

    private static function extractListByPath(array $payload, ?string $dotPath): array
    {
        if (!$dotPath) {
            return $payload;
        }

        $data = $payload;
        foreach (explode('.', $dotPath) as $segment) {
            if (is_array($data) && array_key_exists($segment, $data)) {
                $data = $data[$segment];
            } else {
                return [];
            }
        }

        return is_array($data) ? $data : [];
    }

    private static function dotGet(array $row, string $path)
    {
        $data = $row;
        foreach (explode('.', $path) as $segment) {
            if (is_array($data) && array_key_exists($segment, $data)) {
                $data = $data[$segment];
            } else {
                return null;
            }
        }

        return $data;
    }

    private static function normalizeStatus($val): string
    {
        if (is_string($val)) {
            $lower = strtolower($val);
            if (in_array($lower, ['up', 'down', 'lowerlayerdown'])) {
                return $lower;
            }
        }
        if (is_bool($val)) {
            return $val ? 'up' : 'down';
        }

        return 'unknown';
    }

    private static function extractNumber($mixed)
    {
        if (is_numeric($mixed)) {
            return $mixed + 0;
        }
        if (is_string($mixed)) {
            if (preg_match('/-?\d*\.?\d+/', $mixed, $m)) {
                return $m[0] + 0;
            }
        }

        return null;
    }

    private static function inferSimple(string $capability, array $payload): array
    {
        $rows = $payload;

        switch ($capability) {
            case 'ports':
                return array_values(array_filter($rows, function ($r) {
                    return is_array($r) && (isset($r['ifIndex']) || isset($r['ifName']));
                }));
            case 'inventory':
                return array_values(array_filter($rows, function ($r) {
                    return is_array($r) && (isset($r['entPhysicalIndex']) || isset($r['name']));
                }));
            case 'sensors':
                return array_values(array_filter($rows, function ($r) {
                    return is_array($r) && (isset($r['sensor_class']) || isset($r['sensor_index']));
                }));
            case 'processors':
            case 'mempools':
                return $rows;
            default:
                return [];
        }
    }

    public static function discoverProxmoxNodeName(array $clusterPayload): ?string
    {
        $list = $clusterPayload['data'] ?? $clusterPayload['resources'] ?? [];
        foreach ($list as $item) {
            if (($item['type'] ?? '') === 'node' && !empty($item['name'])) {
                return $item['name'];
            }
        }

        return null;
    }
}