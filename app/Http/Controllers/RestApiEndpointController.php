<?php
// app/Http/Controllers/RestApiEndpointController.php
// UPDATED - Shows how to integrate new mapping UI

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\RestApiConnection;
use App\Models\RestApiEndpoint;
use App\RestApi\Vendors\VendorMapperFactory;
use App\RestApi\Credentials\CredentialHelper;
use GuzzleHttp\Client;
use Illuminate\Http\Request;
use Log;

class RestApiEndpointController extends Controller
{
    protected VendorMapperFactory $vendorMapperFactory;

    public function __construct()
    {
        $this->vendorMapperFactory = new VendorMapperFactory();
    }

    /**
     * Show endpoint edit form with mapping UI
     * This replaces/extends the existing edit view
     *
     * GET /rest-api/endpoints/{id}/edit
     */
    public function edit(RestApiEndpoint $endpoint, Device $device = null)
    {
        // Get the connection
        $connection = $endpoint->connection;
        if (!$connection) {
            return redirect()->back()->withErrors('Connection not found');
        }

        // Get vendor mapper
        $vendorMapper = $this->vendorMapperFactory->getMapper($device ?? $connection->device, $endpoint);

        // Try to fetch API response for preview
        $apiResponse = null;
        $apiError = null;

        try {
            $apiResponse = $this->fetchApiResponse($connection, $endpoint);
        } catch (\Exception $e) {
            $apiError = $e->getMessage();
            Log::warning("Failed to fetch API response for preview: {$e->getMessage()}");
        }

        // Get recommendations if API response available
        $recommendations = [];
        if ($apiResponse && $vendorMapper) {
            $recommendations = $vendorMapper->getRecommendedMappings($apiResponse, $endpoint);
        }

        // Get existing mappings
        $existingMappings = $endpoint->metricMappings()
            ->pluck('librenms_field', 'api_field')
            ->toArray();

        return view('rest-api.endpoint-edit', [
            'endpoint' => $endpoint,
            'connection' => $connection,
            'device' => $device ?? $connection->device,
            'vendor' => $connection->device->os,
            'vendorMapper' => $vendorMapper,
            'apiResponse' => $apiResponse,
            'apiError' => $apiError,
            'recommendations' => $recommendations,
            'existingMappings' => $existingMappings,
        ]);
    }

    /**
     * Show mapping step in endpoint creation wizard
     * This would be called after endpoint configuration
     *
     * GET /rest-api/endpoints/{id}/mapping
     */
    public function showMapping(RestApiEndpoint $endpoint, Device $device = null)
    {
        $connection = $endpoint->connection;
        $device = $device ?? $connection->device;

        // Get vendor mapper
        $vendorMapper = $this->vendorMapperFactory->getMapper($device, $endpoint);

        // Fetch API response
        $apiResponse = null;
        $apiError = null;

        try {
            $apiResponse = $this->fetchApiResponse($connection, $endpoint);
        } catch (\Exception $e) {
            $apiError = $e->getMessage();
        }

        // Get recommendations
        $recommendations = [];
        if ($apiResponse && $vendorMapper) {
            $recommendations = $vendorMapper->getRecommendedMappings($apiResponse, $endpoint);
        }

        return view('rest-api.mapping-wizard', [
            'endpoint' => $endpoint,
            'connection' => $connection,
            'device' => $device,
            'vendor' => $device->os,
            'vendorMapper' => $vendorMapper,
            'apiResponse' => $apiResponse,
            'apiError' => $apiError,
            'recommendations' => $recommendations,
            'step' => 'mapping',
        ]);
    }

    /**
     * API endpoint: Get compatible fields for table/type combo
     * Called by field-mapper.blade.php JavaScript
     *
     * GET /api/rest-api/compatible-fields?table=ports&type=string
     */
    public function getCompatibleFields(Request $request)
    {
        $table = $request->query('table');
        $type = $request->query('type');

        if (!$table || !$type) {
            return response()->json(['error' => 'Missing table or type'], 400);
        }

        // For now, use generic field mapping
        // In future, could use VendorMapper if device specified
        $fieldsByTable = [
            'storage' => [
                'string' => ['storage_descr', 'storage_type', 'type'],
                'integer' => ['storage_size', 'storage_used', 'storage_free', 'storage_perc', 'storage_index'],
                'float' => ['storage_size', 'storage_used', 'storage_free', 'storage_perc'],
            ],
            'ports' => [
                'string' => ['ifName', 'ifDescr', 'ifOperStatus', 'ifAlias'],
                'integer' => ['ifSpeed', 'ifIndex', 'ifAdminStatus', 'ifType', 'ifMtu'],
                'boolean' => ['ifAdminStatus'],
                'float' => ['ifSpeed'],
            ],
            'sensors' => [
                'string' => ['sensor_descr', 'sensor_class'],
                'integer' => ['sensor_current'],
                'float' => ['sensor_current'],
            ],
            'devices' => [
                'string' => ['hostname', 'sysDescr', 'hardware', 'version'],
            ],
            'entPhysical' => [
                'string' => ['entPhysicalDescr', 'entPhysicalName', 'entPhysicalClass'],
                'integer' => ['entPhysicalIndex', 'entPhysicalContainedIn'],
            ],
        ];

        $fields = $fieldsByTable[$table][$type] ?? [];

        return response()->json([
            'table' => $table,
            'type' => $type,
            'fields' => $fields,
            'count' => count($fields),
        ]);
    }

    /**
     * API endpoint: Check mapping compatibility
     * Called by field-mapper.blade.php JavaScript
     *
     * GET /api/rest-api/check-compatibility?api_field=name&table=storage&type=string&endpoint_id=1
     */
    public function checkCompatibility(Request $request)
    {
        $apiField = $request->query('api_field');
        $table = $request->query('table');
        $apiType = $request->query('api_type');
        $field = $request->query('field');
        $endpointId = $request->query('endpoint_id');

        if (!$apiField || !$table) {
            return response()->json(['error' => 'Missing parameters'], 400);
        }

        // Get endpoint and device
        $endpoint = RestApiEndpoint::find($endpointId);
        $device = $endpoint->connection->device;

        // Get vendor mapper
        $vendorMapper = $this->vendorMapperFactory->getMapper($device, $endpoint);

        // Validate mapping
        $validation = $vendorMapper->validateMapping($apiField, null, $table, $field ?? '');

        return response()->json([
            'valid' => $validation['valid'],
            'reason' => $validation['reason'] ?? 'No validation available',
            'api_type' => $validation['api_type'] ?? $apiType,
            'expected_types' => $validation['expected_types'] ?? [],
            'warnings' => $validation['warnings'] ?? [],
        ]);
    }

    /**
     * Store/update endpoint mappings
     *
     * POST /rest-api/endpoints/{id}/mappings
     */
    public function storeMappings(Request $request, RestApiEndpoint $endpoint)
    {
        $mappings = $request->input('mappings', []);

        // Validate mappings
        $validated = [];
        foreach ($mappings as $apiField => $mapping) {
            if (!empty($mapping['table']) && !empty($mapping['field'])) {
                $validated[$apiField] = $mapping;
            }
        }

        if (empty($validated)) {
            return redirect()->back()->withErrors('No mappings configured');
        }

        // Store mappings (depends on your mapping storage structure)
        // This is example code - adjust based on your actual model
        foreach ($validated as $apiField => $mapping) {
            $endpoint->metricMappings()->updateOrCreate(
                ['api_field' => $apiField],
                [
                    'librenms_table' => $mapping['table'],
                    'librenms_field' => $mapping['field'],
                    'enabled' => true,
                ]
            );
        }

        return redirect()->route('rest-api.endpoints.show', $endpoint)
            ->with('success', 'Mappings updated successfully');
    }

    /**
     * Helper: Fetch API response for preview
     *
     * @param RestApiConnection $connection
     * @param RestApiEndpoint $endpoint
     * @return array
     * @throws \Exception
     */
    protected function fetchApiResponse(RestApiConnection $connection, RestApiEndpoint $endpoint): array
    {
        $client = new \GuzzleHttp\Client([
            'base_uri' => $connection->base_url,
            'timeout' => 15,
            'verify' => !$connection->disable_ssl_verify,
        ]);

        // Get auth headers
        $headers = $this->getAuthHeaders($connection, $client);

        // Make request
        $response = $client->request($endpoint->method ?? 'GET', $endpoint->path, [
            'headers' => $headers,
        ]);

        if ($response->getStatusCode() !== 200) {
            throw new \Exception("HTTP {$response->getStatusCode()}");
        }

        $body = (string)$response->getBody();
        $decoded = json_decode($body, true);

        if (!$decoded) {
            throw new \Exception("Invalid JSON response: " . json_last_error_msg());
        }

        return $decoded;
    }

    /**
     * Helper: Get auth headers
     *
     * @param RestApiConnection $connection
     * @param Client $client
     * @return array
     */
    protected function getAuthHeaders(RestApiConnection $connection, Client $client): array
    {
        $credential = $connection->credential;

        if (!$credential) {
            throw new \Exception("No credential configured for connection");
        }

        // Use CredentialHelper (existing code)
        return CredentialHelper::getAuthHeaderFromModel($credential);
    }
}
