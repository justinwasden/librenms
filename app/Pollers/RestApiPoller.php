<?php

namespace App\Pollers;

use App\ApiClients\DeviceApiClientFactory;
use App\Models\Device;
use App\Services\DeviceApiPersistor;
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

        $handlers = [
            'sensors' => [
                'label' => 'sensors',
                'fetch' => 'fetchSensors',
                'persist' => fn ($data) => DeviceApiPersistor::saveSensors($this->device, $data),
            ],
            'ports' => [
                'label' => 'ports',
                'fetch' => 'fetchPorts',
                'persist' => fn ($data) => DeviceApiPersistor::savePorts($this->device, $data),
            ],
            'mempools' => [
                'label' => 'mempools',
                'fetch' => 'fetchMempools',
                'persist' => fn ($data) => DeviceApiPersistor::saveMempools($this->device, $data),
            ],
            'processors' => [
                'label' => 'processors',
                'fetch' => 'fetchProcessors',
                'persist' => fn ($data) => DeviceApiPersistor::saveProcessors($this->device, $data),
            ],
            'inventory' => [
                'label' => 'inventory items',
                'fetch' => 'fetchInventory',
                'persist' => fn ($data) => DeviceApiPersistor::saveInventory($this->device, $data),
            ],
            'storage' => [
                'label' => 'storage entries',
                'fetch' => 'fetchStorage',
                'persist' => fn ($data) => DeviceApiPersistor::saveStorage($this->device, $data),
            ],
            'transceivers' => [
                'label' => 'transceivers',
                'fetch' => 'fetchTransceivers',
                'persist' => fn ($data) => DeviceApiPersistor::saveTransceivers($this->device, $data),
            ],
            'ipv4' => [
                'label' => 'IPv4 addresses',
                'fetch' => 'fetchIpv4Addresses',
                'persist' => fn ($data) => DeviceApiPersistor::saveIpv4Addresses($this->device, $data),
            ],
            'ports_stats' => [
                'label' => 'port statistics',
                'fetch' => 'fetchPortsStatistics',
                'persist' => fn ($data) => DeviceApiPersistor::savePortsStatistics($this->device, $data),
            ],
            'ports_statistics' => [
                'label' => 'port statistics',
                'fetch' => 'fetchPortsStatistics',
                'persist' => fn ($data) => DeviceApiPersistor::savePortsStatistics($this->device, $data),
            ],
            'vlans' => [
                'label' => 'VLANs',
                'fetch' => 'fetchVlans',
                'persist' => fn ($data) => DeviceApiPersistor::saveVlans($this->device, $data),
            ],
            'vminfo' => [
                'label' => 'virtual machines',
                'fetch' => 'fetchVms',
                'persist' => fn ($data) => DeviceApiPersistor::saveVminfo($this->device, $data),
            ],
            'device_info' => [
                'label' => 'device info',
                'fetch' => 'fetchDeviceInfo',
                'persist' => fn ($data) => DeviceApiPersistor::saveDeviceInfo($this->device, $data),
                'single' => true,
            ],
            'hypervisor_hosts' => [
                'label' => 'hypervisor hosts',
                'fetch' => 'fetchHosts',
                'persist' => fn ($data) => DeviceApiPersistor::saveHosts($this->device, $data),
            ],
            'clusters' => [
                'label' => 'clusters',
                'fetch' => 'fetchClusters',
                'persist' => fn ($data) => DeviceApiPersistor::saveClusters($this->device, $data),
            ],
            'alerts' => [
                'label' => 'alerts',
                'fetch' => 'fetchAlerts',
                'persist' => fn ($data) => DeviceApiPersistor::saveAlerts($this->device, $data),
            ],
            'storage_hosts' => [
                'label' => 'storage hosts',
                'fetch' => 'fetchStorageHosts',
                'persist' => fn ($data) => DeviceApiPersistor::saveStorageHosts($this->device, $data),
            ],
            'drives' => [
                'label' => 'drives',
                'fetch' => 'fetchDrives',
                'persist' => fn ($data) => DeviceApiPersistor::saveDrives($this->device, $data),
            ],
            'host_groups' => [
                'label' => 'host groups',
                'fetch' => 'fetchHostGroups',
                'persist' => fn ($data) => DeviceApiPersistor::saveHostGroups($this->device, $data),
            ],
            'protection_groups' => [
                'label' => 'protection groups',
                'fetch' => 'fetchProtectionGroups',
                'persist' => fn ($data) => DeviceApiPersistor::saveProtectionGroups($this->device, $data),
            ],
            'fc_ports' => [
                'label' => 'FC ports',
                'fetch' => 'fetchFcPorts',
                'persist' => fn ($data) => DeviceApiPersistor::saveFcPorts($this->device, $data),
            ],
            'connections' => [
                'label' => 'host-volume connections',
                'fetch' => 'fetchConnections',
                'persist' => fn ($data) => DeviceApiPersistor::saveConnections($this->device, $data),
            ],
            'controllers' => [
                'label' => 'controllers',
                'fetch' => 'fetchControllers',
                'persist' => fn ($data) => DeviceApiPersistor::saveControllers($this->device, $data),
            ],
        ];

        if (!isset($handlers[$capability])) {
            d_echo("    Unknown capability: $capability\n");
            return;
        }

        $handler = $handlers[$capability];

        if (!method_exists($this->apiClient, $handler['fetch'])) {
            d_echo("    API client missing fetch method for $capability\n");
            return;
        }

        $data = $this->apiClient->{$handler['fetch']}($this->device);

        if (empty($data)) {
            d_echo("    No {$handler['label']} data\n");
            return;
        }

        $count = ($handler['single'] ?? false) ? 1 : (is_countable($data) ? count($data) : 1);
        d_echo("    Found {$count} {$handler['label']}\n");

        $handler['persist']($data);
    }
}
