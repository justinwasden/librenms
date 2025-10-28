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

        // TODO: Process and store sensors using LibreNMS sensor functions
        // For now, just log them
        foreach ($sensors as $sensor) {
            d_echo("      - {$sensor['sensor_class']}: {$sensor['sensor_descr']} = {$sensor['sensor_current']}\n");
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

        // TODO: Process and store ports using LibreNMS port functions
        foreach ($ports as $port) {
            d_echo("      - Port {$port['ifIndex']}: {$port['ifDescr']}\n");
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

        // TODO: Process and store mempools using LibreNMS mempool functions
        foreach ($mempools as $mempool) {
            d_echo("      - {$mempool['mempool_descr']}: {$mempool['mempool_used']}/{$mempool['mempool_total']}\n");
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

        // TODO: Process and store processors using LibreNMS processor functions
        foreach ($processors as $processor) {
            d_echo("      - {$processor['processor_descr']}: {$processor['processor_usage']}%\n");
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

        // TODO: Process and store inventory using LibreNMS inventory functions
        foreach ($inventory as $item) {
            d_echo("      - {$item['entPhysicalDescr']}\n");
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

        // TODO: Process and store IPv4 addresses using LibreNMS functions
        foreach ($addresses as $addr) {
            d_echo("      - {$addr['ipv4_address']}/{$addr['ipv4_prefixlen']} on ifIndex {$addr['ifIndex']}\n");
        }
    }
}
