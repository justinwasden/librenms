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
                
                $authType = strtolower($connection->credential->authenticationType->name ?? '');
                Log::debug("Device {$device->device_id}: Auth type: {$authType}");
                
                // Get auth headers using CredentialHelper
                $authHeaders = CredentialHelper::getAuthHeaderFromModel($connection->credential);
                $headers = array_merge($headers, $authHeaders);
                
                if (!empty($authHeaders)) {
                    Log::debug("Device {$device->device_id}: Added " . count($authHeaders) . " auth header(s)");
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
