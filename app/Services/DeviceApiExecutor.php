<?php

namespace App\Services;

use App\Models\Device;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use LibreNMS\Util\ApiTemplateManager;
use LibreNMS\Util\EndpointPathResolver;
use LibreNMS\Util\TransformRunner;

/**
 * Executes Device API templates against a device.
 * Features:
 * - Groups endpoints by method+path: single fetch, fan-out to multiple capabilities
 * - Caches inventory endpoints (TTL 15m)
 * - Proxmox {node} fallback: discovers node and persists proxmox_node on 404
 * - Merges template endpoints with per-device custom endpoints (rest_endpoints)
 * - Runs vendor normalizers or generic transform_map and persists by capability
 */
class DeviceApiExecutor
{
    /**
     * Execute all endpoints for a template against a device.
     *
     * @param Device $device
     * @param string $templateKey
     * @param object $client Must implement get(path, query=[]) and post(path, body=[]) -> array
     */
    public function run(Device $device, string $templateKey, $client): void
    {
        $tpl = ApiTemplateManager::loadTemplate($templateKey);
        if (!$tpl) {
            throw new \RuntimeException("Template $templateKey not found or disabled");
        }

        // Collect enabled endpoints from template
        $tplEndpoints = [];
        foreach ($tpl['endpoints'] ?? [] as $ep) {
            if (!empty($ep['enabled'])) {
                $tplEndpoints[] = $ep;
            }
        }

        // Merge with per-device custom endpoints (stored in device attrib rest_endpoints)
        $customEndpoints = [];
        try {
            $epJson = (string) $device->getAttrib('rest_endpoints', '[]');
            $parsed = json_decode($epJson, true);
            if (is_array($parsed)) {
                foreach ($parsed as $entry) {
                    // Expect structure: name, path, method, category, poll_interval, enabled, headers?, request_body?, transform?, transform_map?
                    if (!empty($entry['enabled']) && !empty($entry['path'])) {
                        $customEndpoints[] = [
                            'capability'   => $entry['category'] ?? 'general',
                            'method'       => $entry['method'] ?? 'GET',
                            'path'         => $entry['path'],
                            'transform'    => $entry['transform'] ?? null,
                            'transform_map'=> $entry['transform_map'] ?? null,
                            'headers'      => $entry['headers'] ?? [],
                            'request_body' => $entry['request_body'] ?? null,
                            'enabled'      => true,
                        ];
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning("Failed to parse rest_endpoints for device {$device->device_id}: {$e->getMessage()}");
        }

        // Final endpoint list
        $endpoints = array_merge($tplEndpoints, $customEndpoints);

        if (empty($endpoints)) {
            Log::info("No REST endpoints configured for device {$device->device_id}");
            return;
        }

        // Group endpoints by method+path to fetch once per unique path.
        $byPath = [];
        foreach ($endpoints as $ep) {
            $method = strtoupper($ep['method'] ?? 'GET');
            $key = $method . ' ' . $ep['path'];
            $byPath[$key][] = $ep;
        }

        foreach ($byPath as $key => $eps) {
            [$method, $rawPath] = explode(' ', $key, 2);
            $resolvedPath = EndpointPathResolver::resolve($device, $rawPath); // {hostname}, {sysname}, {node} placeholders resolved << EndpointPathResolver.php

            // Inventory endpoints get cached
            $capabilities = array_unique(array_map(fn($e) => $e['capability'], $eps));
            $cacheable = in_array('inventory', $capabilities, true);

            // Fetch closure (single request executed once per unique path)
            $fetch = function () use ($client, $method, $resolvedPath, $eps) {
                if ($method === 'POST') {
                    $body = $eps[0]['request_body'] ?? [];
                    return $client->post($resolvedPath, $body);
                }
                // For GET, per-endpoint headers can be used by the client; if client supports, pass headers/options from $eps[0]
                return $client->get($resolvedPath);
            };

            // Cache key
            $cacheKey = "rest:$templateKey:dev:{$device->device_id}:$method:$resolvedPath";

            try {
                $payload = $cacheable
                    ? Cache::remember($cacheKey, now()->addMinutes(15), $fetch)
                    : $fetch();

                // Fan-out to each endpoint (capability) at this path
                foreach ($eps as $ep) {
                    $capability = $ep['capability'] ?? 'general';

                    // Run transform or generic map
                    $mapped = TransformRunner::run($device, $tpl, $ep, $payload);

                    // Persist mapped records by capability
                    $this->persistByCapability($device, $capability, $mapped);
                }
            } catch (\Throwable $e) {
                // Handle common 404 proxmox node fallback
                $msg = $e->getMessage();
                if (str_contains($msg, '404') && str_contains($resolvedPath, '/nodes/{node}') && empty($device->getAttrib('proxmox_node'))) {
                    // discover proxmox node and persist
                    try {
                        $cluster = $client->get('/cluster/resources');
                        $node = TransformRunner::discoverProxmoxNodeName($cluster);
                        if ($node) {
                            $device->setAttrib('proxmox_node', $node);
                            // retry this batch once
                            $resolvedPath = EndpointPathResolver::resolve($device, $rawPath);
                            $payload = $fetch();
                            foreach ($eps as $ep) {
                                $mapped = TransformRunner::run($device, $tpl, $ep, $payload);
                                $this->persistByCapability($device, $ep['capability'] ?? 'general', $mapped);
                            }
                            continue;
                        }
                    } catch (\Throwable $ignored) {
                        // ignore fallback failure
                    }
                }

                Log::warning("REST fetch failed for device {$device->device_id} path {$resolvedPath}: {$e->getMessage()}");
            }
        }
    }

    /**
     * Persist mapped records by capability.
     *
     * Expected $mapped format (examples):
     * - ports:   array of associative arrays with keys [ifIndex, ifName, ifDescr, ifType, ifSpeed, ifOperStatus, ifAdminStatus, ifMtu, ifPhysAddress, ifAlias]
     * - sensors: array of associative arrays with keys [sensor_class, sensor_type, sensor_descr, sensor_index, sensor_current, sensor_limit, sensor_limit_low, entPhysicalIndex, entPhysicalIndex_measured, user_func]
     * - processors: array [processor_index, processor_type, processor_descr, processor_usage]
     * - mempools:  array [mempool_index, mempool_type, mempool_descr, mempool_used, mempool_free, mempool_total, mempool_perc]
     * - inventory: array of entPhysical-like rows (entPhysicalIndex, name, class, description, serial, etc.)
     */
    private function persistByCapability(Device $device, string $capability, array $mapped): void
    {
        if (empty($mapped)) {
            return;
        }

        switch ($capability) {
            case 'ports':
                \App\Services\DeviceApiPersistor::savePorts($device, $mapped);
                break;
            case 'sensors':
                \App\Services\DeviceApiPersistor::saveSensors($device, $mapped);
                break;
            case 'processors':
                \App\Services\DeviceApiPersistor::saveProcessors($device, $mapped);
                break;
            case 'mempools':
                \App\Services\DeviceApiPersistor::saveMempools($device, $mapped);
                break;
            case 'inventory':
                \App\Services\DeviceApiPersistor::saveInventory($device, $mapped);
                break;
            default:
                // general or unknown: log and ignore, or extend with custom capability persistor
                Log::debug("Unknown capability {$capability} for device {$device->device_id}, ignoring.");
        }
    }
}