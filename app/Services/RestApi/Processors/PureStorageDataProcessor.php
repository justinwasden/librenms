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

            // Process IP address if available
            if (isset($item['eth']['address']) && !empty($item['eth']['address'])) {
                $this->processIpAddress(
                    $connection->device_id,
                    $portName,
                    $item['eth']['address'],
                    $item['eth']['netmask'] ?? null,
                    $item['eth']['gateway'] ?? null
                );
            }

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

            // Store static transceiver info in transceivers table
            if (isset($item['static'])) {
                $this->createTransceiverRecord($connection->device_id, $port, $item['static']);
            }

            // Process dynamic sensor measurements
            $this->processTransceiverSensors($connection->device_id, $port, $portName, 'temperature', $item['temperature'] ?? []);
            $this->processTransceiverSensors($connection->device_id, $port, $portName, 'voltage', $item['voltage'] ?? []);
            $this->processTransceiverSensors($connection->device_id, $port, $portName, 'tx_bias', $item['tx_bias'] ?? []);
            $this->processTransceiverSensors($connection->device_id, $port, $portName, 'tx_power', $item['tx_power'] ?? []);
            $this->processTransceiverSensors($connection->device_id, $port, $portName, 'rx_power', $item['rx_power'] ?? []);
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

    /**
     * Create or update transceiver record in transceivers table
     * Maps PureStorage static transceiver data to LibreNMS transceiver fields
     */
    protected function createTransceiverRecord(int $deviceId, $port, array $static): void
    {
        // Map PureStorage fields to LibreNMS transceiver table fields
        $transceiverData = [
            'device_id' => $deviceId,
            'port_id' => $port->port_id,
            'index' => (string) $port->port_id, // Use port_id as index
            'entity_physical_index' => $port->port_id,
            'vendor' => $static['vendor_name'] ?? null,
            'model' => $static['vendor_part_number'] ?? null,
            'serial' => $static['vendor_serial_number'] ?? null,
            'connector' => $static['connector_type'] ?? null,
            'wavelength' => isset($static['wavelength']) ? (int) $static['wavelength'] : null,
            'distance' => isset($static['link_length']) ? (int) $static['link_length'] : null,
            'type' => $static['type'] ?? null, // e.g., SFP, SFP+, QSFP, etc.
            'ddm' => 1, // PureStorage provides DOM/DDM data
            'updated_at' => now(),
        ];

        // Remove null values
        $transceiverData = array_filter($transceiverData, fn($v) => $v !== null);

        // Create or update transceiver record
        DB::table('transceivers')->updateOrInsert(
            [
                'device_id' => $deviceId,
                'port_id' => $port->port_id,
            ],
            $transceiverData
        );

        Log::debug("Created/updated transceiver record for port {$port->ifName}", [
            'device_id' => $deviceId,
            'port_id' => $port->port_id,
            'vendor' => $transceiverData['vendor'] ?? 'unknown',
        ]);
    }

    /**
     * Process IP address and store in ipv4_addresses table
     */
    protected function processIpAddress(int $deviceId, string $portName, string $address, ?string $netmask, ?string $gateway): void
    {
        // Find the port in the database
        $port = DB::table('ports')
            ->where('device_id', $deviceId)
            ->where(function ($query) use ($portName) {
                $query->where('ifDescr', $portName)
                      ->orWhere('ifName', $portName);
            })
            ->first();

        if (!$port) {
            Log::warning("Port not found for IP address: {$portName}", [
                'device_id' => $deviceId,
                'ip_address' => $address,
            ]);
            return;
        }

        // Calculate prefix length from netmask if provided
        $cidr = 24; // Default
        if ($netmask) {
            $cidr = $this->netmaskToCidr($netmask);
        }

        // Create or update IPv4 address record
        DB::table('ipv4_addresses')->updateOrInsert(
            [
                'port_id' => $port->port_id,
                'ipv4_address' => $address,
            ],
            [
                'ipv4_prefixlen' => $cidr,
                'context_name' => '',
            ]
        );

        Log::debug("Created/updated IP address for port {$portName}", [
            'device_id' => $deviceId,
            'port_id' => $port->port_id,
            'ip_address' => $address,
            'netmask' => $netmask,
            'cidr' => $cidr,
        ]);
    }

    /**
     * Convert netmask to CIDR prefix length
     */
    protected function netmaskToCidr(string $netmask): int
    {
        $long = ip2long($netmask);
        $base = ip2long('255.255.255.255');
        return 32 - log(($long ^ $base) + 1, 2);
    }
}
