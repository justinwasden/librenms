<?php

namespace LibreNMS\Util\Normalizers\NetApp;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * NetApp - DeviceInfo Normalizer
 *
 * Capability: device_info
 * Vendor: netapp
 */
class DeviceInfo extends BaseNormalizer
{
    protected string $capability = 'device_info';
    protected string $vendor = 'netapp';

    protected function doNormalize(Device $device, array $payload): array
    {
$deviceInfo = [];

        // Handle both cluster-level endpoint (/cluster) and node-level endpoint (/cluster/nodes)
        $records = $payload['records'] ?? $payload['items'] ?? [];

        // If we have records, it's the nodes endpoint - get the first node
        // Otherwise, it's the cluster endpoint - use the payload directly
        $data = is_array($records) && isset($records[0]) ? $records[0] : $payload;

        if (empty($data)) {
            return $deviceInfo;
        }

        // System Name (hostname) - Construct FQDN from cluster name + DNS domain
        if (isset($payload['name'])) {
            $clusterName = $payload['name'];
            $dnsDomains = $payload['dns_domains'] ?? [];

            if (!empty($dnsDomains) && is_array($dnsDomains)) {
                // Construct FQDN: cluster-name.domain
                $deviceInfo['sysName'] = strtolower($clusterName) . '.' . $dnsDomains[0];
            } else {
                // Just use cluster name if no DNS domain available
                $deviceInfo['sysName'] = $clusterName;
            }
        }

        // Version - Extract full version string
        if (isset($payload['version']['full'])) {
            $deviceInfo['version'] = $payload['version']['full'];
        } elseif (isset($data['version']['full'])) {
            $deviceInfo['version'] = $data['version']['full'];
        }

        // Hardware/Model - from node data
        if (isset($data['model'])) {
            $deviceInfo['hardware'] = $data['model'];
        }

        // Serial number - from node data
        if (isset($data['serial_number'])) {
            $deviceInfo['serial'] = $data['serial_number'];
        } elseif (isset($data['serial'])) {
            $deviceInfo['serial'] = $data['serial'];
        }

        // System Object ID (NetApp OID)
        // NetApp enterprise OID: .1.3.6.1.4.1.789
        $deviceInfo['sysObjectID'] = '.1.3.6.1.4.1.789';

        // System Contact - prefer cluster-level, fallback to node-level
        if (isset($payload['contact'])) {
            $deviceInfo['sysContact'] = $payload['contact'];
        } elseif (isset($data['contact'])) {
            $deviceInfo['sysContact'] = $data['contact'];
        }

        // Location - prefer cluster-level, fallback to node-level
        if (isset($payload['location'])) {
            $deviceInfo['location'] = $payload['location'];
        } elseif (isset($data['location'])) {
            $deviceInfo['location'] = $data['location'];
        }

        // Uptime - from node data
        if (isset($data['uptime'])) {
            $deviceInfo['uptime'] = (int) $data['uptime'];
        }

        // System Description - Build from available information
        $sysDescrParts = [];
        if (isset($deviceInfo['version'])) {
            $sysDescrParts[] = $deviceInfo['version'];
        }
        if (isset($deviceInfo['sysName'])) {
            $sysDescrParts[] = 'System Name: ' . $deviceInfo['sysName'];
        }
        if (isset($deviceInfo['hardware'])) {
            $sysDescrParts[] = $deviceInfo['hardware'];
        }

        if (!empty($sysDescrParts)) {
            $deviceInfo['sysDescr'] = implode("\n", $sysDescrParts);
        }

        return $deviceInfo;
    }
}
