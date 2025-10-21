<?php

namespace App\Pollers;

use App\Models\Device;
use App\Models\RestApiConnection;
use App\RestApi\Services\MapperSelectionService;
use App\RestApi\Credentials\CredentialHelper;
use App\RestApi\Utilities\JsonPathExtractor;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class RestApiPoller
{
    protected Client $client;
    protected array $sessionTokens = [];

    public function __construct()
    {
        $this->client = new Client([
            'timeout' => 30,
            'verify' => false,
        ]);
    }

    public function poll(Device $device)
    {
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
        $targetTable = $mapper->getTargetTableForEndpoint($endpoint->path);
        $items = $response['items'] ?? [$response];

        foreach ($items as $item) {
            foreach ($mappings as $targetField => $jsonPath) {
                // Extract value from API response using JSONPath
                $value = JsonPathExtractor::extract($item, $jsonPath);

                if ($value === null) {
                    continue;
                }

                // Transform value (data type conversion, calculations, etc.)
                $value = $mapper->transformValue($targetField, $value);

                // Store to database
                $this->storeValue($device, $targetTable, $targetField, $value, $endpoint->path);
            }
        }
    }

    private function storeValue(Device $device, $table, $field, $value, $endpoint)
    {
        // TODO: Store to appropriate table based on $table
        // Tables: devices, ports, storage, sensors, links, custom
        // Handle different schema for each table type
        // Update or insert based on identifiers from mapper
    }
}
