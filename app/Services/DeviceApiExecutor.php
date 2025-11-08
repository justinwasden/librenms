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

        $endpoints = $tpl['endpoints'] ?? [];
        if (empty($endpoints)) {
            Log::info("No REST endpoints configured for device {$device->device_id}");
            return;
        }

        $endpointResults = []; // Cache results of fetches by path
        $iterativeEndpoints = [];

        // First pass: execute all non-iterative endpoints and collect iterative ones
        foreach ($endpoints as $ep) {
            if (!empty($ep['for_each'])) {
                $iterativeEndpoints[] = $ep;
                continue;
            }

            $path = $ep['path'];
            if (!isset($endpointResults[$path])) {
                try {
                    Log::debug("DeviceApiExecutor GET: path={$path}");
                    $pathParts = parse_url($path);
                    $basePath = $pathParts['path'] ?? $path;
                    $queryParams = [];
                    if (isset($pathParts['query'])) {
                        parse_str($pathParts['query'], $queryParams);
                    }
                    $endpointResults[$path] = $client->get($basePath, $queryParams);
                } catch (\Throwable $e) {
                    Log::warning("REST fetch failed for device {$device->device_id} path {$path}: {$e->getMessage()}");
                    $endpointResults[$path] = null; // Cache failure
                }
            }

            $payload = $endpointResults[$path];
            if ($payload !== null) {
                $mapped = TransformRunner::run($ep['transform'], $device, $payload, $ep);
                $this->persistByCapability($device, $ep['capability'], $mapped);

                // Make the normalized data available for for_each loops
                if (!isset($endpointResults[$ep['capability']])) {
                    $endpointResults[$ep['capability']] = $mapped;
                }
            }
        }

        // Second pass: execute iterative endpoints
        foreach ($iterativeEndpoints as $ep) {
            $parentCapability = $ep['for_each'];
            if (!isset($endpointResults[$parentCapability])) {
                Log::warning("Could not find parent data for capability '{$parentCapability}' for a for_each loop.");
                continue;
            }

            $iterativeResults = [];
            foreach ($endpointResults[$parentCapability] as $item) {
                $path = $ep['path'];
                $options = is_string($ep['for_each_options']) ? json_decode($ep['for_each_options'], true) : ($ep['for_each_options'] ?? []);
                $placeholder = $options['placeholder'] ?? null;
                $value_key = $options['value_key'] ?? null;

                if ($placeholder && $value_key && isset($item[$value_key])) {
                    $iterated_path = str_replace('{' . $placeholder . '}', $item[$value_key], $ep['path']);
                    try {
                        Log::debug("DeviceApiExecutor GET (iterative): path={$iterated_path}");
                        $pathParts = parse_url($iterated_path);
                        $basePath = $pathParts['path'] ?? $iterated_path;
                        $queryParams = [];
                        if (isset($pathParts['query'])) {
                            parse_str($pathParts['query'], $queryParams);
                        }
                        $payload = $client->get($basePath, $queryParams);

                        if ($payload !== null) {
                            $payload['_parent_item'] = $item;
                            $mapped = TransformRunner::run($ep['transform'], $device, $payload, $ep);
                            $iterativeResults = array_merge($iterativeResults, $mapped);
                        }
                    } catch (\Throwable $e) {
                        Log::warning("REST fetch failed for device {$device->device_id} path {$iterated_path}: {$e->getMessage()}");
                    }
                }
            }

            if (!empty($iterativeResults)) {
                $this->persistByCapability($device, $ep['capability'], $iterativeResults);
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
