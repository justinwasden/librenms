<?php

namespace LibreNMS\OS;

use App\ApiClients\DeviceApiClientFactory;
use LibreNMS\Device\Processor;
use LibreNMS\Interfaces\Discovery\ProcessorDiscovery;
use LibreNMS\Interfaces\Discovery\SensorDiscovery;
use LibreNMS\Interfaces\Discovery\MempoolsDiscovery;
use LibreNMS\Interfaces\Discovery\StorageDiscovery;
use LibreNMS\OS\Traits\ApiPolling;

class Proxmox extends \LibreNMS\OS implements
    ProcessorDiscovery,
    SensorDiscovery,
    MempoolsDiscovery,
    StorageDiscovery
{
    use ApiPolling;

    /**
     * Discover processors (via API)
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

            // Fetch node status and normalize to processors
            $nodes = $client->get('/nodes');
            $processors = $this->normalizeData('Proxmox\NodeStatus', $nodes);

            return $processors ?? [];
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
     */
    public function discoverMempools()
    {
        if (!$this->hasApiConfig()) {
            return [];
        }

        try {
            $client = DeviceApiClientFactory::make($this->getDevice());
            if (!$client || !in_array('mempools', $client->capabilities())) {
                return [];
            }

            // Fetch node status and normalize to mempools
            $nodes = $client->get('/nodes');
            $mempools = $this->normalizeData('Proxmox\NodeStatus', $nodes);

            return $mempools ?? [];
        } catch (\Exception $e) {
            \Log::warning('Proxmox mempool discovery failed', [
                'device_id' => $this->getDevice()->device_id,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Discover sensors (via API)
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
            $clusterSensors = $this->normalizeData('Proxmox\ClusterStatus', $clusterStatus);

            if (!empty($clusterSensors)) {
                $sensors = array_merge($sensors, $clusterSensors);
            }

            // Fetch node status for additional sensors
            $nodes = $client->get('/nodes');
            $nodeSensors = $this->normalizeData('Proxmox\NodeStatus', $nodes);

            if (!empty($nodeSensors)) {
                $sensors = array_merge($sensors, $nodeSensors);
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
     */
    public function discoverStorage()
    {
        if (!$this->hasApiConfig()) {
            return [];
        }

        try {
            $client = DeviceApiClientFactory::make($this->getDevice());
            if (!$client || !in_array('storage', $client->capabilities())) {
                return [];
            }

            // Fetch storage status from each node
            $nodes = $client->get('/nodes');
            $storage = [];

            foreach ($nodes['data'] ?? [] as $node) {
                $nodeName = $node['node'] ?? null;
                if (!$nodeName) {
                    continue;
                }

                try {
                    $storageData = $client->get("/nodes/{$nodeName}/storage");
                    $nodeStorage = $this->normalizeData('Proxmox\StorageStatus', $storageData);

                    if (!empty($nodeStorage)) {
                        $storage = array_merge($storage, $nodeStorage);
                    }
                } catch (\Exception $e) {
                    \Log::debug("Proxmox storage discovery failed for node {$nodeName}", [
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return $storage;
        } catch (\Exception $e) {
            \Log::warning('Proxmox storage discovery failed', [
                'device_id' => $this->getDevice()->device_id,
                'error' => $e->getMessage(),
            ]);
            return [];
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
