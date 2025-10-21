<?php

namespace App\Pollers;

use App\Models\Device;
use App\Models\RestApiConnection;
use App\RestApi\Services\MapperSelectionService;
use App\RestApi\Utilities\JsonPathExtractor;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;

class RestApiPoller
{
    protected Client $client;

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
                $connection->credential->load('authenticationType');
                $authType = $connection->credential->authenticationType->name ?? null;
                
                Log::debug("Device {$device->device_id}: Auth type: {$authType}");
                
                if ($authType === 'Basic Auth') {
                    $username = $connection->credential->getParamValue('username');
                    $password = $connection->credential->getParamValue('password');
                    if ($username && $password) {
                        $headers['Authorization'] = 'Basic ' . base64_encode("{$username}:{$password}");
                        Log::debug("Device {$device->device_id}: Using Basic Auth");
                    }
                } elseif ($authType === 'Bearer Token') {
                    $token = $connection->credential->getParamValue('token');
                    if ($token) {
                        $headers['Authorization'] = "Bearer {$token}";
                        Log::debug("Device {$device->device_id}: Using Bearer Token");
                    }
                } elseif ($authType === 'API Key') {
                    $apiKey = $connection->credential->getParamValue('api_key') 
                        ?? $connection->credential->getParamValue('key');
                    if ($apiKey) {
                        $headers['X-API-Key'] = $apiKey;
                        Log::debug("Device {$device->device_id}: Using API Key");
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
