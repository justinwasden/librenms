<?php

namespace LibreNMS\OS;

use App\ApiClients\DeviceApiClientFactory;
use App\Models\Mempool;
use App\Models\Storage;
use Illuminate\Support\Collection;
use LibreNMS\Device\Processor;
use LibreNMS\Interfaces\Discovery\ProcessorDiscovery;
use LibreNMS\Interfaces\Discovery\MempoolsDiscovery;
use LibreNMS\Interfaces\Discovery\StorageDiscovery;
use LibreNMS\OS\Traits\ApiPolling;

class Proxmox extends \LibreNMS\OS implements
    ProcessorDiscovery,
    MempoolsDiscovery,
    StorageDiscovery
{
    use ApiPolling;

    /**
     * Discover processors (via API)
     *
     * Returns an array of LibreNMS\Device\Processor objects as required by the
     * ProcessorDiscovery interface.
     *
     * @return array<Processor>
     */
    public function discoverProcessors()
    {
        if (!$this->hasApiConfig()) {
            return [];
        }

        try {
            $client = DeviceApiClientFactory::make($this->getDevice());
            if (!$client || !in_array('processors', $client->capabilities())) {
                return [];
            }

            // Fetch node status and normalize
            $nodes = $client->get('/nodes');
            $normalized = $this->normalizeData('Proxmox\NodeStatus', $nodes);

            // Extract processor data and convert to Processor objects
            if (!empty($normalized['processors'])) {
                $processors = [];
                foreach ($normalized['processors'] as $proc) {
                    $processors[] = Processor::discover(
                        $proc['processor_type'] ?? 'proxmox',
                        $this->getDeviceId(),
                        '',  // No OID for API-sourced data
                        $proc['processor_index'] ?? 0,
                        $proc['processor_descr'] ?? 'CPU',
                        1,   // precision
                        $proc['processor_usage'] ?? null,
                        null // warn_percent
                    );
                }

                if (!empty($processors)) {
                    \Log::info('Proxmox: Discovered ' . count($processors) . ' processors via API');
                    return $processors;
                }
            }

            return [];
        } catch (\Exception $e) {
            \Log::warning('Proxmox processor discovery failed', [
                'device_id' => $this->getDevice()->device_id,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Discover memory pools (via API)
     *
     * Returns a Collection of Mempool model instances for native module compatibility
     */
    public function discoverMempools(): Collection
    {
        // Try API-based discovery first
        if ($this->hasApiConfig()) {
            try {
                $client = DeviceApiClientFactory::make($this->getDevice());
                if ($client && in_array('mempools', $client->capabilities())) {
                    $nodes = $client->get('/nodes');
                    $normalized = $this->normalizeData('Proxmox\NodeStatus', $nodes);

                    // Extract mempools data from the normalized structure
                    if (!empty($normalized['mempools'])) {
                        // Convert normalized arrays to Mempool model instances
                        $mempools = collect($normalized['mempools'])->map(function ($item) {
                            $item['device_id'] = $this->getDeviceId();
                            return new Mempool($item);
                        });

                        if ($mempools->isNotEmpty()) {
                            \Log::info('Proxmox: Discovered ' . $mempools->count() . ' mempools via API');
                            return $mempools;
                        }
                    }
                }
            } catch (\Exception $e) {
                \Log::debug('Proxmox API mempool discovery failed, falling back to SNMP', [
                    'device_id' => $this->getDevice()->device_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Fallback to parent SNMP-based discovery
        return parent::discoverMempools();
    }

    /**
     * Discover sensors (via API)
     * Note: This is a custom discovery method, not a standard interface
     */
    public function discoverSensors()
    {
        if (!$this->hasApiConfig()) {
            return [];
        }

        $sensors = [];

        try {
            $client = DeviceApiClientFactory::make($this->getDevice());
            if (!$client || !in_array('sensors', $client->capabilities())) {
                return [];
            }

            // Fetch cluster status for health sensors
            $clusterStatus = $client->get('/cluster/status');
            $clusterNormalized = $this->normalizeData('Proxmox\ClusterStatus', $clusterStatus);

            // Extract sensors from cluster status (normalizer returns ['sensors' => [...]])
            if (!empty($clusterNormalized['sensors'])) {
                $sensors = array_merge($sensors, $clusterNormalized['sensors']);
            }

            // Fetch node status for additional sensors
            $nodes = $client->get('/nodes');
            $nodeNormalized = $this->normalizeData('Proxmox\NodeStatus', $nodes);

            // Extract sensors from node status (normalizer returns ['sensors' => [...], 'processors' => [...], 'mempools' => [...]])
            if (!empty($nodeNormalized['sensors'])) {
                $sensors = array_merge($sensors, $nodeNormalized['sensors']);
            }

            if (!empty($sensors)) {
                \Log::info('Proxmox: Discovered ' . count($sensors) . ' sensors via API');
            }

            return $sensors;
        } catch (\Exception $e) {
            \Log::warning('Proxmox sensor discovery failed', [
                'device_id' => $this->getDevice()->device_id,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Discover storage (via API)
     *
     * Returns a Collection of Storage model instances for native module compatibility
     */
    public function discoverStorage(): Collection
    {
        // Try API-based discovery first
        if ($this->hasApiConfig()) {
            try {
                $client = DeviceApiClientFactory::make($this->getDevice());
                if ($client && in_array('storage', $client->capabilities())) {
                    $nodes = $client->get('/nodes');
                    $storageItems = [];

                    foreach ($nodes['data'] ?? [] as $node) {
                        $nodeName = $node['node'] ?? null;
                        if (!$nodeName) {
                            continue;
                        }

                        try {
                            $storageData = $client->get("/nodes/{$nodeName}/storage");
                            $nodeStorage = $this->normalizeData('Proxmox\StorageStatus', $storageData);

                            if (!empty($nodeStorage)) {
                                $storageItems = array_merge($storageItems, $nodeStorage);
                            }
                        } catch (\Exception $e) {
                            \Log::debug("Proxmox storage discovery failed for node {$nodeName}", [
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }

                    if (!empty($storageItems)) {
                        // Convert normalized arrays to Storage model instances
                        return collect($storageItems)->map(function ($item) {
                            return new Storage($item);
                        });
                    }
                }
            } catch (\Exception $e) {
                \Log::debug('Proxmox API storage discovery failed, falling back to SNMP', [
                    'device_id' => $this->getDevice()->device_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Fallback to parent SNMP-based discovery
        return parent::discoverStorage();
    }

    /**
     * Discover ports/interfaces (via API)
     */
    public function discoverPorts()
    {
        if (!$this->hasApiConfig()) {
            return [];
        }

        try {
            $client = DeviceApiClientFactory::make($this->getDevice());
            if (!$client || !in_array('ports', $client->capabilities())) {
                return [];
            }

            // Fetch network interfaces from each node
            $nodes = $client->get('/nodes');
            $ports = [];

            foreach ($nodes['data'] ?? [] as $node) {
                $nodeName = $node['node'] ?? null;
                if (!$nodeName) {
                    continue;
                }

                try {
                    $networkData = $client->get("/nodes/{$nodeName}/network");
                    $nodePorts = $this->normalizeData('Proxmox\NodeNetwork', $networkData);

                    if (!empty($nodePorts)) {
                        $ports = array_merge($ports, $nodePorts);
                    }
                } catch (\Exception $e) {
                    \Log::debug("Proxmox network discovery failed for node {$nodeName}", [
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return $ports;
        } catch (\Exception $e) {
            \Log::warning('Proxmox ports discovery failed', [
                'device_id' => $this->getDevice()->device_id,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Discover VMs/guests (via API)
     */
    public function discoverVMs()
    {
        if (!$this->hasApiConfig()) {
            return [];
        }

        try {
            $client = DeviceApiClientFactory::make($this->getDevice());
            if (!$client || !in_array('vminfo', $client->capabilities())) {
                return [];
            }

            // Fetch cluster resources (includes VMs, CTs, nodes)
            $resources = $client->get('/cluster/resources');
            $vms = $this->normalizeData('Proxmox\GuestDiscovery', $resources);

            return $vms ?? [];
        } catch (\Exception $e) {
            \Log::warning('Proxmox VM discovery failed', [
                'device_id' => $this->getDevice()->device_id,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Discover clusters (via API)
     */
    public function discoverClusters()
    {
        if (!$this->hasApiConfig()) {
            return [];
        }

        try {
            $client = DeviceApiClientFactory::make($this->getDevice());
            if (!$client || !in_array('clusters', $client->capabilities())) {
                return [];
            }

            // Fetch cluster info
            $clusterStatus = $client->get('/cluster/status');
            $clusters = $this->normalizeData('Proxmox\ClusterInfo', $clusterStatus);

            return $clusters ?? [];
        } catch (\Exception $e) {
            \Log::warning('Proxmox cluster discovery failed', [
                'device_id' => $this->getDevice()->device_id,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Discover hypervisor hosts (via API)
     */
    public function discoverHosts()
    {
        if (!$this->hasApiConfig()) {
            return [];
        }

        try {
            $client = DeviceApiClientFactory::make($this->getDevice());
            if (!$client || !in_array('hypervisor_hosts', $client->capabilities())) {
                return [];
            }

            // Fetch nodes (hypervisor hosts)
            $nodes = $client->get('/nodes');
            $hosts = $this->normalizeData('Proxmox\Nodes', $nodes);

            return $hosts ?? [];
        } catch (\Exception $e) {
            \Log::warning('Proxmox hosts discovery failed', [
                'device_id' => $this->getDevice()->device_id,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Discover IPv4 addresses (via API)
     */
    public function discoverIpv4()
    {
        if (!$this->hasApiConfig()) {
            return [];
        }

        try {
            $client = DeviceApiClientFactory::make($this->getDevice());
            if (!$client || !in_array('ipv4', $client->capabilities())) {
                return [];
            }

            // Fetch network configuration from nodes
            $nodes = $client->get('/nodes');
            $ipv4 = [];

            foreach ($nodes['data'] ?? [] as $node) {
                $nodeName = $node['node'] ?? null;
                if (!$nodeName) {
                    continue;
                }

                try {
                    $networkData = $client->get("/nodes/{$nodeName}/network");
                    $nodeIpv4 = $this->normalizeData('Proxmox\Ipv4', $networkData);

                    if (!empty($nodeIpv4)) {
                        $ipv4 = array_merge($ipv4, $nodeIpv4);
                    }
                } catch (\Exception $e) {
                    \Log::debug("Proxmox IPv4 discovery failed for node {$nodeName}", [
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return $ipv4;
        } catch (\Exception $e) {
            \Log::warning('Proxmox IPv4 discovery failed', [
                'device_id' => $this->getDevice()->device_id,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }
}
