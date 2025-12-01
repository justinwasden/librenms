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
    private $os;

    public function __construct($os = null)
    {
        $this->os = $os;
    }

    /**
     * Execute all endpoints for a template against a device.
     *
     * @param Device $device
     * @param string $templateKey
     * @param object $client Must implement get(path, query=[]) and post(path, body=[]) -> array
     */
    public function run(Device $device, string $templateKey, $client): void
    {
        // Log the template key
        Log::info("DeviceApiExecutor: Using template key '{$templateKey}' for device {$device->device_id}");

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


        // Auto-discover Proxmox node name if missing
        $this->ensureProxmoxNodeAttribute($device, $templateKey, $client);

        // Load device-specific endpoint overrides from device_api_endpoints table
        $deviceOverrides = \App\Models\DeviceApiEndpoint::where('device_id', $device->device_id)
            ->get()
            ->keyBy(function ($ep) {
                // Key by path+method+capability to handle multiple endpoints with same path
                return $ep->path . '::' . $ep->method . '::' . $ep->capability;
            });

        // Apply device-specific overrides to template endpoints
        if ($deviceOverrides->isNotEmpty()) {
            $endpoints = array_map(function ($ep) use ($deviceOverrides) {
                $key = $ep['path'] . '::' . ($ep['method'] ?? 'GET') . '::' . $ep['capability'];
                if (isset($deviceOverrides[$key])) {
                    $override = $deviceOverrides[$key];
                    // Merge device override settings, allowing device settings to take precedence
                    $ep['enabled'] = $override->enabled;
                    // Optionally override other fields if they're set in the device override
                    if ($override->transform) {
                        $ep['transform'] = $override->transform;
                    }
                    if ($override->headers) {
                        $ep['headers'] = array_merge($ep['headers'] ?? [], $override->headers);
                    }
                    if ($override->request_body) {
                        $ep['request_body'] = $override->request_body;
                    }
                    Log::debug("Applied device override for endpoint {$ep['path']} capability {$ep['capability']}: enabled={$override->enabled}");
                }
                return $ep;
            }, $endpoints);
        }

        $endpointResults = []; // Cache results of fetches by path
        $iterativeEndpoints = [];

        // First pass: execute all non-iterative endpoints and collect iterative ones
        foreach ($endpoints as $ep) {
            // Skip disabled endpoints
            if (isset($ep['enabled']) && !$ep['enabled']) {
                Log::debug("DeviceApiExecutor skipping disabled endpoint: {$ep['path']}");
                continue;
            }

            if (!empty($ep['for_each'])) {
                $iterativeEndpoints[] = $ep;
                continue;
            }

            $path = $ep['path'];
            if (!isset($endpointResults[$path])) {
                // Check cache first
                $capability = $ep['capability'] ?? 'unknown';
                $cachedResponse = DeviceApiCache::get($device, $path, $capability);

                if ($cachedResponse !== null) {
                    $endpointResults[$path] = $cachedResponse;
                    Log::debug("DeviceApiExecutor: Using cached response for device {$device->device_id} path {$path}");
                } else {
                    try {
                        // Check if this is a SOAP endpoint (method === 'SOAP')
                        if (isset($ep['method']) && strtoupper($ep['method']) === 'SOAP') {
                            $endpointResults[$path] = $this->executeSoapEndpoint($device, $ep, $client);
                        } elseif (isset($ep['method']) && strtoupper($ep['method']) === 'XML') {
                            // XML endpoint - path is the method name to call on the client
                            $endpointResults[$path] = $this->executeXmlEndpoint($device, $ep, $client);
                        } else {
                            // Standard REST/HTTP endpoint
                            // Resolve placeholders like {hostname}, {node}, etc.
                            $resolvedPath = EndpointPathResolver::resolve($device, $path);
                            $method = strtoupper($ep['method'] ?? 'GET');
                            Log::debug("DeviceApiExecutor {$method}: path={$path} resolved={$resolvedPath}");
                            $pathParts = parse_url($resolvedPath);
                            $basePath = $pathParts['path'] ?? $resolvedPath;
                            $queryParams = [];
                            if (isset($pathParts['query'])) {
                                parse_str($pathParts['query'], $queryParams);
                            }

                            // Use POST or GET based on endpoint method
                            if ($method === 'POST') {
                                // For POST, send query params as body
                                $endpointResults[$path] = $client->post($basePath, $queryParams);
                            } else {
                                $endpointResults[$path] = $client->get($basePath, $queryParams);
                            }
                        }

                        // Cache the response if successful
                        if ($endpointResults[$path] !== null && DeviceApiCache::shouldCache($capability)) {
                            DeviceApiCache::put($device, $path, $capability, $endpointResults[$path]);
                        }
                    } catch (\Throwable $e) {
                        $method = strtoupper($ep['method'] ?? 'GET');
                        Log::warning("REST API {$method} failed for device {$device->device_id} path {$path}: {$e->getMessage()}", [
                            'device_id' => $device->device_id,
                            'hostname' => $device->hostname,
                            'capability' => $ep['capability'] ?? 'unknown',
                            'path' => $path,
                            'exception' => get_class($e),
                            'trace' => $e->getTraceAsString(),
                        ]);
                        $endpointResults[$path] = null; // Cache failure to avoid retrying

                        // Track failed endpoint metrics
                        $device->setAttrib('api_endpoint_last_error_' . md5($path), [
                            'timestamp' => time(),
                            'error' => $e->getMessage(),
                            'capability' => $ep['capability'] ?? 'unknown',
                        ]);

                        // Continue to next endpoint - don't fail entire poll
                        continue;
                    }
                }
            }

            $payload = $endpointResults[$path];
            if ($payload !== null) {
                try {
                    $mapped = TransformRunner::run($ep['transform'], $device, $payload, $ep);
                    $this->persistByCapability($device, $ep['capability'], $mapped);
                } catch (\Throwable $e) {
                    Log::error("REST API transform/persist failed for device {$device->device_id} path {$path}: {$e->getMessage()}", [
                        'device_id' => $device->device_id,
                        'hostname' => $device->hostname,
                        'capability' => $ep['capability'] ?? 'unknown',
                        'transform' => $ep['transform'] ?? 'none',
                        'exception' => get_class($e),
                        'message' => $e->getMessage(),
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                    ]);

                    // Continue to next endpoint - don't fail entire poll
                    continue;
                }

                // Make the normalized data available for for_each loops
                // Merge results if multiple endpoints have the same capability
                if (is_array($mapped) && !empty($mapped)) {
                    // Check if it's a list of items or a structured response
                    $firstKey = array_key_first($mapped);
                    if (is_int($firstKey)) {
                        // It's a simple list of items (e.g., [item1, item2, ...]), merge them
                        if (!isset($endpointResults[$ep['capability']])) {
                            $endpointResults[$ep['capability']] = [];
                        }
                        $endpointResults[$ep['capability']] = array_merge($endpointResults[$ep['capability']], $mapped);
                    } else {
                        // It's a structured response (e.g., ['inventory' => [...], 'sensors' => [...]])
                        // For the capability key specifically, merge its items
                        if (isset($mapped[$ep['capability']]) && is_array($mapped[$ep['capability']])) {
                            if (!isset($endpointResults[$ep['capability']])) {
                                $endpointResults[$ep['capability']] = [];
                            }
                            $endpointResults[$ep['capability']] = array_merge(
                                $endpointResults[$ep['capability']],
                                $mapped[$ep['capability']]
                            );
                        } else {
                            // Fallback: store the whole structured response (only if not set yet)
                            if (!isset($endpointResults[$ep['capability']])) {
                                $endpointResults[$ep['capability']] = $mapped;
                            }
                        }
                    }
                }
            }
        }

        // Second pass: execute iterative endpoints
        foreach ($iterativeEndpoints as $ep) {
            // Skip disabled endpoints
            if (isset($ep['enabled']) && !$ep['enabled']) {
                Log::debug("DeviceApiExecutor skipping disabled iterative endpoint: {$ep['path']}");
                continue;
            }

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
                $filter_key = $options['filter_key'] ?? null;

                // If filter_key is specified, skip items that don't have that field
                if ($filter_key && !isset($item[$filter_key])) {
                    continue;
                }

                if ($placeholder && $value_key && isset($item[$value_key])) {
                    // First replace the for_each placeholder
                    $iterated_path = str_replace('{' . $placeholder . '}', $item[$value_key], $ep['path']);
                    // Then resolve other placeholders like {node}, {hostname}, etc.
                    $iterated_path = \LibreNMS\Util\EndpointPathResolver::resolve($device, $iterated_path);

                    try {
                        Log::debug("DeviceApiExecutor GET (iterative): path={$iterated_path}");
                        Log::debug('DeviceApiExecutor (iterative) debug', [
                            'original_path' => $ep['path'],
                            'placeholder' => $placeholder,
                            'value_key' => $value_key,
                            'replacement_value' => $item[$value_key],
                            'item_data' => $item,
                        ]);
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
     * - device_info: associative array with keys [hardware, serial, sysObjectID, sysContact, uptime, location, lat, lng]
     */
    private function persistByCapability(Device $device, string $capability, array $mapped): void
    {
        // Check if the response is a structured response with multiple capability keys
        // Common capability keys that normalizers might return
        $knownCapabilities = ['ports', 'port_statistics', 'ports_statistics', 'ports_stats', 'sensors', 'storage',
                              'inventory', 'ipv4', 'ipv4_addresses', 'ipv4_mac', 'vlans',
                              'transceivers', 'processors', 'mempools', 'device_info',
                              'array', 'controllers', 'volumes', 'hosts', 'vminfo',
                              'clusters', 'hypervisor_hosts'];

        // If the mapped data has keys that match known capabilities, it's a structured response
        $hasCapabilityKeys = !empty(array_intersect(array_keys($mapped), $knownCapabilities));

        if ($hasCapabilityKeys) {
            // Structured response - persist each capability separately
            foreach ($mapped as $key => $data) {
                if (in_array($key, $knownCapabilities) && !empty($data)) {
                    Log::debug("Processing structured response capability: {$key}", [
                        'device_id' => $device->device_id,
                        'count' => is_array($data) ? count($data) : 'N/A'
                    ]);
                    $this->persistByCapability($device, $key, $data);
                }
            }
            return;
        }

        // Normal response - persist the data directly based on the capability
        switch ($capability) {
            case 'device_info':
                $this->persistDeviceInfo($device, $mapped);
                break;
            case 'inventory':
                $this->persistInventory($device, $mapped);
                break;
            case 'ipv4':
            case 'ipv4_addresses':
                $this->persistIpv4($device, $mapped);
                break;
            case 'ports':
                $this->persistPorts($device, $mapped);
                break;
            case 'port_statistics':
            case 'ports_stats':
            case 'ports_statistics':
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
            case 'vlans':
                $this->persistVlans($device, $mapped);
                break;
            case 'processors':
                $this->persistProcessors($device, $mapped);
                break;
            case 'mempools':
                $this->persistMempools($device, $mapped);
                break;
            case 'array':
                $this->persistStorageArray($device, $mapped);
                break;
            case 'controllers':
                $this->persistControllers($device, $mapped);
                break;
            case 'volumes':
                $this->persistVolumes($device, $mapped);
                break;
            case 'hosts':
                $this->persistHosts($device, $mapped);
                break;
            case 'vminfo':
                $this->persistVminfo($device, $mapped);
                break;
            case 'clusters':
                $this->persistClusters($device, $mapped);
                break;
            case 'hypervisor_hosts':
                $this->persistHypervisorHosts($device, $mapped);
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

    /**
     * Save device info (sysName, version, etc.)
     * @param Device $device
     * @param array $mapped
     */
    private function persistDeviceInfo(Device $device, array $mapped): void
    {
        DeviceApiPersistor::saveDeviceInfo($device, $mapped);
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

    /**
     * Save VLANs data
     * @param Device $device
     * @param array $mapped
     */
    private function persistVlans(Device $device, array $mapped): void
    {
        DeviceApiPersistor::saveVlans($device, $mapped);
    }

    /**
     * Save processors data
     * @param Device $device
     * @param array $mapped
     */
    private function persistProcessors(Device $device, array $mapped): void
    {
        DeviceApiPersistor::saveProcessors($device, $mapped);

        // Enable processor graph if we have processors
        if (!empty($mapped) && $this->os) {
            $this->os->enableGraph('processor');
        }
    }

    /**
     * Save mempools data
     * @param Device $device
     * @param array $mapped
     */
    private function persistMempools(Device $device, array $mapped): void
    {
        DeviceApiPersistor::saveMempools($device, $mapped);

        // Enable mempool graph if we have mempools
        if (!empty($mapped) && $this->os) {
            $this->os->enableGraph('mempool');
        }
    }

    /**
     * Save storage array metadata
     * @param Device $device
     * @param array $mapped
     */
    private function persistStorageArray(Device $device, array $mapped): void
    {
        DeviceApiPersistor::saveStorageArray($device, $mapped);
    }

    /**
     * Save storage controllers data
     * @param Device $device
     * @param array $mapped
     */
    private function persistControllers(Device $device, array $mapped): void
    {
        DeviceApiPersistor::saveControllers($device, $mapped);
    }

    /**
     * Save storage volumes data
     * @param Device $device
     * @param array $mapped
     */
    private function persistVolumes(Device $device, array $mapped): void
    {
        DeviceApiPersistor::saveVolumes($device, $mapped);
    }

    /**
     * Save storage hosts data
     * @param Device $device
     * @param array $mapped
     */
    private function persistHosts(Device $device, array $mapped): void
    {
        DeviceApiPersistor::saveHosts($device, $mapped);
    }

    private function persistVminfo(Device $device, array $mapped): void
    {
        DeviceApiPersistor::saveVminfo($device, $mapped);
    }

    private function persistClusters(Device $device, array $mapped): void
    {
        DeviceApiPersistor::saveClusters($device, $mapped);
    }

    private function persistHypervisorHosts(Device $device, array $mapped): void
    {
        DeviceApiPersistor::saveHypervisorHosts($device, $mapped);
    }

    /**
     * Ensure the proxmox_node attribute is set for Proxmox devices.
     * If missing, automatically discover the node name(s) from cluster/resources.
     *
     * @param Device $device
     * @param string $templateKey
     * @param object $client
     */
    private function ensureProxmoxNodeAttribute(Device $device, string $templateKey, $client): void
    {
        // Only run for Proxmox templates
        if (!str_contains($templateKey, 'proxmox')) {
            return;
        }

        // Check if proxmox_node attribute already exists
        $existingNode = $device->getAttrib('proxmox_node');
        if ($existingNode) {
            Log::debug("Device {$device->device_id} already has proxmox_node set to: {$existingNode}");
            return;
        }

        // Attempt to discover node name from cluster/resources
        try {
            Log::info("DeviceApiExecutor: Auto-discovering Proxmox node name for device {$device->device_id}");

            $resources = $client->get('cluster/resources');
            $data = $resources['data'] ?? [];
            $nodes = array_filter($data, fn($r) => ($r['type'] ?? '') === 'node');

            if (empty($nodes)) {
                Log::warning("DeviceApiExecutor: No Proxmox nodes found in cluster/resources for device {$device->device_id}");
                return;
            }

            // Get the first online node as the default
            $onlineNodes = array_filter($nodes, fn($r) => ($r['status'] ?? '') === 'online');
            $primaryNode = !empty($onlineNodes) ? reset($onlineNodes) : reset($nodes);
            $nodeName = $primaryNode['node'] ?? null;

            if ($nodeName) {
                $device->setAttrib('proxmox_node', $nodeName);
                Log::info("DeviceApiExecutor: Set proxmox_node to '{$nodeName}' for device {$device->device_id}");

                // Log if this is a cluster with multiple nodes
                if (count($nodes) > 1) {
                    $nodeNames = array_map(fn($n) => $n['node'] ?? 'unknown', $nodes);
                    Log::info("DeviceApiExecutor: Proxmox cluster detected with " . count($nodes) . " nodes: " . implode(', ', $nodeNames) . ". Using '{$nodeName}' as primary.");
                }
            } else {
                Log::warning("DeviceApiExecutor: Could not extract node name from cluster/resources for device {$device->device_id}");
            }
        } catch (\Throwable $e) {
            Log::warning("DeviceApiExecutor: Failed to auto-discover Proxmox node for device {$device->device_id}: {$e->getMessage()}");
        }
    }

    /**
     * Execute a SOAP endpoint using EsxiSoapClient
     *
     * @param Device $device
     * @param array $ep Endpoint configuration
     * @param mixed $client HTTP client (not used for SOAP, we create our own)
     * @return array|null SOAP method response
     */
    private function executeSoapEndpoint(Device $device, array $ep, $client): ?array
    {
        try {
            // Use factory to create SOAP client from device config
            $soapClient = \App\ApiClients\VMware\EsxiSoapClientFactory::makeFromDevice($device);

            if (!$soapClient) {
                Log::warning("SOAP endpoint configured but no SOAP API config found for device {$device->device_id}");
                return null;
            }

            // The endpoint 'path' contains the method name (e.g., 'fetchHostHardware')
            $methodName = $ep['path'];

            if (!method_exists($soapClient, $methodName)) {
                Log::warning("SOAP method {$methodName} does not exist on EsxiSoapClient");
                return null;
            }

            Log::debug("DeviceApiExecutor SOAP: calling {$methodName} for device {$device->device_id}");

            // Call the SOAP method
            $result = $soapClient->$methodName($device);

            return $result;
        } catch (\Throwable $e) {
            Log::error("SOAP endpoint execution failed for device {$device->device_id} method {$ep['path']}: {$e->getMessage()}");
            return null;
        }
    }

    private function executeXmlEndpoint(Device $device, array $ep, $client): ?array
    {
        try {
            // The client is already instantiated (UcsmXmlClient)
            // The endpoint 'path' contains the method name (e.g., 'fetchChassis')
            $methodName = $ep['path'];

            if (!method_exists($client, $methodName)) {
                Log::warning("XML method {$methodName} does not exist on " . get_class($client));
                return null;
            }

            Log::debug("DeviceApiExecutor XML: calling {$methodName} for device {$device->device_id}");

            // Call the XML method
            $result = $client->$methodName($device);

            return $result;
        } catch (\Throwable $e) {
            Log::error("XML endpoint execution failed for device {$device->device_id} method {$ep['path']}: {$e->getMessage()}");
            return null;
        }
    }
}
