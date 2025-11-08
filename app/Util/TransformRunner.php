<?php

namespace LibreNMS\Util;

use App\Models\Device;
use Illuminate\Support\Facades\Log;
use LibreNMS\Modules\Support\RestNormalizers;

/**
 * TransformRunner
 *
 * Runs payload transforms to map API responses to normalized arrays suitable for persistence.
 * Supports:
 * - Fully-qualified transform strings: "\\Namespace\\Class::method"
 * - Vendor normalizers in RestNormalizers via short method names
 * - Generic field mapping via endpoint-provided "transform_map"
 */
class TransformRunner
{
    /**
     * Run a transform and return mapped rows.
     *
     * @param mixed  $transform Fully-qualified "Class::method" or short method name in RestNormalizers, or null
     * @param Device $device    The Device model
     * @param array  $payload   Raw payload from the endpoint
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
                    // Prefer normalizers that accept ($device, $payload, $endpoint)
                    return $class::$method($device, $payload, $endpoint);
                } catch (\ArgumentCountError $e) {
                    // Fallbacks for RestNormalizers that expect different signatures
                    try {
                        return $class::$method($payload);
                    } catch (\ArgumentCountError $e2) {
                        try {
                            // Some rate normalizers take ($payload, pollIntervalSec)
                            return $class::$method($payload, 60);
                        } catch (\Throwable $e3) {
                            Log::warning("Transform FQCN {$transform} failed: " . $e3->getMessage());
                            return [];
                        }
                    } catch (\Throwable $e2t) {
                        Log::warning("Transform FQCN {$transform} failed: " . $e2t->getMessage());
                        return [];
                    }
                } catch (\Throwable $e) {
                    Log::warning("Transform FQCN {$transform} failed: " . $e->getMessage());
                    return [];
                }
            }
        }

        // Case 2: Short method names in RestNormalizers (legacy)
        if (is_string($transform) && method_exists(RestNormalizers::class, $transform)) {
            try {
                // Common signature: (array $payload)
                return RestNormalizers::{$transform}($payload);
            } catch (\ArgumentCountError $e) {
                try {
                    // Alternative signature: ($payload, pollIntervalSec)
                    return RestNormalizers::{$transform}($payload, 60);
                } catch (\ArgumentCountError $e2) {
                    try {
                        // Some may accept ($device, $payload, $endpoint)
                        return RestNormalizers::{$transform}($device, $payload, $endpoint);
                    } catch (\Throwable $e3) {
                        Log::warning("Transform {$transform} failed: " . $e3->getMessage());
                        return [];
                    }
                } catch (\Throwable $e2t) {
                    Log::warning("Transform {$transform} failed: " . $e2t->getMessage());
                    return [];
                }
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

            // Capability-specific tweaks
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
