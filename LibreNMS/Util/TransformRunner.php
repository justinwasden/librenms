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
 * - Vendor-specific normalizers (existing in RestNormalizers)
 * - Generic field mapping via endpoint-provided "transform_map"
 */
class TransformRunner
{
    /**
     * @param Device $device
     * @param array $template The loaded template array
     * @param array $endpoint The endpoint definition (capability, method, path, transform, transform_map, headers, request_body)
     * @param mixed $payload The raw payload fetched from the endpoint
     * @return array Mapped rows
     */
    public static function run(Device $device, array $template, array $endpoint, $payload): array
    {
        $transformKey = $endpoint['transform'] ?? null;

        // Case 1: vendor normalizer function exists
        if (is_string($transformKey) && method_exists(RestNormalizers::class, $transformKey)) {
            // Some normalizers require additional arguments, adapt as needed
            try {
                return RestNormalizers::$transformKey($payload);
            } catch (\ArgumentCountError $e) {
                // Try alternative signatures that are common in your codebase
                try {
                    return RestNormalizers::$transformKey($payload, 60); // e.g. poll interval
                } catch (\Throwable $ignored) {
                    Log::warning("Transform {$transformKey} failed: {$ignored->getMessage()}");
                }
            } catch (\Throwable $e) {
                Log::warning("Transform {$transformKey} failed: {$e->getMessage()}");
            }

            return [];
        }

        // Case 2: generic mapping via transform_map
        // Example transform_map:
        // {
        //   "list_path": "response.items",       // dot path where list resides
        //   "capability": "ports",
        //   "fields": {                          // map source->destination
        //     "id": "ifIndex",
        //     "name": "ifName",
        //     "description": "ifDescr",
        //     "type": "ifType",
        //     "speed_bps": "ifSpeed",
        //     "admin": "ifAdminStatus",
        //     "oper": "ifOperStatus",
        //     "mtu": "ifMtu",
        //     "mac": "ifPhysAddress",
        //     "alias": "ifAlias"
        //   }
        // }
        $map = $endpoint['transform_map'] ?? null;
        if (is_array($map)) {
            $capability = $map['capability'] ?? ($endpoint['capability'] ?? 'general');
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
                        // Normalize MAC, status, etc. as needed
                        $mappedRow['ifPhysAddress'] = $mappedRow['ifPhysAddress'] ?? '';
                        $mappedRow['ifOperStatus'] = self::normalizeStatus($mappedRow['ifOperStatus'] ?? null);
                        $mappedRow['ifAdminStatus'] = self::normalizeStatus($mappedRow['ifAdminStatus'] ?? null);
                        break;
                    case 'sensors':
                        // Ensure sensor_current numeric, limits optional
                        $mappedRow['sensor_current'] = self::extractNumber($mappedRow['sensor_current'] ?? null);
                        break;
                    default:
                        // no-op
                }

                $out[] = $mappedRow;
            }

            return $out;
        }

        // Unknown transform and no map: try to infer by capability in simple cases
        $cap = $endpoint['capability'] ?? 'general';
        return self::inferSimple($cap, $payload);
    }

    /**
     * Extract list from payload by dot path (e.g., "response.items").
     */
    private static function extractListByPath($payload, ?string $dotPath): array
    {
        if (!$dotPath) {
            return is_array($payload) ? $payload : [];
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

    /**
     * Dot get from array.
     */
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

    /**
     * Try to infer minimal mapping by capability if payload is already close to target.
     */
    private static function inferSimple(string $capability, $payload): array
    {
        $rows = is_array($payload) ? $payload : [];

        switch ($capability) {
            case 'ports':
                // if payload already has ifIndex/ifName keys, pass through
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

    /**
     * Helper to discover proxmox node name from /cluster/resources payload.
     */
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