<?php

namespace LibreNMS\Util\Normalizers;

use App\Models\Device;

class NetAppNormalizer
{
    /**
     * Normalize NetApp cluster/node information to inventory
     * Input: GET /cluster/nodes 
     */
    public static function normalizeClusterNodes(Device $device, array $payload, array $ep = []): array
    {
        $inventory = [];
        $records = $payload['records'] ?? [];

        foreach ($records as $idx => $node) {
            $inventory[] = [
                'entPhysicalIndex' => $idx + 1000,
                'entPhysicalDescr' => $node['name'] ?? 'Node',
                'entPhysicalClass' => 'chassis',
                'entPhysicalName' => $node['name'] ?? '',
                'entPhysicalModelName' => $node['model'] ?? '',
                'entPhysicalSerialNum' => $node['serial_number'] ?? '',
                'entPhysicalContainedIn' => 0,
                'entPhysicalMfgName' => 'NetApp',
                'entPhysicalParentRelPos' => -1,
                'entPhysicalVendorType' => 'netapp.node',
                'entPhysicalHardwareRev' => null,
                'entPhysicalFirmwareRev' => $node['version']['full'] ?? null,
                'entPhysicalSoftwareRev' => null,
                'entPhysicalIsFRU' => true,
                'entPhysicalAlias' => null,
                'entPhysicalAssetID' => null,
            ];
        }

        return $inventory;
    }

    /**
     * Normalize NetApp volumes to storage entries
     * Input: GET /storage/volumes
     */
    public static function normalizeVolumes(Device $device, array $payload, array $ep = []): array
    {
        $storage = [];
        $records = $payload['records'] ?? [];

        foreach ($records as $volume) {
            $name = $volume['name'] ?? 'Unknown';
            $size = $volume['size'] ?? 0;
            $used = $volume['space']['used'] ?? 0;
            $available = $volume['space']['available'] ?? ($size - $used);

            $storage[] = [
                'storage_descr' => $name,
                'storage_type' => 'netapp-volume',
                'storage_index' => $volume['uuid'] ?? $name,
                'storage_size' => $size,
                'storage_used' => $used,
                'storage_free' => $available,
                'storage_units' => 1,
            ];
        }

        return $storage;
    }

    /**
     * Normalize NetApp network ports to LibreNMS ports
     * Input: GET /network/ethernet/ports
     */
    public static function normalizeNetworkPorts(Device $device, array $payload, array $ep = []): array
    {
        $ports = [];
        $records = $payload['records'] ?? [];

        foreach ($records as $idx => $port) {
            $portName = $port['name'] ?? 'port' . $idx;
            $nodeName = $port['node']['name'] ?? null;
            $enabled = $port['enabled'] ?? false;
            $state = $port['state'] ?? 'unknown';
            $speed = $port['speed'] ?? 0;

            // Convert speed to bps (ONTAP returns in Mbps)
            if ($speed > 0) {
                $speed = $speed * 1000000;
            }

            // Create unique ifName by including node name (since ports exist on multiple nodes)
            // This prevents duplicate ifName conflicts when saving
            $ifName = $nodeName ? "{$nodeName}:{$portName}" : $portName;

            // Build port description
            $descr = $port['type'] ?? $portName;
            if ($nodeName) {
                $descr = "{$nodeName}:{$portName}";
            }

            // Generate stable ifIndex from port UUID
            // Use crc32 hash of UUID to generate a stable ifIndex
            $uuid = $port['uuid'] ?? $ifName;
            $ifIndex = abs(crc32($uuid)) % 100000; // Keep it under 100000 for reasonable values

            $ports[] = [
                'uuid' => $uuid, // Add the UUID here for for_each loops
                'ifIndex' => $ifIndex,
                'ifName' => $ifName,
                'ifDescr' => $descr,
                'ifAlias' => isset($port['broadcast_domain']['name']) ? $port['broadcast_domain']['name'] : null,
                'ifType' => 'ethernetCsmacd',
                'ifOperStatus' => $state === 'up' ? 'up' : 'down',
                'ifAdminStatus' => $enabled ? 'up' : 'down',
                'ifSpeed' => $speed,
                'ifMtu' => $port['mtu'] ?? 1500,
                'ifPhysAddress' => $port['mac_address'] ?? '',
            ];
        }

        return $ports;
    }

    /**
     * Normalize NetApp IPv4 addresses
     * Input: GET /network/ip/interfaces
     */
    public static function normalizeIpv4(Device $device, array $payload, array $ep = []): array
    {
        $addresses = [];
        $records = $payload['records'] ?? [];

        foreach ($records as $interface) {
            $ip = $interface['ip']['address'] ?? null;
            $netmask = $interface['ip']['netmask'] ?? null;

            if (!$ip || !$netmask) {
                continue;
            }

            // Convert netmask to prefix length
            $prefixLen = self::netmaskToCidr($netmask);

            $addresses[] = [
                'ifName' => $interface['name'] ?? 'unknown',
                'ipv4_address' => $ip,
                'ipv4_prefixlen' => $prefixLen,
                'context_name' => 'netapp',
            ];
        }

        return $addresses;
    }

    public static function normalizePortMetrics(Device $device, array $payload, array $ep = []): array
    {
        $metrics = [];
        $records = $payload['records'] ?? [];

        foreach ($records as $metric) {
            $portUuid = $metric['uuid'] ?? null;
            if (!$portUuid) {
                continue;
            }

            // Throughput metrics are often counters, handle accordingly
            $metrics[] = [
                'port_id' => $portUuid, // Used to match with existing port
                'ifInOctets' => $metric['throughput']['total'] ?? 0,
                'ifOutOctets' => $metric['throughput']['total'] ?? 0, // Adjust if separate in/out available
                'ifInErrors' => $metric['errors']['total'] ?? 0,
                'ifOutErrors' => $metric['errors']['total'] ?? 0, // Adjust if separate in/out available
                'ifHCInOctets' => $metric['throughput']['total'] ?? 0,
                'ifHCOutOctets' => $metric['throughput']['total'] ?? 0, // Adjust if separate in/out available
            ];
        }

        return $metrics;
    }

    /**
     * Normalize NetApp storage array details (controllers, volumes, hosts)
     * Input: GET /cluster (or any endpoint - fetches what we need)
     */
    public static function normalizeStorageDetails(Device $device, array $payload, array $ep = []): array
    {
        try {
            // Get the NetApp ONTAP client instance
            $client = \App\ApiClients\DeviceApiClientFactory::make($device);
            if (!$client || !method_exists($client, 'fetchControllers')) {
                \Log::warning("NetApp normalizeStorageDetails: client does not support fetchControllers");
                return [];
            }

            // Fetch detailed information using client methods
            $controllers = $client->fetchControllers($device);
            $volumes = $client->fetchVolumes($device);
            $hosts = $client->fetchHosts($device);

            // Return structured response
            return [
                'controllers' => $controllers,
                'volumes' => $volumes,
                'hosts' => $hosts,
            ];
        } catch (\Throwable $e) {
            \Log::error("NetApp normalizeStorageDetails failed for device {$device->device_id}: {$e->getMessage()}");
            return [];
        }
    }

    /**
     * Normalize NetApp cluster sensors (CPU, memory, etc.)
     * Input: GET /cluster/nodes with performance metrics
     */
    public static function normalizeClusterMetrics(Device $device, array $payload, array $ep = []): array
    {
        $sensors = [];
        $records = $payload['records'] ?? [];

        foreach ($records as $idx => $node) {
            $nodeName = $node['name'] ?? "node{$idx}";

            // CPU usage - use processor_utilization (percentage) instead of processor_utilization_raw (cumulative microseconds)
            if (isset($node['statistics']['processor_utilization'])) {
                $sensors[] = [
                    'sensor_class' => 'load',
                    'sensor_type' => 'netapp',
                    'sensor_descr' => "{$nodeName} CPU Usage",
                    'sensor_index' => "cpu.{$nodeName}",
                    'sensor_current' => $node['statistics']['processor_utilization'],
                    'sensor_limit' => 100,
                    'sensor_limit_low' => 0,
                ];
            } elseif (isset($node['statistics']['processor_utilization_raw'])) {
                // Fallback: processor_utilization_raw is a counter and shouldn't be used as-is
                // Log warning but don't create invalid sensor
                \Log::warning("NetApp CPU: processor_utilization not available for {$nodeName}, skipping CPU sensor");
            }
        }

        return $sensors;
    }

    /**
     * Normalize NetApp cluster processors (CPU)
     * Input: GET /cluster/nodes?fields=statistics
     */
    public static function normalizeClusterProcessors(Device $device, array $payload, array $ep = []): array
    {
        $processors = [];
        $records = $payload['records'] ?? [];

        foreach ($records as $idx => $node) {
            $nodeName = $node['name'] ?? "node{$idx}";

            // CPU usage - use processor_utilization (percentage) instead of processor_utilization_raw (cumulative microseconds)
            if (isset($node['statistics']['processor_utilization'])) {
                $cpuUsage = $node['statistics']['processor_utilization'];

                $processors[] = [
                    'processor_index' => "netapp-node-{$idx}",
                    'processor_type' => 'netapp-cpu',
                    'processor_descr' => "{$nodeName} CPU",
                    'processor_usage' => $cpuUsage,
                ];
            }
        }

        return $processors;
    }

    /**
     * Normalize NetApp cluster memory
     * Input: GET /cluster/nodes?fields=statistics
     */
    public static function normalizeClusterMempools(Device $device, array $payload, array $ep = []): array
    {
        $mempools = [];
        $records = $payload['records'] ?? [];

        foreach ($records as $idx => $node) {
            $nodeName = $node['name'] ?? "node{$idx}";

            // Memory statistics
            $memTotal = $node['statistics']['memory_size'] ?? 0; // in bytes
            $memUsed = $node['statistics']['memory_used'] ?? 0; // in bytes

            if ($memTotal > 0) {
                $memFree = $memTotal - $memUsed;
                $memPerc = ($memUsed / $memTotal) * 100;

                $mempools[] = [
                    'mempool_index' => "netapp-node-{$idx}",
                    'mempool_type' => 'netapp-memory',
                    'mempool_descr' => "{$nodeName} Memory",
                    'mempool_total' => $memTotal,
                    'mempool_used' => $memUsed,
                    'mempool_free' => $memFree,
                    'mempool_perc' => $memPerc,
                ];
            }
        }

        return $mempools;
    }

    /**
     * Convert dotted decimal netmask to CIDR prefix length
     */
    protected static function netmaskToCidr(string $netmask): int
    {
        $long = ip2long($netmask);
        if ($long === false) {
            return 24; // Default to /24 if invalid
        }

        $cidr = 0;
        for ($i = 0; $i < 32; $i++) {
            if (($long & (1 << (31 - $i))) !== 0) {
                $cidr++;
            } else {
                break;
            }
        }

        return $cidr;
    }
}
