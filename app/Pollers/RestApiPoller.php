<?php

namespace App\Pollers;

use App\Models\RestApiDeviceTemplate;
use App\RestApi\Services\MapperSelectionService;
use App\RestApi\Utilities\JsonPathExtractor;
use Illuminate\Support\Facades\Log;

class RestApiPoller
{
    public function poll($device)
    {
        $deviceTemplate = $device->restApiTemplate;
        
        if (!$deviceTemplate) {
            Log::info("Device {$device->id}: No REST API template configured");
            return;
        }

        // SELECT MAPPER
        $mapperResult = MapperSelectionService::selectMapper($deviceTemplate);
        $mapper = $mapperResult['mapper'];
        
        Log::info("Device {$device->id}: Using mapper {$mapperResult['mapper_name']} ({$mapperResult['source']})");

        // POLL ENDPOINTS
        foreach ($deviceTemplate->template->endpoints as $endpoint) {
            $this->pollEndpoint($device, $deviceTemplate, $endpoint, $mapper);
        }
    }

    private function pollEndpoint($device, $deviceTemplate, $endpoint, $mapper)
    {
        // Get mappings for this endpoint
        $mappings = $mapper->getMappingsForEndpoint($endpoint->path);
        
        if (empty($mappings)) {
            Log::warning("Device {$device->id}: No mappings for endpoint {$endpoint->path}");
            return;
        }

        try {
            // Fetch from API
            $response = $this->fetchEndpoint($device, $deviceTemplate, $endpoint);
            
            // Extract and store data
            $this->processResponse($device, $endpoint, $mapper, $mappings, $response);
            
            Log::info("Device {$device->id}: Successfully polled {$endpoint->path}");
        } catch (\Exception $e) {
            Log::error("Device {$device->id}: Error polling {$endpoint->path}: {$e->getMessage()}");
        }
    }

    private function fetchEndpoint($device, $deviceTemplate, $endpoint)
    {
        // TODO: Implement HTTP client to fetch from API
        // Use credential auth, handle pagination, etc.
        return [];
    }

    private function processResponse($device, $endpoint, $mapper, $mappings, $response)
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

    private function storeValue($device, $table, $field, $value, $endpoint)
    {
        // TODO: Store to appropriate table based on $table
        // Handle devices, ports, storage, sensors, links, etc.
    }
}
