<?php

namespace App\Services;

use App\Models\Device;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Caching layer for Device API responses
 * Caches infrequently changing data like hardware info, inventory, etc.
 */
class DeviceApiCache
{
    /**
     * Default cache TTLs (in seconds) for different endpoint types
     */
    const CACHE_TTL_DEVICE_INFO = 86400;     // 24 hours - hardware/serial rarely changes
    const CACHE_TTL_INVENTORY = 3600;        // 1 hour - inventory changes infrequently
    const CACHE_TTL_PORTS = 300;             // 5 minutes - ports can change but not often
    const CACHE_TTL_VLANS = 600;             // 10 minutes - VLANs don't change often
    const CACHE_TTL_VMINFO = 300;            // 5 minutes - VM state changes occasionally
    const CACHE_TTL_STORAGE = 300;           // 5 minutes - storage discovery fairly static
    const CACHE_TTL_CLUSTERS = 3600;         // 1 hour - cluster topology rarely changes
    const CACHE_TTL_HYPERVISOR_HOSTS = 3600; // 1 hour - hypervisor hosts rarely change

    // These capabilities should NEVER be cached (real-time metrics)
    const NO_CACHE_CAPABILITIES = [
        'processors',
        'mempools',
        'sensors',
        'ports_statistics',
        'port_statistics',
    ];

    /**
     * Get cached API response for an endpoint
     *
     * @param Device $device
     * @param string $path Endpoint path
     * @param string $capability Capability name
     * @return array|null Cached response or null if not cached/expired
     */
    public static function get(Device $device, string $path, string $capability): ?array
    {
        // Never cache real-time metrics
        if (in_array($capability, self::NO_CACHE_CAPABILITIES)) {
            return null;
        }

        $cacheKey = self::buildCacheKey($device, $path);

        if (Cache::has($cacheKey)) {
            Log::debug("DeviceApiCache: HIT for device {$device->device_id} path {$path}");
            return Cache::get($cacheKey);
        }

        Log::debug("DeviceApiCache: MISS for device {$device->device_id} path {$path}");
        return null;
    }

    /**
     * Store API response in cache
     *
     * @param Device $device
     * @param string $path Endpoint path
     * @param string $capability Capability name
     * @param array $response API response
     * @param int|null $ttl Custom TTL in seconds (uses default if null)
     */
    public static function put(Device $device, string $path, string $capability, array $response, ?int $ttl = null): void
    {
        // Never cache real-time metrics
        if (in_array($capability, self::NO_CACHE_CAPABILITIES)) {
            return;
        }

        // Determine TTL based on capability
        if ($ttl === null) {
            $ttl = self::getTtlForCapability($capability);
        }

        $cacheKey = self::buildCacheKey($device, $path);

        Cache::put($cacheKey, $response, $ttl);
        Log::debug("DeviceApiCache: STORED device {$device->device_id} path {$path} TTL {$ttl}s");
    }

    /**
     * Clear cache for a specific device
     *
     * @param Device $device
     */
    public static function clearDevice(Device $device): void
    {
        $prefix = "device_api:{$device->device_id}:";

        // Laravel Cache doesn't have a built-in prefix delete, so we track keys in a set
        $keysKey = "device_api_keys:{$device->device_id}";
        $keys = Cache::get($keysKey, []);

        foreach ($keys as $key) {
            Cache::forget($key);
        }

        Cache::forget($keysKey);
        Log::info("DeviceApiCache: Cleared cache for device {$device->device_id}");
    }

    /**
     * Clear cache for a specific endpoint
     *
     * @param Device $device
     * @param string $path
     */
    public static function clearEndpoint(Device $device, string $path): void
    {
        $cacheKey = self::buildCacheKey($device, $path);
        Cache::forget($cacheKey);
        Log::debug("DeviceApiCache: Cleared cache for device {$device->device_id} path {$path}");
    }

    /**
     * Build cache key
     *
     * @param Device $device
     * @param string $path
     * @return string
     */
    protected static function buildCacheKey(Device $device, string $path): string
    {
        // Use md5 of path to avoid key length issues
        $pathHash = md5($path);
        $key = "device_api:{$device->device_id}:{$pathHash}";

        // Track this key for device-level clearing
        $keysKey = "device_api_keys:{$device->device_id}";
        $keys = Cache::get($keysKey, []);
        if (!in_array($key, $keys)) {
            $keys[] = $key;
            Cache::put($keysKey, $keys, 86400 * 7); // Keep key list for a week
        }

        return $key;
    }

    /**
     * Get appropriate TTL for a capability
     *
     * @param string $capability
     * @return int TTL in seconds
     */
    protected static function getTtlForCapability(string $capability): int
    {
        return match($capability) {
            'device_info' => self::CACHE_TTL_DEVICE_INFO,
            'inventory' => self::CACHE_TTL_INVENTORY,
            'ports' => self::CACHE_TTL_PORTS,
            'vlans' => self::CACHE_TTL_VLANS,
            'vminfo', 'vm_info', 'discovery' => self::CACHE_TTL_VMINFO,
            'storage' => self::CACHE_TTL_STORAGE,
            'clusters' => self::CACHE_TTL_CLUSTERS,
            'hypervisor_hosts' => self::CACHE_TTL_HYPERVISOR_HOSTS,
            default => 300, // Default 5 minutes for unknown capabilities
        };
    }

    /**
     * Check if caching is enabled for a capability
     *
     * @param string $capability
     * @return bool
     */
    public static function shouldCache(string $capability): bool
    {
        return !in_array($capability, self::NO_CACHE_CAPABILITIES);
    }

    /**
     * Get cache statistics for a device
     *
     * @param Device $device
     * @return array
     */
    public static function getStats(Device $device): array
    {
        $keysKey = "device_api_keys:{$device->device_id}";
        $keys = Cache::get($keysKey, []);

        $stats = [
            'device_id' => $device->device_id,
            'total_keys' => count($keys),
            'cached_endpoints' => 0,
        ];

        foreach ($keys as $key) {
            if (Cache::has($key)) {
                $stats['cached_endpoints']++;
            }
        }

        return $stats;
    }
}
