<?php

namespace App\Pollers;

use App\ApiClients\DeviceApiClientFactory;
use App\Models\Device;
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

        // Include sensor polling functions
        require_once base_path('includes/discovery/sensors.inc.php');

        foreach ($sensors as $sensor) {
            $sensorClass = $sensor['sensor_class'] ?? 'count';
            $sensorType = $sensor['sensor_type'] ?? 'api';
            $sensorDescr = $sensor['sensor_descr'] ?? 'Unknown';
            $sensorCurrent = $sensor['sensor_current'] ?? 0;
            $sensorIndex = $sensor['sensor_index'] ?? crc32($sensorDescr);

            d_echo("      - $sensorClass: $sensorDescr = $sensorCurrent\n");

            // Use discover_sensor to create/update sensor
            discover_sensor(
                $valid_sensor = null,
                $sensorClass,
                $this->device,
                $sensor['sensor_oid'] ?? ".1.3.6.1.4.1.99999.$sensorIndex", // Fake OID for API sensors
                $sensorIndex,
                $sensorType,
                $sensorDescr,
                $sensor['sensor_divisor'] ?? 1,
                $sensor['sensor_multiplier'] ?? 1,
                $sensor['sensor_limit'] ?? null,
                $sensor['sensor_limit_warn'] ?? null,
                $sensor['sensor_limit_low'] ?? null,
                $sensor['sensor_limit_low_warn'] ?? null,
                $sensorCurrent,
                $sensor['rrd_type'] ?? 'GAUGE',
                $sensor['entPhysicalIndex'] ?? null,
                $sensor['entPhysicalIndex_measured'] ?? null,
                $sensor['user_func'] ?? null,
                $sensor['group'] ?? null
            );
        }

        // Poll/update RRD values for all sensors
        include base_path('includes/polling/sensors.inc.php');
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
            d_echo("      - Port {$portData['ifIndex']}: {$portData['ifDescr']}\n");

            // Check if port exists
            $port = \App\Models\Port::where('device_id', $this->device->device_id)
                ->where('ifIndex', $portData['ifIndex'])
                ->first();

            if (!$port) {
                // Create new port
                $port = new \App\Models\Port();
                $port->device_id = $this->device->device_id;
                $port->ifIndex = $portData['ifIndex'];
                $port->port_id = null; // Will be auto-generated
            }

            // Update port data
            $port->ifName = $portData['ifName'] ?? '';
            $port->ifDescr = $portData['ifDescr'] ?? '';
            $port->ifAlias = $portData['ifAlias'] ?? '';
            $port->ifType = $portData['ifType'] ?? 'other';
            $port->ifOperStatus = $portData['ifOperStatus'] ?? 'unknown';
            $port->ifAdminStatus = $portData['ifAdminStatus'] ?? 'unknown';
            $port->ifSpeed = $portData['ifSpeed'] ?? 0;
            $port->ifMtu = $portData['ifMtu'] ?? 0;
            $port->ifPhysAddress = $portData['ifPhysAddress'] ?? '';

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
            d_echo("      - {$mempoolData['mempool_descr']}: {$mempoolData['mempool_used']}/{$mempoolData['mempool_total']}\n");

            // Check if mempool exists
            $mempool = \App\Models\Mempool::where('device_id', $this->device->device_id)
                ->where('mempool_index', $mempoolData['mempool_index'])
                ->first();

            if (!$mempool) {
                // Create new mempool
                $mempool = new \App\Models\Mempool();
                $mempool->device_id = $this->device->device_id;
                $mempool->mempool_index = $mempoolData['mempool_index'];
            }

            // Update mempool data
            $mempool->mempool_type = $mempoolData['mempool_type'] ?? 'api';
            $mempool->mempool_descr = $mempoolData['mempool_descr'] ?? 'Memory';
            $mempool->mempool_precision = $mempoolData['mempool_precision'] ?? 1;
            $mempool->mempool_perc = $mempoolData['mempool_perc'] ?? 0;
            $mempool->mempool_used = $mempoolData['mempool_used'] ?? 0;
            $mempool->mempool_free = $mempoolData['mempool_free'] ?? 0;
            $mempool->mempool_total = $mempoolData['mempool_total'] ?? 0;

            $mempool->save();

            // Update RRD
            $rrd_name = ['mempool', $mempool->mempool_type, $mempool->mempool_index];
            $rrd_def = \RrdDefinition::make()
                ->addDataset('used', 'GAUGE', 0)
                ->addDataset('free', 'GAUGE', 0);

            $fields = [
                'used' => $mempool->mempool_used,
                'free' => $mempool->mempool_free,
            ];

            $tags = compact('mempool_type', 'rrd_name', 'rrd_def');
            data_update($this->device, 'mempool', $tags, $fields);
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
            d_echo("      - {$processorData['processor_descr']}: {$processorData['processor_usage']}%\n");

            // Check if processor exists
            $processor = \App\Models\Processor::where('device_id', $this->device->device_id)
                ->where('processor_index', $processorData['processor_index'])
                ->first();

            if (!$processor) {
                // Create new processor
                $processor = new \App\Models\Processor();
                $processor->device_id = $this->device->device_id;
                $processor->processor_index = $processorData['processor_index'];
            }

            // Update processor data
            $processor->processor_type = $processorData['processor_type'] ?? 'api';
            $processor->processor_descr = $processorData['processor_descr'] ?? 'CPU';
            $processor->processor_usage = $processorData['processor_usage'] ?? 0;
            $processor->processor_precision = $processorData['processor_precision'] ?? 1;

            $processor->save();

            // Update RRD
            $rrd_name = ['processor', $processor->processor_type, $processor->processor_index];
            $rrd_def = \RrdDefinition::make()->addDataset('usage', 'GAUGE', 0, 100);

            $fields = [
                'usage' => $processor->processor_usage,
            ];

            $tags = compact('rrd_name', 'rrd_def');
            data_update($this->device, 'processor', $tags, $fields);
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
            d_echo("      - {$itemData['entPhysicalDescr']}\n");

            // Check if inventory item exists
            $item = \App\Models\EntityPhysical::where('device_id', $this->device->device_id)
                ->where('entPhysicalIndex', $itemData['entPhysicalIndex'])
                ->first();

            if (!$item) {
                // Create new inventory item
                $item = new \App\Models\EntityPhysical();
                $item->device_id = $this->device->device_id;
                $item->entPhysicalIndex = $itemData['entPhysicalIndex'];
            }

            // Update inventory data
            $item->entPhysicalDescr = $itemData['entPhysicalDescr'] ?? '';
            $item->entPhysicalClass = $itemData['entPhysicalClass'] ?? 'other';
            $item->entPhysicalName = $itemData['entPhysicalName'] ?? '';
            $item->entPhysicalModelName = $itemData['entPhysicalModelName'] ?? '';
            $item->entPhysicalSerialNum = $itemData['entPhysicalSerialNum'] ?? '';
            $item->entPhysicalContainedIn = $itemData['entPhysicalContainedIn'] ?? 0;
            $item->entPhysicalMfgName = $itemData['entPhysicalMfgName'] ?? '';
            $item->entPhysicalParentRelPos = $itemData['entPhysicalParentRelPos'] ?? -1;
            $item->entPhysicalVendorType = $itemData['entPhysicalVendorType'] ?? null;
            $item->entPhysicalHardwareRev = $itemData['entPhysicalHardwareRev'] ?? '';
            $item->entPhysicalFirmwareRev = $itemData['entPhysicalFirmwareRev'] ?? '';
            $item->entPhysicalSoftwareRev = $itemData['entPhysicalSoftwareRev'] ?? '';
            $item->entPhysicalIsFRU = $itemData['entPhysicalIsFRU'] ?? 'false';
            $item->entPhysicalAlias = $itemData['entPhysicalAlias'] ?? '';
            $item->entPhysicalAssetID = $itemData['entPhysicalAssetID'] ?? '';

            $item->save();
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
