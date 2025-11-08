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
		    // Load template metadata
		    $tpl = ApiTemplateManager::loadTemplate($templateKey);
		    if (!$tpl) {
		        throw new \RuntimeException("Template {$templateKey} not found or disabled");
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
		        // Try loading from database first
		        $dbEndpoints = \App\Models\DeviceApiEndpoint::where('device_id', $device->device_id)
		            ->enabled()
		            ->ordered()
		            ->get();

		        if ($dbEndpoints->isNotEmpty()) {
		            foreach ($dbEndpoints as $endpoint) {
		                $customEndpoints[] = [
		                    'capability'   => $endpoint->capability ?? 'general',
		                    'method'       => strtoupper($endpoint->method ?? 'GET'),
		                    'path'         => $endpoint->path,
		                    'transform'    => $endpoint->transform ?? null,
		                    'headers'      => $endpoint->headers ?? [],
		                    'request_body' => $endpoint->request_body ?? null,
		                    'enabled'      => true,
		                ];
		            }
		            \Log::debug("Loaded " . count($customEndpoints) . " enabled endpoints from database for device {$device->device_id}");
		            $epJson = null; // Skip legacy loading
		        } else {
		            // Fallback to legacy attrib storage
		            $epJson = (string) $device->getAttrib('rest_endpoints', '[]');
		        }

		        if ($epJson) {
		        $parsed = json_decode($epJson, true);
		        if (is_array($parsed)) {
		            foreach ($parsed as $entry) {
		                // Expect structure: name, path, method, category, poll_interval, enabled, headers?, request_body?, transform?, transform_map?
		                if (!empty($entry['enabled']) && !empty($entry['path'])) {
		                    $customEndpoints[] = [
		                        'capability'   => $entry['category'] ?? 'general',
		                        'method'       => strtoupper($entry['method'] ?? 'GET'),
		                        'path'         => $entry['path'],
		                        'transform'    => $entry['transform'] ?? null,
		                        'transform_map'=> $entry['transform_map'] ?? null,
		                        'headers'      => $entry['headers'] ?? [],
		                        'request_body' => $entry['request_body'] ?? null,
		                        'enabled'      => true,
		                    ];
		                }
		            }
		            \Log::debug("Loaded " . count($customEndpoints) . " enabled endpoints from legacy attrib for device {$device->device_id}");
		        }
		        }
		    } catch (\Throwable $e) {
		        \Log::warning("Failed to load endpoints for device {$device->device_id}: {$e->getMessage()}");
		    }

		    // Final endpoint list
		    $endpoints = array_merge($tplEndpoints, $customEndpoints);

		    if (empty($endpoints)) {
		        \Log::info("No REST endpoints configured for device {$device->device_id}");
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
		        $resolvedPath = \LibreNMS\Util\EndpointPathResolver::resolve($device, $rawPath); // resolve {hostname}, {sysname}, {node}, etc.

		        // Inventory endpoints get cached
		        $capabilities = array_unique(array_map(fn($e) => $e['capability'] ?? 'general', $eps));
		        $cacheable = in_array('inventory', $capabilities, true);

		        // Fetch closure (single request executed once per unique path)
		        $fetch = function () use ($client, $method, $resolvedPath, $eps) {
		            if ($method === 'POST') {
		                $body = $eps[0]['request_body'] ?? [];
		                return $client->post($resolvedPath, $body);
		            }
		            // GET - parse query parameters from path
		            $pathParts = parse_url($resolvedPath);
		            $path = $pathParts['path'] ?? $resolvedPath;
		            $queryParams = [];
		            if (isset($pathParts['query'])) {
		                parse_str($pathParts['query'], $queryParams);
		            }
		            \Log::debug("DeviceApiExecutor GET: path={$path}, queryParams=" . json_encode($queryParams));
		            return $client->get($path, $queryParams);
		        };

		        // Cache key
		        $cacheKey = "rest:{$templateKey}:dev:{$device->device_id}:{$method}:{$resolvedPath}";

		        try {
		            $payload = $cacheable
		                ? \Illuminate\Support\Facades\Cache::remember($cacheKey, now()->addMinutes(15), $fetch)
		                : $fetch();

		            // Fan-out: run each endpoint transform against the same payload
		            foreach ($eps as $ep) {
		                $capability = $ep['capability'] ?? 'general';
		                if ($capability === 'ports' && isset($payload['uuid'])) {
		                    // Special handling for single-port metric calls
		                    $mapped = \LibreNMS\Util\TransformRunner::run($ep['transform'], $device, $payload, $ep);
		                } else {
		                    $mapped = \LibreNMS\Util\TransformRunner::run($ep['transform'], $device, $payload, $ep);
		                }
		                $this->persistByCapability($device, $capability, $mapped);
		            }
		        } catch (\Throwable $e) {
		            // Proxmox {node} fallback on 404/failed fetch
		            $msg = $e->getMessage();
		            $needsNode = str_contains($resolvedPath, '/nodes/{node}');
		            $hasNode   = (string) $device->getAttrib('proxmox_node', '') !== '';

		            if (str_contains($msg, '404') && $needsNode && !$hasNode) {
		                try {
		                    // Discover nodes and pick best match
		                    $cluster = $client->get('/cluster/resources');
		                    $node = \LibreNMS\Util\TransformRunner::discoverProxmoxNodeName($cluster);
		                    if ($node) {
		                        $device->setAttrib('proxmox_node', $node);
		                        // Re-resolve path and retry once
		                        $resolvedPath = \LibreNMS\Util\EndpointPathResolver::resolve($device, $rawPath);
		                        $payload = $fetch();

		                        foreach ($eps as $ep) {
		                            $capability = $ep['capability'] ?? 'general';
		                            $mapped = \LibreNMS\Util\TransformRunner::run($ep['transform'], $device, $payload, $ep);
		                            $this->persistByCapability($device, $capability, $mapped);
		                        }
		                        continue;
		                    }
		                } catch (\Throwable $ignored) {
		                    // ignore fallback failure; proceed to log warning
		                }
		            }

		            \Log::warning("REST fetch failed for device {$device->device_id} path {$resolvedPath}: {$e->getMessage()}");
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
     * - ipv4_addresses: structured arrays under 'ipv4_addresses' when normalizer returns structured payloads
     */
    private function persistByCapability(Device $device, string $capability, array $mapped): void
    {
        switch ($capability) {
            case 'inventory':
                $this->persistInventory($device, $mapped);
                break;
            case 'ipv4':
                $this->persistIpv4($device, $mapped);
                break;
            case 'ports':
                $this->persistPorts($device, $mapped);
                break;
            case 'ports_stats':
                $this->persistPortsStatistics($device, $mapped);
                break;
            case 'sensors':
                $this->persistSensors($device, $mapped);
                break;
            case 'storage':
                $this->persistStorage($device, $mapped);
                break;
            case 'transceivers':
                $this->persistTransceivers($device, $mapped);
                break;
            default:
                // Log unhandled capability for debugging
                if (!empty($mapped)) {
                    \Illuminate\Support\Facades\Log::debug("Unhandled capability '$capability' with mapped data", [
                        'device_id' => $device->device_id,
                        'data_sample' => array_slice($mapped, 0, 1),
                    ]);
                }
                break;
        }
    }

    private function persistPortsStatistics(Device $device, array $mapped): void
    {
        DeviceApiPersistor::savePortsStatistics($device, $mapped);
    }

    /**
     * Save inventory data
     * @param Device $device
     * @param array $mapped
     */
    private function persistInventory(Device $device, array $mapped): void
    {
        DeviceApiPersistor::saveInventory($device, $mapped);
    }

    /**
     * Save IPv4 data
     * @param Device $device
     * @param array $mapped
     */
    private function persistIpv4(Device $device, array $mapped): void
    {
        DeviceApiPersistor::saveIpv4Addresses($device, $mapped);
    }

    /**
     * Save ports data
     * @param Device $device
     * @param array $mapped
     */
    private function persistPorts(Device $device, array $mapped): void
    {
        DeviceApiPersistor::savePorts($device, $mapped);
    }

    /**
     * Save sensors data
     * @param Device $device
     * @param array $mapped
     */
    private function persistSensors(Device $device, array $mapped): void
    {
        DeviceApiPersistor::saveSensors($device, $mapped);
    }

    /**
     * Save storage data
     * @param Device $device
     * @param array $mapped
     */
    private function persistStorage(Device $device, array $mapped): void
    {
        DeviceApiPersistor::saveStorage($device, $mapped);
    }

    /**
     * Save transceivers data
     * @param Device $device
     * @param array $mapped
     */
    private function persistTransceivers(Device $device, array $mapped): void
    {
        DeviceApiPersistor::saveTransceivers($device, $mapped);
    }
}
