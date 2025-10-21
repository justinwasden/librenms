<?php

namespace App\Pollers;

use App\Models\Device;
use App\Models\Port;
use App\Models\Sensor;
use App\Models\Storage;
use App\Models\Link;
use App\Models\RestApiConnection;
use App\RestApi\Services\MapperSelectionService;
use App\RestApi\Credentials\CredentialHelper;
use App\RestApi\Utils\JsonPathExtractor;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class RestApiPoller
{
    protected Client $client;
    protected array $sessionTokens = [];
    protected Device $device;

    public function __construct()
    {
        $this->client = new Client([
            'timeout' => 30,
            'verify' => false,
        ]);
    }

    public function poll(Device $device)
    {
        $this->device = $device;
        
        $deviceTemplate = $device->restApiTemplate;
        
        if (!$deviceTemplate) {
            Log::info("Device {$device->device_id}: No REST API template configured");
            return;
        }

        // Load the template relationship
        $deviceTemplate->load('template');
        
        if (!$deviceTemplate->template) {
            Log::error("Device {$device->device_id}: Template not found for device template ID {$deviceTemplate->id}");
            return;
        }

        // Get REST API connection for this device
        $connection = $device->restApiConnections()->where('enabled', 1)->first();
        if (!$connection) {
            Log::warning("Device {$device->device_id}: No enabled REST API connection found");
            return;
        }

        // SELECT MAPPER with intelligent priority
        $mapperResult = MapperSelectionService::selectMapper($deviceTemplate);
        $mapper = $mapperResult['mapper'];
        $mapperName = $mapperResult['mapper_name'];
        $mapperSource = $mapperResult['source'];
        
        Log::info("Device {$device->device_id}: Using mapper '{$mapperName}' (source: {$mapperSource})");

        // POLL ENDPOINTS - get from template_data
        $endpoints = $deviceTemplate->template->getEndpoints();
        
        if (!$endpoints || $endpoints->isEmpty()) {
            Log::warning("Device {$device->device_id}: No endpoints in template");
            return;
        }

        foreach ($endpoints as $endpoint) {
            $this->pollEndpoint($device, $connection, $endpoint, $mapper);
        }
    }

    private function pollEndpoint(Device $device, RestApiConnection $connection, $endpoint, $mapper)
    {
        // Get mappings for this endpoint
        $mappings = $mapper->getMappingsForEndpoint($endpoint->path);
        
        if (empty($mappings)) {
            Log::warning("Device {$device->device_id}: No mappings for endpoint '{$endpoint->path}'");
            return;
        }

        try {
            // Fetch from API
            $response = $this->fetchEndpoint($device, $connection, $endpoint);
            
            if (!$response) {
                Log::warning("Device {$device->device_id}: Empty response from {$endpoint->path}");
                return;
            }
            
            // Extract and store data
            $this->processResponse($device, $endpoint, $mapper, $mappings, $response);
            
            Log::info("Device {$device->device_id}: Successfully polled {$endpoint->path}");
        } catch (\Exception $e) {
            Log::error("Device {$device->device_id}: Error polling {$endpoint->path}: {$e->getMessage()}");
        }
    }

    private function fetchEndpoint(Device $device, RestApiConnection $connection, $endpoint)
    {
        try {
            // Build URL
            $baseUrl = str_replace('{device_hostname}', $device->hostname, $connection->base_url);
            $url = $baseUrl . $endpoint->path;

            Log::debug("Device {$device->device_id}: Fetching {$url}");

            // Build headers with authentication
            $headers = ['Accept' => 'application/json'];
            
            if ($connection->credential) {
                // Load credential relationships
                $connection->credential->load(['authenticationType', 'params']);
                
                $authType = Str::lower($connection->credential->authenticationType->name ?? '');
                Log::debug("Device {$device->device_id}: Auth type: {$authType}");
                
                // Handle session token auth (two-stage)
                if ($authType === 'session token') {
                    $sessionToken = $this->getSessionToken($device, $connection);
                    if ($sessionToken) {
                        // Get params as key=>value array (values are auto-decrypted by Encryptable trait)
                        $params = $connection->credential->params->pluck('value', 'key')->toArray();
                        $tokenHeader = $params['token_header'] ?? 'x-auth-token';
                        $headers[$tokenHeader] = $sessionToken;
                        Log::debug("Device {$device->device_id}: Added session token header: {$tokenHeader}");
                    } else {
                        Log::error("Device {$device->device_id}: Failed to obtain session token");
                        return [];
                    }
                } else {
                    // Get auth headers using CredentialHelper for other auth types
                    $authHeaders = CredentialHelper::getAuthHeaderFromModel($connection->credential);
                    $headers = array_merge($headers, $authHeaders);
                    
                    if (!empty($authHeaders)) {
                        Log::debug("Device {$device->device_id}: Added " . count($authHeaders) . " auth header(s)");
                    }
                }
            }

            // Make request
            $response = $this->client->request('GET', $url, [
                'headers' => $headers,
                'verify' => !$connection->disable_ssl_verify,
            ]);

            // Parse response
            $body = $response->getBody()->getContents();
            $data = json_decode($body, true);

            Log::debug("Device {$device->device_id}: Got response from {$endpoint->path} with " . (is_array($data) ? count($data) : 1) . " top-level key(s)");
            
            return $data ?: [];
        } catch (GuzzleException $e) {
            Log::error("Device {$device->device_id}: HTTP error fetching {$endpoint->path}: {$e->getMessage()}");
            return [];
        } catch (\Exception $e) {
            Log::error("Device {$device->device_id}: Error fetching {$endpoint->path}: {$e->getMessage()}");
            return [];
        }
    }

    /**
     * Get session token via login endpoint
     * Uses API token to authenticate and receives session token in response header
     */
    private function getSessionToken(Device $device, RestApiConnection $connection): ?string
    {
        $cacheKey = "device_{$device->device_id}_connection_{$connection->id}";
        
        // Return cached token if available
        if (isset($this->sessionTokens[$cacheKey])) {
            Log::debug("Device {$device->device_id}: Using cached session token");
            return $this->sessionTokens[$cacheKey];
        }

        try {
            $credential = $connection->credential;
            
            // Ensure params are loaded (this triggers automatic decryption via Encryptable trait)
            if (!$credential->relationLoaded('params')) {
                $credential->load('params');
            }
            
            $params = $credential->params->pluck('value', 'key')->toArray();
            
            Log::debug("Device {$device->device_id}: Credential params keys: " . implode(', ', array_keys($params)));
            
            // Get API token for login (key is 'api_token')
            $apiToken = $params['api_token'] ?? null;
            if (!$apiToken) {
                Log::error("Device {$device->device_id}: No API token found in credential. Available keys: " . implode(', ', array_keys($params)));
                return null;
            }

            // Build login URL
            $baseUrl = str_replace('{device_hostname}', $device->hostname, $connection->base_url);
            $loginPath = $params['login_path'] ?? '/login';
            $loginUrl = $baseUrl . $loginPath;
            
            // Get header names from params
            $apiTokenHeader = $params['api_token_header'] ?? 'api-token';
            $sessionTokenHeader = $params['session_token_header'] ?? 'x-auth-token';

            Log::debug("Device {$device->device_id}: Obtaining session token from {$loginUrl}");
            Log::debug("Device {$device->device_id}: API token header: {$apiTokenHeader}, Session token header: {$sessionTokenHeader}");

            // Make login request with API token
            $response = $this->client->request('POST', $loginUrl, [
                'headers' => [
                    $apiTokenHeader => $apiToken,
                    'Accept' => 'application/json',
                ],
                'verify' => !$connection->disable_ssl_verify,
            ]);

            // Extract session token from response header
            $sessionToken = $response->getHeader($sessionTokenHeader)[0] ?? null;

            if (!$sessionToken) {
                Log::error("Device {$device->device_id}: Session token not found in response header: {$sessionTokenHeader}");
                Log::debug("Device {$device->device_id}: Response headers: " . json_encode($response->getHeaders()));
                return null;
            }

            Log::debug("Device {$device->device_id}: Session token obtained successfully");
            
            // Cache the token
            $this->sessionTokens[$cacheKey] = $sessionToken;
            
            return $sessionToken;

        } catch (GuzzleException $e) {
            Log::error("Device {$device->device_id}: HTTP error obtaining session token: {$e->getMessage()}");
            return null;
        } catch (\Exception $e) {
            Log::error("Device {$device->device_id}: Error obtaining session token: {$e->getMessage()}");
            return null;
        }
    }

    private function processResponse(Device $device, $endpoint, $mapper, $mappings, $response)
    {
        // Get target table for this endpoint
        $targetTable = $mapper->getTargetTableForEndpoint($endpoint->path);
        
        // Handle multi-item responses
        $items = $response['items'] ?? [$response];
        
        if (empty($items)) {
            return;
        }

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            foreach ($mappings as $targetField => $jsonPath) {
                // Extract value from API response using JSONPath
                $value = JsonPathExtractor::extract($item, $jsonPath);

                if ($value === null) {
                    continue;
                }

                // Store to database
                $this->storeValue($device, $targetTable, $item, $targetField, $value, $endpoint->path);
            }
        }
    }

    /**
     * Store metric value to appropriate LibreNMS table
     * 
     * @param Device $device
     * @param string $table Target table (devices, ports, storage, sensors, links)
     * @param array $itemData Complete item data for context
     * @param string $field Target field name
     * @param mixed $value Field value
     * @param string $endpoint Endpoint path for context
     */
    private function storeValue(Device $device, string $table, array $itemData, string $field, $value, string $endpoint)
    {
        try {
            $field = trim($field);
            $value = trim((string) $value);

            Log::debug("Device {$device->device_id}: Storing {$table}.{$field} = {$value}");

            switch ($table) {
                case 'devices':
                    $this->storeDeviceMetric($device, $field, $value);
                    break;

                case 'ports':
                    $this->storePortMetric($device, $itemData, $field, $value);
                    break;

                case 'storage':
                    $this->storeStorageMetric($device, $itemData, $field, $value);
                    break;

                case 'sensors':
                    $this->storeSensorMetric($device, $itemData, $field, $value, $endpoint);
                    break;

                case 'links':
                    $this->storeLinkMetric($device, $itemData, $field, $value);
                    break;

                default:
                    Log::warning("Device {$device->device_id}: Unknown table type: {$table}");
            }
        } catch (\Exception $e) {
            Log::error("Device {$device->device_id}: Error storing metric {$table}.{$field}: {$e->getMessage()}");
        }
    }

    /**
     * Store device-level metrics (hostname, version, hardware, etc.)
     */
    private function storeDeviceMetric(Device $device, string $field, $value)
    {
        // Map Pure Storage field names to Device model fields
        $fieldMap = [
            'hostname' => 'hostname',
            'version' => 'version',
            'hardware' => 'hardware',
            'serial' => 'serial',
            'os' => 'os',
            'sysName' => 'sysName',
        ];

        if (isset($fieldMap[$field])) {
            $dbField = $fieldMap[$field];
            $device->update([$dbField => $value]);
            Log::debug("Device {$device->device_id}: Updated device.{$dbField} = {$value}");
        }
    }

    /**
     * Store port/network interface metrics
     */
    private function storePortMetric(Device $device, array $itemData, string $field, $value)
    {
        $portName = $itemData['name'] ?? null;
        if (!$portName) {
            return;
        }

        // Find or create port
        $port = Port::firstOrCreate(
            [
                'device_id' => $device->device_id,
                'ifName' => $portName,
            ],
            [
                'ifDescr' => $itemData['services'][0] ?? $portName,
                'ifType' => $itemData['interface_type'] ?? 'other',
                'ifSpeed' => $itemData['speed'] ?? 0,
                'ifPhysAddress' => $itemData['eth']['mac_address'] ?? '',
                'ifMtu' => $itemData['eth']['mtu'] ?? 1500,
            ]
        );

        // Map Pure Storage fields to Port model fields
        $fieldMap = [
            'speed' => 'ifSpeed',
            'mac_address' => 'ifPhysAddress',
            'mtu' => 'ifMtu',
            'address' => 'ipv4_address',
            'netmask' => 'ipv4_netmask',
            'vlan' => 'ifVlan',
        ];

        if (isset($fieldMap[$field])) {
            $port->update([$fieldMap[$field] => $value]);
            Log::debug("Device {$device->device_id}: Updated port {$portName}.{$fieldMap[$field]} = {$value}");
        }
    }

    /**
     * Store storage/volume metrics
     */
    private function storeStorageMetric(Device $device, array $itemData, string $field, $value)
    {
        $storageName = $itemData['name'] ?? null;
        if (!$storageName) {
            return;
        }

        // Find or create storage entry
        $storage = Storage::firstOrCreate(
            [
                'device_id' => $device->device_id,
                'storage_index' => md5($storageName), // Use hash as unique index
            ],
            [
                'storage_descr' => $storageName,
                'storage_type' => 'pure-volume',
            ]
        );

        // Map Pure Storage fields
        $fieldMap = [
            'storage_size' => 'storage_size',
            'storage_used' => 'storage_used',
            'storage_free' => 'storage_free',
            'storage_perc' => 'storage_perc',
        ];

        if (isset($fieldMap[$field])) {
            $storage->update([$fieldMap[$field] => $value]);
            Log::debug("Device {$device->device_id}: Updated storage {$storageName}.{$fieldMap[$field]} = {$value}");
        }
    }

    /**
     * Store sensor metrics (performance, temperature, status, etc.)
     */
    private function storeSensorMetric(Device $device, array $itemData, string $field, $value, string $endpoint)
    {
        // Determine sensor type from endpoint and field
        $sensorType = $this->determineSensorType($endpoint, $field);
        $sensorClass = $this->determineSensorClass($field);
        
        $sensorDescr = $itemData['name'] ?? "{$sensorClass}_{$field}";
        $sensorIndex = md5("{$sensorDescr}_{$endpoint}");

        // Find or create sensor
        $sensor = Sensor::firstOrCreate(
            [
                'device_id' => $device->device_id,
                'sensor_type' => $sensorType,
                'sensor_index' => $sensorIndex,
            ],
            [
                'poller_type' => 'rest-api',
                'sensor_class' => $sensorClass,
                'sensor_descr' => $sensorDescr,
                'rrd_type' => 'GAUGE',
            ]
        );

        // Update sensor value
        $sensor->update(['sensor_current' => $value]);
        Log::debug("Device {$device->device_id}: Updated sensor {$sensorDescr} = {$value}");
    }

    /**
     * Store link/connection metrics
     */
    private function storeLinkMetric(Device $device, array $itemData, string $field, $value)
    {
        $localPort = $itemData['local_port'] ?? null;
        $remotePort = $itemData['remote_port'] ?? null;
        $remoteHost = $itemData['name'] ?? null;

        if (!$localPort || !$remotePort || !$remoteHost) {
            return;
        }

        // Try to find remote device
        $remoteDevice = Device::where('hostname', $remoteHost)->first();
        if (!$remoteDevice) {
            Log::debug("Device {$device->device_id}: Remote device not found: {$remoteHost}");
            return;
        }

        // Find or create link
        $link = Link::firstOrCreate(
            [
                'local_device_id' => $device->device_id,
                'local_port_id' => $localPort,
                'remote_device_id' => $remoteDevice->device_id,
                'remote_port_id' => $remotePort,
            ],
            [
                'link_type' => $itemData['replication_transport'] ?? 'unknown',
            ]
        );

        // Store link status if present
        if ($field === 'status') {
            $link->update(['link_status' => $value]);
            Log::debug("Device {$device->device_id}: Updated link status = {$value}");
        }
    }

    /**
     * Determine sensor type from endpoint and field
     */
    private function determineSensorType(string $endpoint, string $field): string
    {
        if (strpos($endpoint, 'performance') !== false) {
            if (strpos($field, 'latency') !== false) {
                return 'latency';
            } elseif (strpos($field, 'iops') !== false || strpos($field, 'reads_per_sec') !== false) {
                return 'iops';
            } elseif (strpos($field, 'bytes_per_sec') !== false) {
                return 'throughput';
            }
        }

        if (strpos($endpoint, 'hardware') !== false) {
            if (strpos($field, 'temperature') !== false) {
                return 'temperature';
            } elseif (strpos($field, 'voltage') !== false) {
                return 'voltage';
            }
        }

        return 'generic';
    }

    /**
     * Determine sensor class for RRD graphing
     */
    private function determineSensorClass(string $field): string
    {
        if (strpos($field, 'temperature') !== false) {
            return 'temperature';
        } elseif (strpos($field, 'voltage') !== false) {
            return 'voltage';
        } elseif (strpos($field, 'current') !== false || strpos($field, 'bias') !== false) {
            return 'current';
        } elseif (strpos($field, 'power') !== false) {
            return 'power';
        } elseif (strpos($field, 'status') !== false || strpos($field, 'state') !== false) {
            return 'state';
        } elseif (strpos($field, 'latency') !== false) {
            return 'delay';
        } elseif (strpos($field, 'iops') !== false || strpos($field, 'operations') !== false) {
            return 'counter';
        }

        return 'gauge';
    }
}
