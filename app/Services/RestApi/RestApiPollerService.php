<?php

namespace App\Services\RestApi;

use App\Models\RestApiConnection;
use App\Models\RestApiMetric;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RestApiPollerService
{
    private $authTokens = [];  // Cache auth tokens per connection

    /**
     * JSONPath parser - extracts values from arrays using JSONPath notation
     * Supports: $.items[*].field, $.items[0].field, $.field.subfield
     */
    private function extractJsonPath(array $data, string $path): mixed
    {
        // Handle simple direct path
        if (strpos($path, '$') === 0) {
            $path = substr($path, 1);
        }

        if (strpos($path, '.') === 0) {
            $path = substr($path, 1);
        }

        // Handle array wildcard notation like items[*].field
        if (preg_match('/^(\w+)\[\*\]\.(.+)$/', $path, $matches)) {
            $arrayField = $matches[1];
            $subPath = $matches[2];

            if (!isset($data[$arrayField]) || !is_array($data[$arrayField])) {
                return null;
            }

            $results = [];
            foreach ($data[$arrayField] as $item) {
                $value = $this->extractJsonPath($item, '.' . $subPath);
                if ($value !== null) {
                    $results[] = $value;
                }
            }

            return !empty($results) ? $results : null;
        }

        // Handle numeric array index like items[0]
        if (preg_match('/^(\w+)\[(\d+)\](?:\.(.+))?$/', $path, $matches)) {
            $arrayField = $matches[1];
            $index = (int) $matches[2];
            $subPath = $matches[3] ?? null;

            if (!isset($data[$arrayField][$index])) {
                return null;
            }

            $value = $data[$arrayField][$index];

            if ($subPath) {
                return $this->extractJsonPath($value, '.' . $subPath);
            }

            return $value;
        }

        // Handle nested path like field.subfield.nested
        $parts = explode('.', $path);
        $current = $data;

        foreach ($parts as $part) {
            if (!is_array($current) || !isset($current[$part])) {
                return null;
            }
            $current = $current[$part];
        }

        return $current;
    }

    public function pollAllDevices(): void
    {
        RestApiConnection::where('enabled', true)
            ->with(['device', 'endpoints', 'credential'])
            ->chunk(20, function ($connections) {
                foreach ($connections as $connection) {
                    try {
                        $this->pollDeviceConnection($connection);
                    } catch (\Throwable $e) {
                        Log::error("REST API poll failure for device {$connection->device->hostname}: {$e->getMessage()}");
                    }
                }
            });

        // Clear cached tokens after polling
        $this->authTokens = [];
    }

    public function pollDeviceConnection(RestApiConnection $connection): void
    {
        // For Session Token authentication (e.g., Pure Storage): authenticate and get session token
        if ($connection->credential && strtolower($connection->credential->authenticationType->name ?? '') === 'session token') {
            try {
                $this->sessionTokenLogin($connection);
            } catch (\Throwable $e) {
                Log::error("Session token login failed for {$connection->device->hostname}: {$e->getMessage()}", [
                    'device_id' => $connection->device_id,
                    'error' => (string) $e,
                ]);
                return;
            }
        }

        foreach ($connection->endpoints()->where('enabled', true)->get() as $endpoint) {
            try {
                $this->processEndpoint($connection, $endpoint);
            } catch (\Throwable $e) {
                Log::error("REST poll error for {$connection->device->hostname} ({$endpoint->path}): {$e->getMessage()}", [
                    'device_id' => $connection->device_id,
                    'endpoint' => $endpoint->path,
                    'exception' => $e,
                ]);
            }
        }
    }

    /**
     * Pure Storage requires POST /login with X-API-Token to get X-Auth-Token
     * This exchanges the API token for a session token
     */
    // In RestApiPollerService.php

		protected function sessionTokenLogin(RestApiConnection $connection): void
		{
		    // 1. Retrieve parameters from the Session Token credential configuration
		    $params = $connection->credential->getParamsArray();
			    // Retrieve configurable login details (using keys from session-token.blade.php)
		    $loginPath = $params['login_path'] ?? 'login';
		    $loginMethod = strtoupper($params['login_method'] ?? 'POST');
		    $sendHeaderName = $params['api_token_header'] ?? 'api-token';         // Header name to SEND the API key
		    $receiveHeaderName = $params['token_header'] ?? 'X-Auth-Token';       // Header name to RECEIVE the session token
		    $apiKey = $params['api_token'] ?? null;                               // The decrypted API key value

		    // 2. Construct the final login URL
		    // Safely combines the base_url with the configurable login_path
		    $loginUrl = rtrim($connection->base_url, '/') . '/' . ltrim($loginPath, '/');

		    $request = Http::withOptions([
		        'verify' => !$connection->disable_ssl_verify,
		        'timeout' => 30,
		    ]);

		    if (!$apiKey) {
		        throw new \Exception("No API key found in credential");
		    }

		    // 3. Send login request with the CORRECT header name and Content-Type
		    $request = $request->withHeaders([
		        $sendHeaderName => $apiKey,
		        'Content-Type' => 'application/json',
		    ]);

		    // Use the configured method (POST/GET) and explicitly send an empty payload
		    $response = $request->{$loginMethod}($loginUrl, []);

		    if (!$response->successful()) {
    		    throw new \Exception("Pure Storage login failed: HTTP {$response->status()} - {$response->body()}");
  		  }

		    // 4. Extract Session Token using the configured response header name
		    $authToken = $response->header($receiveHeaderName);

		    if (!$authToken) {
    		    throw new \Exception("No {$receiveHeaderName} in response headers");
  		  }

		    // Cache the token
		    $this->authTokens[$connection->id] = $authToken;

		    Log::info("Pure Storage login successful for connection {$connection->id}", [
		        'device_id' => $connection->device_id,
		        'login_url' => $loginUrl,
		    ]);
		}

		protected function processEndpoint(RestApiConnection $connection, $endpoint): void
		{
		    $baseUrl = rtrim($connection->base_url, '/');
		    $endpointPath = ltrim($endpoint->path, '/');

		    // Check if the Base URL contains the API version string
		    $apiUrlSegment = '/api/2.26/';

		    $url = $baseUrl;

        if (Str::startsWith($endpointPath, 'api/')) {

        	$basePathSegment = parse_url($baseUrl, PHP_URL_PATH);

        	if ($basePathSegment && Str::endsWith($basePathSegment, '/2.26')) {
						$url = Str::before($baseUrl, $basePathSegment) . '/' . $endpointPath;
					} else {

						$url = $baseUrl . '/' . $endpointPath;
        	}
			} elseif (!Str::contains($baseUrl, $apiUrlSegment) && !Str::contains($endpointPath, $apiUrlSegment)) {
			        // Case 2: Neither Base URL nor Endpoint Path contains the version (e.g., Base=https://172.16.7.5, Path=/arrays)
			        // We assume the version is necessary and missing.
			        $url = $baseUrl . '/api/2.26/' . $endpointPath; // <--- This assumes version 2.26 is required

			    } else {
			        // Case 3: Standard combination (Base URL contains version, Path does not)
			        $url = $baseUrl . '/' . $endpointPath;
			    }

        // Build HTTP request with authentication
        $request = Http::withOptions([
            'verify' => !$connection->disable_ssl_verify,
            'timeout' => 30,
        ]);

        // Apply credentials/auth headers
        if ($connection->credential) {
            $authType = strtolower($connection->credential->authenticationType->name ?? '');

            // Session token auth (e.g., Pure Storage): use cached token from login
            if ($authType === 'session token' && isset($this->authTokens[$connection->id])) {
                // Get the configured session token header name from credential params
                $params = $connection->credential->getParamsArray();
                $sessionTokenHeader = $params['token_header'] ?? 'X-Auth-Token';

                Log::debug("Using cached session token for {$connection->device->hostname}", [
                    'connection_id' => $connection->id,
                    'header_name' => $sessionTokenHeader,
                    'token_preview' => substr($this->authTokens[$connection->id], 0, 20) . '...',
                    'endpoint' => $endpoint->path,
                ]);

                $request = $request->withHeaders([
                    $sessionTokenHeader => $this->authTokens[$connection->id],
                ]);
            } else {
                // Other auth types
                Log::debug("Using non-session-token auth for {$connection->device->hostname}", [
                    'auth_type' => $authType,
                    'has_cached_token' => isset($this->authTokens[$connection->id]),
                    'endpoint' => $endpoint->path,
                ]);
                $request = $this->applyAuthentication($request, $connection->credential);
            }
        }

        $response = $request->get($url);

        if (!$response->successful()) {
            // Log 404s as info (endpoint may not be available on all devices)
            if ($response->status() === 404) {
                Log::info("Endpoint not available (404): {$endpoint->path}", [
                    'device_id' => $connection->device_id,
                    'url' => $url,
                ]);
                return; // Skip this endpoint gracefully
            }

            throw new \Exception("HTTP {$response->status()} from {$url}");
        }

        $data = $response->json();

        // Check if response is null or empty
        if ($data === null) {
            Log::warning("API response was null/empty for {$endpoint->path}", [
                'device_id' => $connection->device_id,
                'endpoint' => $endpoint->path,
                'status' => $response->status(),
                'body' => substr($response->body(), 0, 200),
            ]);
            return;
        }

        // Get mappings - must be set on endpoint
        $mappings = $endpoint->template_response_mapping;

        if (empty($mappings) || !is_array($mappings)) {
            Log::warning("No template_response_mapping for endpoint {$endpoint->path}", [
                'device_id' => $connection->device_id,
                'endpoint_id' => $endpoint->id,
            ]);
            return;
        }

        // Process mappings by grouping them into complete entities
        $this->processMappings($connection, $endpoint, $mappings, $data);
    }

    /**
     * Process all mappings for an endpoint
     * Groups mappings by array items to preserve entity relationships
     */
    protected function processMappings(RestApiConnection $connection, $endpoint, array $mappings, array $data): void
    {
        // Check if we're dealing with array data ($.items[*].field pattern)
        $hasArrayMappings = false;
        foreach ($mappings as $apiField) {
            if (str_contains($apiField, '[*]')) {
                $hasArrayMappings = true;
                break;
            }
        }

        if ($hasArrayMappings) {
            // Process as array of entities
            $this->processArrayMappings($connection, $endpoint, $mappings, $data);
        } else {
            // Process as single entity (legacy behavior)
            foreach ($mappings as $tableField => $apiField) {
                try {
                    $this->processMapping($connection, $endpoint, $tableField, $apiField, $data);
                } catch (\Throwable $e) {
                    Log::warning("Failed to process mapping {$tableField} <= {$apiField} for {$endpoint->path}: {$e->getMessage()}", [
                        'device_id' => $connection->device_id,
                        'table_field' => $tableField,
                        'api_field' => $apiField,
                    ]);
                }
            }
        }
    }

    /**
     * Process array-based mappings where each item is a complete entity
     * Example: volumes, ports, hardware components
     */
    protected function processArrayMappings(RestApiConnection $connection, $endpoint, array $mappings, array $data): void
    {
        // Check if this is the transceiver/port-details endpoint
        if (str_contains($endpoint->path, 'port-details') || str_contains($endpoint->path, 'network-interfaces/port-details')) {
            $this->processTransceiverData($connection, $endpoint, $data);
            return;
        }

        // Extract the base array path (e.g., "$.items" from "$.items[*].field")
        $baseArrayPath = null;
        foreach ($mappings as $apiField) {
            if (preg_match('/^(\$\.[\w.]+)\[\*\]/', $apiField, $matches)) {
                $baseArrayPath = $matches[1];
                break;
            }
        }

        if (!$baseArrayPath) {
            return;
        }

        // Get the array of items
        $items = $this->extractJsonPath($data, $baseArrayPath);
        if (!is_array($items) || empty($items)) {
            return;
        }

        // Process each item as a complete entity
        foreach ($items as $item) {
            $entityData = [];
            $targetTable = null;

            // Extract all mapped fields for this item
            foreach ($mappings as $tableField => $apiField) {
                // Convert array pattern to single item pattern
                // "$.items[*].name" -> "$.name"
                $itemFieldPath = preg_replace('/^\$\.[\w.]+\[\*\]\./', '$.', $apiField);

                $value = $this->extractJsonPath($item, $itemFieldPath);
                if ($value === null) {
                    continue;
                }

                list($table, $field) = $this->parseTableField($tableField);

                if ($targetTable === null) {
                    $targetTable = $table;
                }

                $entityData[$field] = $value;
            }

            // Apply the complete entity
            if (!empty($entityData) && $targetTable) {
                $this->applyEntity($connection->device_id, $targetTable, $entityData, $endpoint);
            }
        }
    }

    /**
     * Special handler for transceiver/DOM data from port-details endpoint
     * Creates sensors for temperature, voltage, tx_power, rx_power, tx_bias
     */
    protected function processTransceiverData(RestApiConnection $connection, $endpoint, array $data): void
    {
        $items = $data['items'] ?? [];
        if (empty($items)) {
            return;
        }

        foreach ($items as $item) {
            $portName = $item['name'] ?? null;
            if (!$portName) {
                continue;
            }

            // Find the port in the database to get port_id
            $port = DB::table('ports')
                ->where('device_id', $connection->device_id)
                ->where(function ($query) use ($portName) {
                    $query->where('ifDescr', $portName)
                          ->orWhere('ifName', $portName);
                })
                ->first();

            if (!$port) {
                Log::debug("Port not found for transceiver data: {$portName}");
                continue;
            }

            // Process each sensor type
            $this->processTransceiverSensors($connection->device_id, $port, $portName, 'temperature', $item['temperature'] ?? []);
            $this->processTransceiverSensors($connection->device_id, $port, $portName, 'voltage', $item['voltage'] ?? []);
            $this->processTransceiverSensors($connection->device_id, $port, $portName, 'tx_bias', $item['tx_bias'] ?? []);
            $this->processTransceiverSensors($connection->device_id, $port, $portName, 'tx_power', $item['tx_power'] ?? []);
            $this->processTransceiverSensors($connection->device_id, $port, $portName, 'rx_power', $item['rx_power'] ?? []);

            // Store static transceiver info as metrics
            if (isset($item['static'])) {
                $static = $item['static'];
                $staticFields = [
                    'vendor_name', 'vendor_part_number', 'vendor_serial_number',
                    'connector_type', 'wavelength', 'link_length'
                ];

                foreach ($staticFields as $field) {
                    if (isset($static[$field])) {
                        RestApiMetric::updateOrCreate(
                            [
                                'device_id' => $connection->device_id,
                                'metric_key' => $portName . '.transceiver.' . $field,
                                'endpoint_name' => $endpoint->path,
                            ],
                            [
                                'metric_value' => (string) $static[$field],
                                'last_updated' => now(),
                            ]
                        );
                    }
                }
            }
        }
    }

    /**
     * Process individual transceiver sensor measurements
     */
    protected function processTransceiverSensors(int $deviceId, $port, string $portName, string $sensorType, array $measurements): void
    {
        foreach ($measurements as $measurement) {
            $channel = $measurement['channel'] ?? 0;
            $value = $measurement['measurement'] ?? null;
            $status = $measurement['status'] ?? 'unknown';

            if ($value === null) {
                continue;
            }

            // Map sensor types to LibreNMS sensor classes
            $sensorClass = match ($sensorType) {
                'temperature' => 'temperature',
                'voltage' => 'voltage',
                'tx_power', 'rx_power' => 'dbm',
                'tx_bias' => 'current',
                default => 'count',
            };

            $sensorDescr = $portName . ' ' . strtoupper(str_replace('_', ' ', $sensorType));
            if (count($measurements) > 1) {
                $sensorDescr .= " Ch{$channel}";
            }

            // Create or update sensor
            DB::table('sensors')->updateOrInsert(
                [
                    'device_id' => $deviceId,
                    'sensor_class' => $sensorClass,
                    'sensor_type' => 'rest-api',
                    'sensor_descr' => $sensorDescr,
                ],
                [
                    'sensor_index' => $port->port_id . $channel,
                    'sensor_current' => $value,
                    'entPhysicalIndex' => $port->port_id,
                    'lastupdate' => now(),
                ]
            );
        }
    }

    /**
     * Apply authentication to HTTP request (for non-Pure Storage APIs)
     */
    protected function applyAuthentication($request, $credential)
    {
        $authType = $credential->authenticationType;

        if (!$authType) {
            return $request;
        }

        $params = $credential->getParamsArray();

        return match (strtolower($authType->name)) {
            'basic' => $request->withBasicAuth($params['username'] ?? '', $params['password'] ?? ''),
            'bearer' => $request->withToken($params['token'] ?? ''),
            'api_key' => $request->withHeaders([
                ($params['header_name'] ?? 'X-API-Key') => ($params['api_key'] ?? ''),
            ]),
            'oauth2' => $request->withToken($params['access_token'] ?? ''),
            'custom' => $request->withHeaders($params),
            default => $request,
        };
    }

    /**
     * Process a single mapping
     * tableField format: "table.field" or "table.field[index]"
     * apiField format: "$.path.to.field" or "$.items[*].field"
     */
    protected function processMapping(RestApiConnection $connection, $endpoint, string $tableField, string $apiField, array $data): void
    {
        // Extract value from API response using JSONPath
        $value = $this->extractJsonPath($data, $apiField);

        if ($value === null || (is_array($value) && empty($value))) {
            return;
        }

        // Parse table.field notation
        list($table, $field) = $this->parseTableField($tableField);

        // If value is an array (from wildcard extraction), process each item
        if (is_array($value)) {
            foreach ($value as $item) {
                $this->applyValue($connection->device_id, $table, $field, $item);
            }
        } else {
            $this->applyValue($connection->device_id, $table, $field, $value);
        }
    }

    /**
     * Parse table.field notation
     * Examples: "devices.hostname", "storage.storage_descr", "ports.ifName"
     * If no table prefix is provided, defaults to 'metrics' table (rest_api_metrics)
     */
    private function parseTableField(string $tableField): array
    {
        $parts = explode('.', $tableField, 2);

        if (count($parts) !== 2) {
            // No table prefix - treat as a metric key and use 'metrics' as the table
            return ['metrics', $tableField];
        }

        return [$parts[0], $parts[1]];
    }

    /**
     * Apply a complete entity (row) with all its fields
     * Uses intelligent matching based on entity identifiers
     */
    protected function applyEntity(int $deviceId, string $table, array $entityData, $endpoint): void
    {
        try {
            switch ($table) {
                case 'devices':
                    // Filter to only valid devices columns
                    $validColumns = [
                        'hostname', 'sysName', 'ip', 'community', 'authlevel', 'authname', 'authpass',
                        'authalgo', 'cryptopass', 'cryptoalgo', 'snmpver', 'port', 'transport', 'timeout',
                        'retries', 'snmp_disable', 'bgpLocalAs', 'sysObjectID', 'sysDescr', 'sysContact',
                        'version', 'hardware', 'features', 'location_id', 'os', 'status', 'status_reason',
                        'ignore', 'disabled', 'uptime', 'agent_uptime', 'last_polled', 'last_poll_attempted',
                        'last_polled_timetaken', 'last_discovered_timetaken', 'last_discovered', 'last_ping',
                        'last_ping_timetaken', 'purpose', 'type', 'serial', 'icon', 'poller_group',
                        'override_sysLocation', 'notes', 'port_association_mode', 'max_depth'
                    ];

                    $filteredData = array_intersect_key($entityData, array_flip($validColumns));

                    // Store non-standard fields as metrics
                    $extraFields = array_diff_key($entityData, array_flip($validColumns));
                    if (!empty($extraFields)) {
                        foreach ($extraFields as $key => $value) {
                            RestApiMetric::updateOrCreate(
                                [
                                    'device_id' => $deviceId,
                                    'metric_key' => 'device.' . $key,
                                    'endpoint_name' => $endpoint->path,
                                ],
                                [
                                    'metric_value' => (string) $value,
                                    'last_updated' => now(),
                                ]
                            );
                        }
                    }

                    // Update the device record
                    if (!empty($filteredData)) {
                        DB::table('devices')->where('device_id', $deviceId)->update($filteredData);
                    }
                    break;

                case 'storage':
                    // Use 'name' field as the unique identifier for storage volumes
                    $identifier = $entityData['storage_descr'] ?? $entityData['name'] ?? null;
                    if (!$identifier) {
                        Log::warning("No identifier found for storage entity", ['entity_data' => $entityData]);
                        return;
                    }

                    // Filter to only valid storage columns
                    $validColumns = [
                        'storage_mib', 'storage_index', 'storage_type', 'storage_descr', 'storage_size',
                        'storage_units', 'storage_used', 'storage_free', 'storage_perc', 'storage_perc_warn',
                        'storage_deleted'
                    ];

                    $filteredData = array_intersect_key($entityData, array_flip($validColumns));
                    $filteredData['storage_descr'] = $identifier;

                    // Store non-standard fields as metrics
                    $extraFields = array_diff_key($entityData, array_flip($validColumns));
                    if (!empty($extraFields)) {
                        foreach ($extraFields as $key => $value) {
                            RestApiMetric::updateOrCreate(
                                [
                                    'device_id' => $deviceId,
                                    'metric_key' => $identifier . '.' . $key,
                                    'endpoint_name' => $endpoint->path,
                                ],
                                [
                                    'metric_value' => (string) $value,
                                    'last_updated' => now(),
                                ]
                            );
                        }
                    }

                    DB::table('storage')->updateOrInsert(
                        [
                            'device_id' => $deviceId,
                            'storage_descr' => $identifier,
                        ],
                        $filteredData
                    );
                    break;

                case 'ports':
                    // Use 'name' or 'ifName' as the unique identifier for ports
                    $identifier = $entityData['ifName'] ?? $entityData['name'] ?? $entityData['ifDescr'] ?? null;
                    if (!$identifier) {
                        Log::warning("No identifier found for port entity", ['entity_data' => $entityData]);
                        return;
                    }

                    // Set required LibreNMS port fields with sensible defaults
                    if (!isset($entityData['ifDescr'])) {
                        $entityData['ifDescr'] = $identifier;
                    }
                    if (!isset($entityData['ifName'])) {
                        $entityData['ifName'] = $identifier;
                    }
                    if (!isset($entityData['ifType'])) {
                        $entityData['ifType'] = 'ethernetCsmacd';
                    }
                    if (!isset($entityData['ifOperStatus'])) {
                        $entityData['ifOperStatus'] = 'up'; // Default to up for REST-discovered ports
                    }
                    if (!isset($entityData['ifAdminStatus'])) {
                        $entityData['ifAdminStatus'] = 'up';
                    }
                    // Set disabled flag based on operational status (if not already set)
                    if (!isset($entityData['disabled'])) {
                        $operStatus = $entityData['ifOperStatus'] ?? 'up';
                        $entityData['disabled'] = in_array($operStatus, ['down', 'lowerLayerDown', 'notPresent']) ? 1 : 0;
                    }

                    // Filter to only valid ports columns - commonly used ones
                    $validColumns = [
                        'ifIndex', 'ifName', 'ifDescr', 'ifAlias', 'ifType', 'ifOperStatus', 'ifAdminStatus',
                        'ifSpeed', 'ifHighSpeed', 'ifMtu', 'ifPhysAddress', 'ifLastChange', 'ifVlan', 'ifTrunk',
                        'disabled', 'deleted', 'ignore', 'pagpOperationMode', 'pagpPortState', 'pagpPartnerDeviceId',
                        'pagpPartnerLearnMethod', 'pagpPartnerIfIndex', 'pagpPartnerGroupIfIndex', 'pagpPartnerDeviceName',
                        'pagpEthcOperationMode', 'pagpDeviceId', 'pagpGroupIfIndex'
                    ];

                    $filteredData = array_intersect_key($entityData, array_flip($validColumns));

                    // Store non-standard fields as metrics
                    $extraFields = array_diff_key($entityData, array_flip($validColumns));
                    if (!empty($extraFields)) {
                        foreach ($extraFields as $key => $value) {
                            RestApiMetric::updateOrCreate(
                                [
                                    'device_id' => $deviceId,
                                    'metric_key' => $identifier . '.' . $key,
                                    'endpoint_name' => $endpoint->path,
                                ],
                                [
                                    'metric_value' => (string) $value,
                                    'last_updated' => now(),
                                ]
                            );
                        }
                    }

                    DB::table('ports')->updateOrInsert(
                        [
                            'device_id' => $deviceId,
                            'ifDescr' => $filteredData['ifDescr'],
                        ],
                        $filteredData
                    );
                    break;

                case 'entPhysical':
                case 'hardware':
                    // Use 'name' as the unique identifier for hardware components
                    $identifier = $entityData['entPhysicalName'] ?? $entityData['name'] ?? null;
                    if (!$identifier) {
                        Log::warning("No identifier found for entPhysical entity", ['entity_data' => $entityData]);
                        return;
                    }

                    // SMART ROUTING: If this is an ethernet port, route to ports table instead
                    $class = $entityData['entPhysicalClass'] ?? null;
                    if (in_array($class, ['eth_port', 'port', 'ethernet'])) {
                        // Convert to ports format and route to ports table
                        $status = $entityData['status'] ?? $entityData['sensor_value'] ?? 'up';
                        $operStatus = $this->mapStatusToIfOperStatus($status);

                        $portData = [
                            'ifDescr' => $identifier,
                            'ifName' => $identifier,
                            'ifAlias' => $entityData['entPhysicalDescr'] ?? null,
                            'ifType' => 'ethernetCsmacd',
                            'ifOperStatus' => $operStatus,
                            'ifAdminStatus' => 'up',
                            // Set disabled flag based on operational status
                            'disabled' => in_array($operStatus, ['down', 'lowerLayerDown', 'notPresent']) ? 1 : 0,
                        ];

                        // Remove nulls
                        $portData = array_filter($portData, fn($v) => $v !== null);

                        // Add any extra fields as port data
                        foreach ($entityData as $key => $value) {
                            if (!isset($portData[$key]) && $key !== 'entPhysicalClass' && $key !== 'entPhysicalName') {
                                $portData[$key] = $value;
                            }
                        }

                        // Recursively call with ports table
                        $this->applyEntity($deviceId, 'ports', $portData, $endpoint);
                        return;
                    }

                    // Filter to only valid entPhysical columns
                    $validColumns = [
                        'entPhysicalIndex', 'entPhysicalDescr', 'entPhysicalClass', 'entPhysicalName',
                        'entPhysicalHardwareRev', 'entPhysicalFirmwareRev', 'entPhysicalSoftwareRev',
                        'entPhysicalAlias', 'entPhysicalAssetID', 'entPhysicalIsFRU', 'entPhysicalModelName',
                        'entPhysicalVendorType', 'entPhysicalSerialNum', 'entPhysicalContainedIn',
                        'entPhysicalParentRelPos', 'entPhysicalMfgName', 'ifIndex'
                    ];

                    $filteredData = array_intersect_key($entityData, array_flip($validColumns));
                    $filteredData['entPhysicalName'] = $identifier;

                    // Store non-standard fields (like sensor_value, status) as metrics
                    $extraFields = array_diff_key($entityData, array_flip($validColumns));
                    if (!empty($extraFields)) {
                        foreach ($extraFields as $key => $value) {
                            RestApiMetric::updateOrCreate(
                                [
                                    'device_id' => $deviceId,
                                    'metric_key' => $identifier . '.' . $key, // e.g., "CT0.PWR1.sensor_value"
                                    'endpoint_name' => $endpoint->path,
                                ],
                                [
                                    'metric_value' => (string) $value,
                                    'last_updated' => now(),
                                ]
                            );
                        }
                    }

                    DB::table('entPhysical')->updateOrInsert(
                        [
                            'device_id' => $deviceId,
                            'entPhysicalName' => $identifier,
                        ],
                        $filteredData
                    );
                    break;

                case 'sensors':
                    // Use 'name' or 'sensor_descr' as the unique identifier
                    $identifier = $entityData['sensor_descr'] ?? $entityData['name'] ?? null;
                    if (!$identifier) {
                        Log::warning("No identifier found for sensor entity", ['entity_data' => $entityData]);
                        return;
                    }

                    // Filter to only valid sensors columns
                    $validColumns = [
                        'sensor_deleted', 'sensor_class', 'poller_type', 'sensor_oid', 'sensor_index',
                        'sensor_type', 'sensor_descr', 'group', 'sensor_divisor', 'sensor_multiplier',
                        'sensor_current', 'sensor_limit', 'sensor_limit_warn', 'sensor_limit_low',
                        'sensor_limit_low_warn', 'sensor_alert', 'sensor_custom', 'entPhysicalIndex',
                        'entPhysicalIndex_measured', 'sensor_prev', 'user_func', 'state_name',
                        'sensor_info', 'lastupdate', 'sensor_polled'
                    ];

                    $filteredData = array_intersect_key($entityData, array_flip($validColumns));
                    $filteredData['sensor_descr'] = $identifier;

                    // Store non-standard fields as metrics
                    $extraFields = array_diff_key($entityData, array_flip($validColumns));
                    if (!empty($extraFields)) {
                        foreach ($extraFields as $key => $value) {
                            RestApiMetric::updateOrCreate(
                                [
                                    'device_id' => $deviceId,
                                    'metric_key' => $identifier . '.' . $key,
                                    'endpoint_name' => $endpoint->path,
                                ],
                                [
                                    'metric_value' => (string) $value,
                                    'last_updated' => now(),
                                ]
                            );
                        }
                    }

                    DB::table('sensors')->updateOrInsert(
                        [
                            'device_id' => $deviceId,
                            'sensor_descr' => $identifier,
                        ],
                        $filteredData
                    );
                    break;

                case 'metrics':
                default:
                    // Store as custom metrics - each field becomes a separate metric
                    foreach ($entityData as $key => $value) {
                        RestApiMetric::updateOrCreate(
                            [
                                'device_id' => $deviceId,
                                'metric_key' => $key,
                                'endpoint_name' => $endpoint->path,
                            ],
                            [
                                'metric_value' => (string) $value,
                                'last_updated' => now(),
                            ]
                        );
                    }
            }
        } catch (\Illuminate\Database\QueryException $e) {
            // Log database errors but don't fail the entire poll
            if (str_contains($e->getMessage(), 'Column not found') || str_contains($e->getMessage(), 'Unknown column')) {
                Log::warning("Database schema mismatch for table '{$table}', storing as metrics instead", [
                    'device_id' => $deviceId,
                    'table' => $table,
                    'entity_data' => $entityData,
                    'error' => $e->getMessage(),
                ]);

                // Fallback to metrics
                foreach ($entityData as $key => $value) {
                    try {
                        RestApiMetric::updateOrCreate(
                            [
                                'device_id' => $deviceId,
                                'metric_key' => $key,
                                'endpoint_name' => $endpoint->path,
                            ],
                            [
                                'metric_value' => (string) $value,
                                'last_updated' => now(),
                            ]
                        );
                    } catch (\Throwable $fallbackError) {
                        Log::error("Failed to store metric as fallback", [
                            'key' => $key,
                            'error' => $fallbackError->getMessage(),
                        ]);
                    }
                }
            } else {
                throw $e;
            }
        }
    }

    protected function applyValue($deviceId, $table, $column, $value): void
    {
        // Type conversions
        if (is_numeric($value) && !is_string($value)) {
            $value = (int) $value;
        } elseif (is_bool($value)) {
            $value = $value ? 1 : 0;
        } else {
            $value = (string) $value;
        }

        try {
            switch ($table) {
                case 'devices':
                    DB::table('devices')->where('device_id', $deviceId)->update([$column => $value]);
                    break;

                case 'storage':
                    DB::table('storage')->updateOrInsert(
                        ['device_id' => $deviceId, 'storage_descr' => 'REST Import'],
                        [$column => $value]
                    );
                    break;

                case 'ports':
                    DB::table('ports')->updateOrInsert(
                        ['device_id' => $deviceId, 'ifDescr' => 'REST Interface'],
                        [$column => $value]
                    );
                    break;

                case 'entPhysical':
                    DB::table('entPhysical')->updateOrInsert(
                        ['device_id' => $deviceId, 'entPhysicalName' => 'REST Component'],
                        [$column => $value]
                    );
                    break;

                case 'sensors':
                    DB::table('sensors')->updateOrInsert(
                        ['device_id' => $deviceId, 'sensor_descr' => 'REST Sensor'],
                        [$column => $value]
                    );
                    break;

                case 'metrics':
                default:
                    // Default to rest_api_metrics table for custom metrics
                    RestApiMetric::updateOrCreate(
                        [
                            'device_id' => $deviceId,
                            'metric_key' => $column,
                            'endpoint_name' => $table,
                        ],
                        [
                            'metric_value' => (string) $value,
                            'last_updated' => now(),
                        ]
                    );
            }
        } catch (\Illuminate\Database\QueryException $e) {
            // Log database errors (e.g., column not found) but don't fail the entire poll
            if (str_contains($e->getMessage(), 'Column not found') || str_contains($e->getMessage(), 'Unknown column')) {
                Log::warning("Column '{$column}' does not exist in table '{$table}', storing as metric instead", [
                    'device_id' => $deviceId,
                    'table' => $table,
                    'column' => $column,
                    'value' => $value,
                ]);

                // Fallback to metrics table
                RestApiMetric::updateOrCreate(
                    [
                        'device_id' => $deviceId,
                        'metric_key' => $column,
                        'endpoint_name' => $table,
                    ],
                    [
                        'metric_value' => (string) $value,
                        'last_updated' => now(),
                    ]
                );
            } else {
                throw $e;
            }
        }
    }

    /**
     * Map API status values to LibreNMS ifOperStatus
     */
    protected function mapStatusToIfOperStatus(string $status): string
    {
        return match (strtolower($status)) {
            'up', 'ok', 'healthy', 'active', 'online', 'ready' => 'up',
            'down', 'failed', 'error', 'offline' => 'down',
            'disabled', 'not_installed', 'unused' => 'lowerLayerDown',
            'testing', 'initializing' => 'testing',
            default => 'unknown',
        };
    }

    /**
     * Integrates with native LibreNMS discovery to detect REST-enabled devices.
     */
    public static function discoverRestDevices(): void
    {
        $devices = DB::table('devices')->pluck('device_id');
        foreach ($devices as $deviceId) {
            $hasConnections = DB::table('rest_api_connections')
                ->where('device_id', $deviceId)
                ->where('enabled', true)
                ->exists();

            if ($hasConnections) {
                Log::info("Discovered REST API connection for device ID {$deviceId}.");
            }
        }
    }

    /**
     * Hook for LibreNMS polling engine.
     * If device has REST API endpoints, poll them automatically.
     */
    public static function pollViaLibreNMS($device): void
    {
        $connections = RestApiConnection::where('device_id', $device->device_id)
            ->where('enabled', true)
            ->with(['endpoints', 'credential'])
            ->get();

        if ($connections->isEmpty()) {
            return; // No REST API connections, skip
        }

        $poller = new static();
        foreach ($connections as $connection) {
            $poller->pollDeviceConnection($connection);
        }
    }
}
