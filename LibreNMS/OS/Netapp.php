<?php

namespace LibreNMS\OS;

use App\ApiClients\DeviceApiClientFactory;
use LibreNMS\Interfaces\Discovery\ProcessorDiscovery;
use LibreNMS\Interfaces\Discovery\MempoolsDiscovery;
use LibreNMS\Interfaces\Discovery\StorageDiscovery;
use LibreNMS\OS\Traits\ApiPolling;

class Netapp extends \LibreNMS\OS implements
    ProcessorDiscovery,
    MempoolsDiscovery,
    StorageDiscovery
{
    use ApiPolling;

    /**
     * Discover processors (via API)
     * NetApp ONTAP exposes CPU metrics per node
     */
    public function discoverProcessors()
    {
        if (!$this->hasApiConfig()) {
            return [];
        }

        try {
            $client = DeviceApiClientFactory::make($this->getDevice());
            if (!$client) {
                return [];
            }

            // Fetch node metrics (includes CPU utilization)
            $nodeData = $client->get('/api/cluster/nodes');
            $processors = $this->normalizeData('NetApp\NodeMetricsToProcessorsMempools', $nodeData);

            return $processors ?? [];
        } catch (\Exception $e) {
            \Log::warning('NetApp processor discovery failed', [
                'device_id' => $this->getDevice()->device_id,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Discover memory pools (via API)
     */
    public function discoverMempools(): \Illuminate\Support\Collection
    {
        if (!$this->hasApiConfig()) {
            return collect();
        }

        try {
            $client = DeviceApiClientFactory::make($this->getDevice());
            if (!$client) {
                return collect();
            }

            // Fetch node metrics (includes memory utilization)
            $nodeData = $client->get('/api/cluster/nodes');
            $mempools = $this->normalizeData('NetApp\NodeMetricsToProcessorsMempools', $nodeData);

            return collect($mempools ?? []);
        } catch (\Exception $e) {
            \Log::warning('NetApp mempool discovery failed', [
                'device_id' => $this->getDevice()->device_id,
                'error' => $e->getMessage(),
            ]);
            return collect();
        }
    }

    /**
     * Discover sensors (via API)
     * NetApp ONTAP provides various health and status sensors
     */
    public function discoverSensors()
    {
        if (!$this->hasApiConfig()) {
            return [];
        }

        $sensors = [];

        try {
            $client = DeviceApiClientFactory::make($this->getDevice());
            if (!$client) {
                return [];
            }

            // Cluster status sensors
            try {
                $clusterData = $client->get('/api/cluster');
                $clusterSensors = $this->normalizeData('NetApp\ClusterStatusToSensors', $clusterData);

                if (!empty($clusterSensors)) {
                    $sensors = array_merge($sensors, $clusterSensors);
                }
            } catch (\Exception $e) {
                \Log::debug('NetApp cluster sensors failed', ['error' => $e->getMessage()]);
            }

            // Node status sensors
            try {
                $nodeData = $client->get('/api/cluster/nodes');
                $nodeSensors = $this->normalizeData('NetApp\NodesToSensors', $nodeData);

                if (!empty($nodeSensors)) {
                    $sensors = array_merge($sensors, $nodeSensors);
                }
            } catch (\Exception $e) {
                \Log::debug('NetApp node sensors failed', ['error' => $e->getMessage()]);
            }

            // Aggregate sensors
            try {
                $aggData = $client->get('/api/storage/aggregates');
                $aggSensors = $this->normalizeData('NetApp\AggregatesToSensors', $aggData);

                if (!empty($aggSensors)) {
                    $sensors = array_merge($sensors, $aggSensors);
                }
            } catch (\Exception $e) {
                \Log::debug('NetApp aggregate sensors failed', ['error' => $e->getMessage()]);
            }

            return $sensors;
        } catch (\Exception $e) {
            \Log::warning('NetApp sensor discovery failed', [
                'device_id' => $this->getDevice()->device_id,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Discover storage (via API)
     * NetApp ONTAP provides volumes and aggregates
     */
    public function discoverStorage(): \Illuminate\Support\Collection
    {
        if (!$this->hasApiConfig()) {
            return collect();
        }

        $storage = [];

        try {
            $client = DeviceApiClientFactory::make($this->getDevice());
            if (!$client) {
                return collect();
            }

            // Volumes
            try {
                $volumeData = $client->get('/api/storage/volumes');
                $volumes = $this->normalizeData('NetApp\VolumesToStorage', $volumeData);

                if (!empty($volumes)) {
                    $storage = array_merge($storage, $volumes);
                }
            } catch (\Exception $e) {
                \Log::debug('NetApp volume discovery failed', ['error' => $e->getMessage()]);
            }

            // Storage pools/aggregates
            try {
                $poolData = $client->get('/api/storage/aggregates');
                $pools = $this->normalizeData('NetApp\PoolsToStorage', $poolData);

                if (!empty($pools)) {
                    $storage = array_merge($storage, $pools);
                }
            } catch (\Exception $e) {
                \Log::debug('NetApp pool discovery failed', ['error' => $e->getMessage()]);
            }

            return collect($storage);
        } catch (\Exception $e) {
            \Log::warning('NetApp storage discovery failed', [
                'device_id' => $this->getDevice()->device_id,
                'error' => $e->getMessage(),
            ]);
            return collect();
        }
    }

    /**
     * Discover ports/interfaces (via API)
     */
    public function discoverPorts()
    {
        if (!$this->hasApiConfig()) {
            return [];
        }

        $ports = [];

        try {
            $client = DeviceApiClientFactory::make($this->getDevice());
            if (!$client) {
                return [];
            }

            // Network interfaces
            try {
                $interfaceData = $client->get('/api/network/ip/interfaces');
                $interfaces = $this->normalizeData('NetApp\InterfacesToPorts', $interfaceData);

                if (!empty($interfaces)) {
                    $ports = array_merge($ports, $interfaces);
                }
            } catch (\Exception $e) {
                \Log::debug('NetApp interface discovery failed', ['error' => $e->getMessage()]);
            }

            // Ethernet ports
            try {
                $ethData = $client->get('/api/network/ethernet/ports');
                $ethPorts = $this->normalizeData('NetApp\EthPortsToPorts', $ethData);

                if (!empty($ethPorts)) {
                    $ports = array_merge($ports, $ethPorts);
                }
            } catch (\Exception $e) {
                \Log::debug('NetApp ethernet port discovery failed', ['error' => $e->getMessage()]);
            }

            return $ports;
        } catch (\Exception $e) {
            \Log::warning('NetApp ports discovery failed', [
                'device_id' => $this->getDevice()->device_id,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Discover inventory (via API)
     */
    public function discoverInventory()
    {
        if (!$this->hasApiConfig()) {
            return [];
        }

        $inventory = [];

        try {
            $client = DeviceApiClientFactory::make($this->getDevice());
            if (!$client) {
                return [];
            }

            // Nodes inventory
            try {
                $nodeData = $client->get('/api/cluster/nodes');
                $nodes = $this->normalizeData('NetApp\NodesToInventory', $nodeData);

                if (!empty($nodes)) {
                    $inventory = array_merge($inventory, $nodes);
                }
            } catch (\Exception $e) {
                \Log::debug('NetApp node inventory failed', ['error' => $e->getMessage()]);
            }

            // Disks inventory
            try {
                $diskData = $client->get('/api/storage/disks');
                $disks = $this->normalizeData('NetApp\DisksToInventory', $diskData);

                if (!empty($disks)) {
                    $inventory = array_merge($inventory, $disks);
                }
            } catch (\Exception $e) {
                \Log::debug('NetApp disk inventory failed', ['error' => $e->getMessage()]);
            }

            return $inventory;
        } catch (\Exception $e) {
            \Log::warning('NetApp inventory discovery failed', [
                'device_id' => $this->getDevice()->device_id,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }
}
