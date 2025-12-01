<?php

namespace App\Services;

use App\Models\Device;
use App\Models\DeviceApiEndpoint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Manages device API endpoint enablement based on device version/capabilities
 */
class DeviceApiVersionManager
{
    /**
     * Update endpoint enablement for a device based on its version/capabilities
     * This is called during polling to dynamically enable/disable endpoints
     */
    public static function updateEndpointEnablement(Device $device): void
    {
        switch ($device->os) {
            case 'netapp':
                self::updateNetAppEndpoints($device);
                break;
            // Add other OS-specific handlers here
            default:
                // No version-specific endpoint management for this OS
                break;
        }
    }

    /**
     * Update NetApp endpoint enablement based on ONTAP version
     * NetApp ONTAP 9.11+ supports statistics field for processors/memory
     */
    protected static function updateNetAppEndpoints(Device $device): void
    {
        $version = self::parseNetAppVersion($device->version);

        if ($version === null) {
            Log::debug("DeviceApiVersionManager: Could not parse NetApp version from: {$device->version}");
            return;
        }

        Log::info("DeviceApiVersionManager: NetApp device {$device->device_id} version: {$version['major']}.{$version['minor']}");

        // Check if version is 9.11 or higher
        $supportsStatistics = ($version['major'] > 9) || ($version['major'] == 9 && $version['minor'] >= 11);

        // Get processor and mempool endpoints for this device
        $endpoints = DeviceApiEndpoint::where('device_id', $device->device_id)
            ->whereIn('capability', ['processors', 'mempools'])
            ->where('path', 'like', '%?fields=%statistics%')
            ->get();

        foreach ($endpoints as $endpoint) {
            $shouldBeEnabled = $supportsStatistics;

            if ($endpoint->enabled != $shouldBeEnabled) {
                $endpoint->enabled = $shouldBeEnabled;
                $endpoint->save();

                $status = $shouldBeEnabled ? 'enabled' : 'disabled';
                Log::info("DeviceApiVersionManager: {$status} NetApp endpoint '{$endpoint->path}' ({$endpoint->capability}) for device {$device->device_id} (ONTAP {$version['major']}.{$version['minor']})");
            }
        }

        // If version supports statistics and endpoints don't exist, we should create them
        if ($supportsStatistics && $endpoints->isEmpty()) {
            self::createNetAppStatisticsEndpoints($device);
        }
    }

    /**
     * Create NetApp processor/mempool endpoints if they don't exist
     */
    protected static function createNetAppStatisticsEndpoints(Device $device): void
    {
        // Get the device's API config to find template_id
        $config = $device->deviceApiConfig;
        if (!$config || !$config->template_id) {
            Log::warning("DeviceApiVersionManager: NetApp device {$device->device_id} has no API config/template");
            return;
        }

        // Get template endpoints that we should copy to device-specific overrides
        $templateEndpoints = DB::table('device_api_template_endpoints')
            ->where('template_id', $config->template_id)
            ->whereIn('capability', ['processors', 'mempools'])
            ->where('path', 'like', '%?fields=%statistics%')
            ->get();

        foreach ($templateEndpoints as $templateEndpoint) {
            // Check if device-specific override already exists
            $exists = DeviceApiEndpoint::where('device_id', $device->device_id)
                ->where('capability', $templateEndpoint->capability)
                ->where('path', $templateEndpoint->path)
                ->exists();

            if (!$exists) {
                $deviceEndpoint = new DeviceApiEndpoint();
                $deviceEndpoint->device_id = $device->device_id;
                $deviceEndpoint->template_endpoint_id = $templateEndpoint->id;
                $deviceEndpoint->capability = $templateEndpoint->capability;
                $deviceEndpoint->path = $templateEndpoint->path;
                $deviceEndpoint->method = $templateEndpoint->method;
                $deviceEndpoint->transform = $templateEndpoint->transform;
                $deviceEndpoint->enabled = true; // Enable since version supports it
                $deviceEndpoint->display_order = $templateEndpoint->display_order;
                $deviceEndpoint->save();

                Log::info("DeviceApiVersionManager: Created enabled NetApp endpoint '{$templateEndpoint->path}' ({$templateEndpoint->capability}) for device {$device->device_id}");
            }
        }
    }

    /**
     * Parse NetApp ONTAP version string
     * Examples:
     *   "NetApp Release 9.8P17: Fri Feb 24 15:11:05 UTC 2023"
     *   "NetApp Release 9.11.1P3: Mon Jan 15 10:30:00 UTC 2024"
     *   "9.12.1"
     *
     * @return array{major: int, minor: int, patch: int}|null
     */
    protected static function parseNetAppVersion(?string $version): ?array
    {
        if (!$version) {
            return null;
        }

        // Try to extract version number (e.g., "9.8P17" or "9.11.1")
        if (preg_match('/(\d+)\.(\d+)(?:\.(\d+))?(?:[P\-](\d+))?/', $version, $matches)) {
            return [
                'major' => (int) $matches[1],
                'minor' => (int) $matches[2],
                'patch' => isset($matches[3]) && $matches[3] !== '' ? (int) $matches[3] : 0,
                'build' => isset($matches[4]) && $matches[4] !== '' ? (int) $matches[4] : 0,
            ];
        }

        return null;
    }

    /**
     * Parse version string for any device type
     * This is a generic version parser that can be extended per-OS
     */
    public static function parseVersion(Device $device): ?array
    {
        switch ($device->os) {
            case 'netapp':
                return self::parseNetAppVersion($device->version);

            case 'purestorage':
                // Example: "Purity//FA 6.5.6"
                if (preg_match('/(\d+)\.(\d+)\.(\d+)/', $device->version, $matches)) {
                    return [
                        'major' => (int) $matches[1],
                        'minor' => (int) $matches[2],
                        'patch' => (int) $matches[3],
                    ];
                }
                return null;

            case 'velocloud':
                // Example: "4.2.1" or "4.5.1"
                if (preg_match('/(\d+)\.(\d+)\.(\d+)/', $device->version, $matches)) {
                    return [
                        'major' => (int) $matches[1],
                        'minor' => (int) $matches[2],
                        'patch' => (int) $matches[3],
                    ];
                }
                return null;

            case 'proxmox':
                // Example: "6.17.2-1-pve"
                if (preg_match('/(\d+)\.(\d+)\.(\d+)/', $device->version, $matches)) {
                    return [
                        'major' => (int) $matches[1],
                        'minor' => (int) $matches[2],
                        'patch' => (int) $matches[3],
                    ];
                }
                return null;

            default:
                // Generic version parsing
                if (preg_match('/(\d+)\.(\d+)(?:\.(\d+))?/', $device->version ?? '', $matches)) {
                    return [
                        'major' => (int) $matches[1],
                        'minor' => (int) $matches[2],
                        'patch' => isset($matches[3]) && $matches[3] !== '' ? (int) $matches[3] : 0,
                    ];
                }
                return null;
        }
    }

    /**
     * Compare two versions
     * Returns: -1 if v1 < v2, 0 if equal, 1 if v1 > v2
     */
    public static function compareVersions(array $v1, array $v2): int
    {
        if ($v1['major'] != $v2['major']) {
            return $v1['major'] < $v2['major'] ? -1 : 1;
        }
        if ($v1['minor'] != $v2['minor']) {
            return $v1['minor'] < $v2['minor'] ? -1 : 1;
        }
        $patch1 = $v1['patch'] ?? 0;
        $patch2 = $v2['patch'] ?? 0;
        if ($patch1 != $patch2) {
            return $patch1 < $patch2 ? -1 : 1;
        }
        return 0;
    }

    /**
     * Check if device version meets minimum requirement
     */
    public static function versionMeetsMinimum(Device $device, int $major, int $minor, int $patch = 0): bool
    {
        $version = self::parseVersion($device);
        if (!$version) {
            return false;
        }

        $minVersion = ['major' => $major, 'minor' => $minor, 'patch' => $patch];
        return self::compareVersions($version, $minVersion) >= 0;
    }
}
