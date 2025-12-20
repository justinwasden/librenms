<?php

namespace LibreNMS\Util\Normalizers\Fortinet;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * Fortinet - DeviceInfo Normalizer
 *
 * Capability: device_info
 * Vendor: fortigate
 */
class DeviceInfo extends BaseNormalizer
{
    protected string $capability = 'device_info';
    protected string $vendor = 'fortigate';

    protected function doNormalize(Device $device, array $payload): array
    {
$deviceInfo = [];
        $results = $payload['results'] ?? $payload;

        if (empty($results) && empty($payload)) {
            return $deviceInfo;
        }

        // Hardware/Model - Format as "FortiGate <model>"
        // Try model_name + model_number first (more descriptive), then fall back to model
        if (isset($results['model_name']) && isset($results['model_number'])) {
            $deviceInfo['hardware'] = $results['model_name'] . ' ' . $results['model_number'];
        } elseif (isset($results['model'])) {
            $model = $results['model'];
            // If model doesn't start with "FortiGate", prepend it
            if (stripos($model, 'FortiGate') !== 0 && stripos($model, 'FG') === 0) {
                $deviceInfo['hardware'] = 'FortiGate ' . $model;
            } else {
                $deviceInfo['hardware'] = $model;
            }
        }

        // Version - Combine version and build information
        // Check top-level payload first (FortiGate puts these there), then check results
        $version = $payload['version'] ?? $results['version'] ?? null;
        $build = $payload['build'] ?? $results['build'] ?? null;
        $patch = $payload['patch'] ?? $results['patch'] ?? null;

        if ($version) {
            $versionStr = $version;
            // Add build number if available
            if ($build) {
                $versionStr .= ',build' . $build;
            }
            // Add patch level if available
            if ($patch) {
                $versionStr .= ',patch' . $patch;
            }
            $deviceInfo['version'] = $versionStr;
        }

        // Serial number - Check top-level first
        $serial = $payload['serial'] ?? $results['serial'] ?? null;
        if ($serial) {
            $deviceInfo['serial'] = $serial;
        }

        // System Object ID - Build complete OID from model if available
        // Base Fortinet OID: .1.3.6.1.4.1.12356.101.1
        if (isset($results['model_id'])) {
            // Use model_id if provided by API
            $deviceInfo['sysObjectID'] = '.1.3.6.1.4.1.12356.101.1.' . $results['model_id'];
        } elseif (isset($results['model'])) {
            // Try to extract numeric model ID from model name (e.g., "901G" -> 9002)
            $model = $results['model'];
            if (preg_match('/(\d+)[A-Z]?/', $model, $matches)) {
                $modelNum = $matches[1];
                // Rough mapping: model number * 10 + 2 (e.g., 900 -> 9002)
                $modelId = ($modelNum * 10) + 2;
                $deviceInfo['sysObjectID'] = '.1.3.6.1.4.1.12356.101.1.' . $modelId;
            } else {
                // Fallback to base Fortinet OID
                $deviceInfo['sysObjectID'] = '.1.3.6.1.4.1.12356';
            }
        } else {
            $deviceInfo['sysObjectID'] = '.1.3.6.1.4.1.12356';
        }

        // System Name - Use hostname from API
        $hostname = $results['hostname'] ?? null;
        if ($hostname && $hostname !== 'FortiGate') {
            $deviceInfo['sysName'] = $hostname;
        }

        // System Description - Build from hostname, model, and version
        $model = $results['model'] ?? ($results['model_name'] ?? '') . ' ' . ($results['model_number'] ?? '');
        $model = trim($model);

        $sysDescr = 'Fortinet';
        if ($hostname && $hostname !== 'FortiGate') {
            $sysDescr = $hostname;
        }
        if ($model) {
            $sysDescr .= ' ' . $model;
        }
        if (isset($deviceInfo['version'])) {
            $sysDescr .= ' ' . $deviceInfo['version'];
        }
        $deviceInfo['sysDescr'] = trim($sysDescr);

        // System Contact (if available) - check both payload and results
        $contact = $payload['contact'] ?? $results['contact'] ?? null;
        if ($contact) {
            $deviceInfo['sysContact'] = $contact;
        }

        // Uptime (FortiGate provides uptime in seconds) - check both payload and results
        $uptime = $payload['uptime'] ?? $results['uptime'] ?? null;
        if ($uptime !== null) {
            $deviceInfo['uptime'] = (int) $uptime;
        }

        // Location (if available)
        if (isset($results['location'])) {
            $deviceInfo['location'] = $results['location'];
        }

        return $deviceInfo;
    }
}
