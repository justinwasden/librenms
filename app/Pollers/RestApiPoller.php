<?php

namespace App\Pollers;

use App\ApiClients\DeviceApiClientFactory;
use App\Models\Device;
use LibreNMS\RRD\RrdDefinition;
use LibreNMS\Util\DeviceApiSettings;

class RestApiPoller
{
    protected Device $device;
    protected $apiClient;

    public function __construct(Device $device)
    {
        $this->device = $device;
    }

    public function poll(): void
    {
        // Check if REST API is enabled for this device
        if (!DeviceApiSettings::restEnabled($this->device)) {
            d_echo("REST API not enabled for device {$this->device->hostname}\n");
            return;
        }

        // Check circuit breaker
        if (DeviceApiSettings::shouldTripCircuitBreaker($this->device)) {
            d_echo("Circuit breaker tripped for device {$this->device->hostname} - too many errors\n");
            return;
        }

        try {
            // Create API client
            $this->apiClient = DeviceApiClientFactory::make($this->device);

            if (!$this->apiClient) {
                d_echo("No API client available for device {$this->device->hostname}\n");
                return;
            }

            d_echo("Polling {$this->device->hostname} via REST API...\n");

            $start = microtime(true);

            // Poll each capability the client supports
            $capabilities = $this->apiClient->capabilities();

            foreach ($capabilities as $capability) {
                try {
                    $this->pollCapability($capability);
                } catch (\Exception $e) {
                    d_echo("  Failed to poll $capability: " . $e->getMessage() . "\n");
                    \Log::warning("REST API polling failed for capability $capability", [
                        'device_id' => $this->device->device_id,
                        'capability' => $capability,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $latencyMs = (int) ((microtime(true) - $start) * 1000);
            DeviceApiSettings::recordSuccess($this->device, $latencyMs);

            d_echo("REST API polling completed in {$latencyMs}ms\n");

        } catch (\Exception $e) {
            DeviceApiSettings::recordError($this->device, $e->getMessage());
            d_echo("REST API polling FAILED: " . $e->getMessage() . "\n");
            \Log::error("REST API polling failed", [
                'device_id' => $this->device->device_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function pollCapability(string $capability): void
    {
        d_echo("  Polling $capability...\n");

        switch ($capability) {
            case 'sensors':
                $this->pollSensors();
                break;

            case 'ports':
                $this->pollPorts();
                break;

            case 'mempools':
                $this->pollMempools();
                break;

            case 'processors':
                $this->pollProcessors();
                break;

            case 'inventory':
                $this->pollInventory();
                break;

            case 'ipv4':
                $this->pollIpv4Addresses();
                break;

            default:
                d_echo("    Unknown capability: $capability\n");
        }
    }

    protected function pollSensors(): void
    {
        $sensors = $this->apiClient->fetchSensors($this->device);

        if (empty($sensors)) {
            d_echo("    No sensors data\n");
            return;
        }

        d_echo("    Found " . count($sensors) . " sensors\n");

        foreach ($sensors as $sensorData) {
            $sensorClass = $sensorData['sensor_class'] ?? 'count';
            $sensorType = $sensorData['sensor_type'] ?? 'api';
            $sensorDescr = $sensorData['sensor_descr'] ?? 'Unknown';
            $sensorCurrent = $sensorData['sensor_current'] ?? null;
            $sensorIndex = $sensorData['sensor_index'] ?? crc32($sensorDescr);
            $sensorOid = $sensorData['sensor_oid'] ?? ".1.3.6.1.4.1.99999.$sensorIndex";

            // Skip sensors with null or invalid values
            if ($sensorCurrent === null || $sensorCurrent === '') {
                d_echo("      - Skipping $sensorClass: $sensorDescr (null/empty value)\n");
                continue;
            }

            // Skip sensors with 0 value unless it's a valid zero for certain classes
            $validZeroClasses = ['state', 'runtime', 'count', 'delay'];
            if ($sensorCurrent == 0 && !in_array($sensorClass, $validZeroClasses)) {
                d_echo("      - Skipping $sensorClass: $sensorDescr (zero value)\n");
                continue;
            }

            d_echo("      - $sensorClass: $sensorDescr = $sensorCurrent\n");

            // Check if sensor exists
            $sensor = \App\Models\Sensor::where('device_id', $this->device->device_id)
                ->where('sensor_class', $sensorClass)
                ->where('sensor_type', $sensorType)
                ->where('sensor_index', $sensorIndex)
                ->first();

            if (!$sensor) {
                // Create new sensor
                $sensor = new \App\Models\Sensor();
                $sensor->device_id = $this->device->device_id;
                $sensor->sensor_class = $sensorClass;
                $sensor->sensor_type = $sensorType;
                $sensor->sensor_index = $sensorIndex;
                $sensor->sensor_oid = $sensorOid;
            }

            // Update sensor data
            $sensor->sensor_descr = $sensorDescr;
            $sensor->sensor_divisor = $sensorData['sensor_divisor'] ?? 1;
            $sensor->sensor_multiplier = $sensorData['sensor_multiplier'] ?? 1;
            $sensor->sensor_limit = $sensorData['sensor_limit'] ?? null;
            $sensor->sensor_limit_warn = $sensorData['sensor_limit_warn'] ?? null;
            $sensor->sensor_limit_low = $sensorData['sensor_limit_low'] ?? null;
            $sensor->sensor_limit_low_warn = $sensorData['sensor_limit_low_warn'] ?? null;
            $sensor->sensor_current = $sensorCurrent;
            $sensor->sensor_prev = $sensor->sensor_current ?? null;
            $sensor->entPhysicalIndex = $sensorData['entPhysicalIndex'] ?? null;
            $sensor->entPhysicalIndex_measured = $sensorData['entPhysicalIndex_measured'] ?? null;
            $sensor->sensor_custom = $sensorData['sensor_custom'] ?? 'No';
            $sensor->rrd_type = $sensorData['rrd_type'] ?? 'GAUGE';
            $sensor->sensor_alert = $sensorData['sensor_alert'] ?? 1;

            $sensor->save();

            // Update RRD
            $rrd_name = get_sensor_rrd($this->device, $sensor->toArray());
            $rrd_def = RrdDefinition::make()->addDataset('sensor', $sensor->rrd_type, 0);

            $fields = [
                'sensor' => $sensorCurrent,
            ];

            $tags = [
                'sensor_class' => $sensorClass,
                'sensor_type' => $sensorType,
                'sensor_index' => $sensorIndex,
                'rrd_name' => $rrd_name,
                'rrd_def' => $rrd_def,
            ];

            app('Datastore')->put($this->device->toArray(), 'sensor', $tags, $fields);
        }
    }

    protected function pollPorts(): void
    {
        $ports = $this->apiClient->fetchPorts($this->device);

        if (empty($ports)) {
            d_echo("    No ports data\n");
            return;
        }

        d_echo("    Found " . count($ports) . " ports\n");

        // Use LibreNMS port sync functionality
        foreach ($ports as $portData) {
            $ifIndex = $portData['ifIndex'] ?? null;
            $ifName = $portData['ifName'] ?? '';
            $ifDescr = $portData['ifDescr'] ?? '';
            $ifOperStatus = $portData['ifOperStatus'] ?? 'unknown';
            $ifAdminStatus = $portData['ifAdminStatus'] ?? 'unknown';

            // Skip ports without valid index
            if ($ifIndex === null) {
                d_echo("      - Skipping port: $ifDescr (no ifIndex)\n");
                continue;
            }

            // Skip disabled ports (optional - uncomment if you want to skip down ports)
            // if ($ifOperStatus === 'down' && $ifAdminStatus === 'down') {
            //     d_echo("      - Skipping port $ifIndex: $ifDescr (disabled)\n");
            //     continue;
            // }

            d_echo("      - Port $ifIndex: $ifDescr ($ifOperStatus)\n");

            // Check if port exists (should be created during discovery)
            $port = \App\Models\Port::where('device_id', $this->device->device_id)
                ->where('ifIndex', $ifIndex)
                ->first();

            if (!$port) {
                // Port doesn't exist - skip (discovery should create it)
                d_echo("        Warning: Port not found in database (run discovery first)\n");
                continue;
            }

            // Update only operational status and statistics
            $port->ifOperStatus = $ifOperStatus;
            $port->ifAdminStatus = $ifAdminStatus;
            $port->save();
        }
    }

    protected function pollMempools(): void
    {
        $mempools = $this->apiClient->fetchMempools($this->device);

        if (empty($mempools)) {
            d_echo("    No mempools data\n");
            return;
        }

        d_echo("    Found " . count($mempools) . " mempools\n");

        foreach ($mempools as $mempoolData) {
            $mempoolIndex = $mempoolData['mempool_index'] ?? null;
            $mempoolDescr = $mempoolData['mempool_descr'] ?? 'Memory';
            $mempoolTotal = $mempoolData['mempool_total'] ?? 0;
            $mempoolUsed = $mempoolData['mempool_used'] ?? 0;

            // Skip mempools without valid index or total
            if ($mempoolIndex === null || $mempoolTotal == 0) {
                d_echo("      - Skipping mempool: $mempoolDescr (invalid data)\n");
                continue;
            }

            d_echo("      - $mempoolDescr: $mempoolUsed/$mempoolTotal\n");

            // Check if mempool exists
            $mempool = \App\Models\Mempool::where('device_id', $this->device->device_id)
                ->where('mempool_index', $mempoolIndex)
                ->first();

            if (!$mempool) {
                // Mempool doesn't exist - skip (discovery should create it)
                d_echo("        Warning: Mempool not found in database (run discovery first)\n");
                continue;
            }

            // Update mempool usage data only
            $mempool->mempool_perc = $mempoolData['mempool_perc'] ?? 0;
            $mempool->mempool_used = $mempoolData['mempool_used'] ?? 0;
            $mempool->mempool_free = $mempoolData['mempool_free'] ?? 0;

            $mempool->save();

            // Update RRD
            $rrd_name = ['mempool', $mempool->mempool_type, $mempool->mempool_index];
            $rrd_def = RrdDefinition::make()
                ->addDataset('used', 'GAUGE', 0)
                ->addDataset('free', 'GAUGE', 0);

            $fields = [
                'used' => $mempool->mempool_used,
                'free' => $mempool->mempool_free,
            ];

            $tags = compact('mempool_type', 'rrd_name', 'rrd_def');
            app('Datastore')->put($this->device->toArray(), 'mempool', $tags, $fields);
        }
    }

    protected function pollProcessors(): void
    {
        $processors = $this->apiClient->fetchProcessors($this->device);

        if (empty($processors)) {
            d_echo("    No processors data\n");
            return;
        }

        d_echo("    Found " . count($processors) . " processors\n");

        foreach ($processors as $processorData) {
            $processorIndex = $processorData['processor_index'] ?? null;
            $processorDescr = $processorData['processor_descr'] ?? 'CPU';
            $processorUsage = $processorData['processor_usage'] ?? null;

            // Skip processors without valid index
            if ($processorIndex === null) {
                d_echo("      - Skipping processor: $processorDescr (no index)\n");
                continue;
            }

            // Skip processors with null usage (though 0% is valid)
            if ($processorUsage === null) {
                d_echo("      - Skipping processor: $processorDescr (no usage data)\n");
                continue;
            }

            d_echo("      - $processorDescr: {$processorUsage}%\n");

            // Check if processor exists
            $processor = \App\Models\Processor::where('device_id', $this->device->device_id)
                ->where('processor_index', $processorIndex)
                ->first();

            if (!$processor) {
                // Processor doesn't exist - skip (discovery should create it)
                d_echo("        Warning: Processor not found in database (run discovery first)\n");
                continue;
            }

            // Update processor usage only
            $processor->processor_usage = $processorData['processor_usage'] ?? 0;

            $processor->save();

            // Update RRD
            $rrd_name = ['processor', $processor->processor_type, $processor->processor_index];
            $rrd_def = RrdDefinition::make()->addDataset('usage', 'GAUGE', 0, 100);

            $fields = [
                'usage' => $processor->processor_usage,
            ];

            $tags = compact('rrd_name', 'rrd_def');
            app('Datastore')->put($this->device->toArray(), 'processor', $tags, $fields);
        }
    }

    protected function pollInventory(): void
    {
        $inventory = $this->apiClient->fetchInventory($this->device);

        if (empty($inventory)) {
            d_echo("    No inventory data\n");
            return;
        }

        d_echo("    Found " . count($inventory) . " inventory items\n");

        foreach ($inventory as $itemData) {
            $physicalIndex = $itemData['entPhysicalIndex'] ?? null;
            $physicalDescr = $itemData['entPhysicalDescr'] ?? '';

            // Skip inventory items without valid index or description
            if ($physicalIndex === null || empty($physicalDescr)) {
                d_echo("      - Skipping inventory item (invalid data)\n");
                continue;
            }

            d_echo("      - $physicalDescr\n");

            // Check if inventory item exists (should be created during discovery)
            $item = \App\Models\EntPhysical::where('device_id', $this->device->device_id)
                ->where('entPhysicalIndex', $physicalIndex)
                ->first();

            if (!$item) {
                // Inventory item doesn't exist - skip (discovery should create it)
                d_echo("        Warning: Inventory item not found in database (run discovery first)\n");
                continue;
            }

            // Inventory items are static hardware - no updates needed during polling
            // Discovery handles creation and updates
        }
    }

    protected function pollIpv4Addresses(): void
    {
        $addresses = $this->apiClient->fetchIpv4Addresses($this->device);

        if (empty($addresses)) {
            d_echo("    No IPv4 addresses\n");
            return;
        }

        d_echo("    Found " . count($addresses) . " IPv4 addresses\n");

        foreach ($addresses as $addrData) {
            d_echo("      - {$addrData['ipv4_address']}/{$addrData['ipv4_prefixlen']} on ifIndex {$addrData['ifIndex']}\n");

            // Find the port for this interface
            $port = \App\Models\Port::where('device_id', $this->device->device_id)
                ->where('ifIndex', $addrData['ifIndex'])
                ->first();

            if (!$port) {
                d_echo("        Warning: Port not found for ifIndex {$addrData['ifIndex']}\n");
                continue;
            }

            // Check if IPv4 address exists
            $ipv4 = \App\Models\Ipv4Address::where('port_id', $port->port_id)
                ->where('ipv4_address', $addrData['ipv4_address'])
                ->first();

            if (!$ipv4) {
                // Create new IPv4 address
                $ipv4 = new \App\Models\Ipv4Address();
                $ipv4->port_id = $port->port_id;
                $ipv4->ipv4_address = $addrData['ipv4_address'];
            }

            // Update IPv4 address data
            $ipv4->ipv4_prefixlen = $addrData['ipv4_prefixlen'] ?? 32;
            $ipv4->context_name = $addrData['context_name'] ?? '';

            $ipv4->save();
        }
    }
}
