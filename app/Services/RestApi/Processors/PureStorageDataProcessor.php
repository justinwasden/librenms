<?php

namespace App\Services\RestApi\Processors;

use App\Models\RestApiConnection;
use App\Models\RestApiEndpoint;
use App\Services\RestApi\Contracts\VendorDataProcessorInterface;
use App\Services\RestApi\DataPersistence;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * PureStorage FlashArray REST API Data Processor
 *
 * Handles PureStorage-specific endpoints and data structures:
 * - /api/2.x/network-interfaces - Port list with full details
 * - /api/2.x/network-interfaces/port-details - Transceiver/DOM data
 * - /api/2.x/network-interfaces/performance - Port performance metrics
 *
 * PureStorage API specifics:
 * - Uses session token authentication (handled by RestApiPollerService)
 * - API version in URL path (/api/2.26/)
 * - Nested data structures (eth.address, eth.vlan, etc.)
 * - Items array with consistent structure
 */
class PureStorageDataProcessor implements VendorDataProcessorInterface
{
    public function canProcess(RestApiEndpoint $endpoint): bool
    {
        // Check if endpoint is PureStorage API endpoint
        // PureStorage uses /api/2.x/ path structure
        // We identify by API version pattern and common endpoint names

        // Match PureStorage API version pattern
        if (preg_match('#/api/\d+\.\d+/#', $endpoint->path)) {
            return true;
        }

        // Also match characteristic PureStorage endpoints
        $pureStorageEndpoints = [
            'arrays', 'volumes', 'drives', 'controllers', 'hardware',
            'network-interfaces', 'port-details', 'subnets', 'array-connections',
            'performance', 'alerts', 'space'
        ];

        foreach ($pureStorageEndpoints as $psEndpoint) {
            if (str_contains($endpoint->path, $psEndpoint)) {
                return true;
            }
        }

        return false;
    }

    public function process(RestApiConnection $connection, RestApiEndpoint $endpoint, array $data): void
    {
        // Route to specific handler based on endpoint type
        if (str_contains($endpoint->path, 'port-details')) {
            // Transceiver/DOM data endpoint
            $this->processTransceiverData($connection, $endpoint, $data);
        } elseif (str_contains($endpoint->path, 'performance') && str_contains($endpoint->path, 'network-interfaces')) {
            // Port performance/statistics endpoint
            $this->processPortPerformance($connection, $endpoint, $data);
        } elseif (preg_match('#/network-interfaces(?:\?|$)#', $endpoint->path)) {
            // Main network interfaces endpoint (port list with details)
            $this->processNetworkInterfaces($connection, $endpoint, $data);
        } else {
            // For other PureStorage endpoints, use generic mapping if available
            // Otherwise, just log that we handled it (to suppress warnings)
            if (!empty($endpoint->template_response_mapping)) {
                // Delegate to GenericDataProcessor for standard mapping
                $genericProcessor = new \App\Services\RestApi\Processors\GenericDataProcessor();
                $genericProcessor->process($connection, $endpoint, $data);
            } else {
                Log::debug("PureStorage endpoint has no mappings, skipping: {$endpoint->path}", [
                    'device_id' => $connection->device_id,
                    'endpoint' => $endpoint->path,
                ]);
            }
        }
    }

    public function getPriority(): int
    {
        return 50; // Run before generic processor
    }

    public function getVendorName(): string
    {
        return 'purestorage';
    }

    public function getDescription(): string
    {
        return 'PureStorage FlashArray REST API processor for network interfaces, transceivers, and performance metrics';
    }

    /**
     * Process main network-interfaces endpoint
     * Creates/updates ports with full details (speed, MAC, MTU, etc.)
     */
    protected function processNetworkInterfaces(RestApiConnection $connection, RestApiEndpoint $endpoint, array $data): void
    {
        $items = $data['items'] ?? [];
        if (empty($items)) {
            Log::debug("No items in network-interfaces response", [
                'device_id' => $connection->device_id,
                'endpoint' => $endpoint->path,
            ]);
            return;
        }

        foreach ($items as $item) {
            $portName = $item['name'] ?? null;
            if (!$portName) {
                continue;
            }

            // Build port data from API response
            $portData = [
                'ifDescr' => $portName,
                'ifName' => $portName,
                'port_descr_type' => 'rest-api', // Mark as REST API port
            ];

            // Map fields from the API
            if (isset($item['services'][0])) {
                $portData['ifAlias'] = $item['services'][0]; // First service as alias
            }

            if (isset($item['interface_type'])) {
                $portData['ifType'] = $item['interface_type'] === 'eth' ? 'ethernetCsmacd' : $item['interface_type'];
            }

            if (isset($item['speed'])) {
                $portData['ifSpeed'] = $item['speed'];
            }

            if (isset($item['enabled'])) {
                // Convert boolean to ifOperStatus/ifAdminStatus
                $portData['ifOperStatus'] = $item['enabled'] ? 'up' : 'down';
                $portData['ifAdminStatus'] = $item['enabled'] ? 'up' : 'down';
                $portData['disabled'] = $item['enabled'] ? 0 : 1;
            }

            // Ethernet-specific fields
            if (isset($item['eth'])) {
                $eth = $item['eth'];
                if (isset($eth['mac_address'])) {
                    $portData['ifPhysAddress'] = $eth['mac_address'];
                }
                if (isset($eth['mtu'])) {
                    $portData['ifMtu'] = $eth['mtu'];
                }
                if (isset($eth['address'])) {
                    // Use as ifAlias if not already set
                    if (!isset($portData['ifAlias']) || empty($portData['ifAlias'])) {
                        $portData['ifAlias'] = $eth['address'];
                    }
                }
                if (isset($eth['vlan'])) {
                    $portData['ifVlan'] = $eth['vlan'];
                }
            }

            // Use DataPersistence to store the port
            DataPersistence::applyEntity($connection->device_id, 'ports', $portData, $endpoint);

            Log::debug("Processed PureStorage network interface: {$portName}", [
                'device_id' => $connection->device_id,
                'port_data' => $portData,
            ]);
        }
    }

    /**
     * Process transceiver/DOM data from port-details endpoint
     * Creates sensors for temperature, voltage, tx_power, rx_power, tx_bias
     */
    protected function processTransceiverData(RestApiConnection $connection, RestApiEndpoint $endpoint, array $data): void
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
                Log::debug("Port not found for transceiver data: {$portName}", [
                    'device_id' => $connection->device_id,
                ]);
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
                        $entityData = [
                            $portName . '.transceiver.' . $field => (string) $static[$field],
                        ];
                        DataPersistence::applyEntity($connection->device_id, 'metrics', $entityData, $endpoint);
                    }
                }
            }
        }
    }

    /**
     * Process port performance/statistics data
     * Updates ports table with traffic statistics
     */
    protected function processPortPerformance(RestApiConnection $connection, RestApiEndpoint $endpoint, array $data): void
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

            // Extract performance metrics from the item
            $performanceData = [];

            // Map common performance fields
            if (isset($item['eth'])) {
                $eth = $item['eth'];
                $performanceData['ifInOctets'] = $eth['received_bytes_per_sec'] ?? null;
                $performanceData['ifOutOctets'] = $eth['transmitted_bytes_per_sec'] ?? null;
                $performanceData['ifInUcastPkts'] = $eth['received_packets_per_sec'] ?? null;
                $performanceData['ifOutUcastPkts'] = $eth['transmitted_packets_per_sec'] ?? null;
                $performanceData['ifInErrors'] = $eth['received_errors_per_sec'] ?? null;
                $performanceData['ifOutErrors'] = $eth['transmitted_errors_per_sec'] ?? null;
            }

            // Remove nulls
            $performanceData = array_filter($performanceData, fn($v) => $v !== null);

            if (empty($performanceData)) {
                continue;
            }

            // Update the port with performance data
            DB::table('ports')
                ->where('device_id', $connection->device_id)
                ->where(function ($query) use ($portName) {
                    $query->where('ifDescr', $portName)
                          ->orWhere('ifName', $portName);
                })
                ->update($performanceData);

            Log::debug("Updated PureStorage port performance: {$portName}", [
                'device_id' => $connection->device_id,
                'performance_data' => $performanceData,
            ]);
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

            // Skip zero values for power, voltage, and bias sensors (indicates no transceiver/inactive)
            $valueNumeric = is_numeric($value) ? (float)$value : 0;
            if ($valueNumeric == 0 && in_array($sensorType, ['tx_power', 'rx_power', 'voltage', 'tx_bias'])) {
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
}
