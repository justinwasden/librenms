<?php

namespace LibreNMS\Util\Normalizers\Fortinet;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * Fortinet - Interfaces Normalizer
 *
 * Capability: ports
 * Vendor: fortigate
 */
class Interfaces extends BaseNormalizer
{
    protected string $capability = 'ports';
    protected string $vendor = 'fortigate';

    protected function doNormalize(Device $device, array $payload): array
    {
$ports = [];
        $ipv4_addresses = [];
        $ipv4_mac = [];
        $ports_statistics = [];
        $transceivers = [];

        $results = $payload['results'] ?? $payload;
        if (!is_array($results)) {
            return ['ports' => $ports];
        }

        foreach ($results as $idx => $iface) {
            $name = $iface['name'] ?? "port_$idx";

            // FortiGate uses 'link' (boolean) instead of 'status' (string)
            // link: true = interface is up, link: false = interface is down
            $linkUp = $iface['link'] ?? false;
            $status = $linkUp ? 'up' : 'down';

            // Also check for 'status' field for other FortiGate API versions
            if (isset($iface['status'])) {
                $status = strtolower($iface['status']) === 'up' ? 'up' : 'down';
            }

            $ifIndex = $this->stableIndexFromName($name);

            // Parse speed - handle numeric values and string formats like "1000", "auto", "1G", etc.
            $speed = $iface['speed'] ?? 1000;
            if (is_numeric($speed)) {
                $speedBps = ((int)$speed) * 1000000; // Mbps to bps
            } else {
                // Handle string formats like "auto", "1G", etc. - default to 1Gbps
                $speedBps = 1000000000;
            }

            // Use alias for ifDescr, fallback to name if alias is empty
            $alias = $iface['alias'] ?? '';
            $ifDescr = !empty($alias) ? $alias : $name;

            // Use MAC address from 'mac' field (FortiGate specific)
            $macAddr = $iface['mac'] ?? $iface['macaddr'] ?? '';

            $ports[] = [
                'ifIndex' => $ifIndex,
                'ifName' => $name,
                'ifDescr' => $ifDescr,
                'ifType' => $iface['type'] ?? 'ethernetCsmacd',
                'ifSpeed' => $speedBps,
                'ifOperStatus' => $status,
                'ifAdminStatus' => $status,
                'ifMtu' => $iface['mtu'] ?? 1500,
                'ifPhysAddress' => $macAddr,
                'ifAlias' => $alias,
                'ifLastChange' => 0,
            ];

            // Extract IPv4 addresses if present
            if (isset($iface['ip']) && $iface['ip'] !== '0.0.0.0') {
                $ip = $iface['ip'];
                if (strpos($ip, '/') !== false) {
                    [$ipAddr, $prefixLen] = explode('/', $ip, 2);
                } else {
                    $ipAddr = $ip;
                    $prefixLen = isset($iface['netmask']) ? $this->netmaskToCidr($iface['netmask']) : 24;
                }

                $ipv4_addresses[] = [
                    'ifIndex' => $ifIndex,
                    'ipv4_address' => $ipAddr,
                    'ipv4_prefixlen' => $prefixLen,
                    'context_name' => '',
                ];
            }

            // Extract MAC address mappings if present (ARP data)
            if (isset($iface['arp']) && is_array($iface['arp'])) {
                foreach ($iface['arp'] as $arp) {
                    if (isset($arp['mac'], $arp['ip'])) {
                        $ipv4_mac[] = [
                            'ifIndex' => $ifIndex,
                            'mac_address' => $arp['mac'],
                            'ipv4_address' => $arp['ip'],
                            'context_name' => '',
                        ];
                    }
                }
            }

            // Extract traffic statistics if present
            if (isset($iface['rx_bytes']) || isset($iface['tx_bytes'])) {
                $ports_statistics[] = [
                    'ifIndex' => $ifIndex,
                    'ifName' => $name,  // Include ifName for matching when ifIndex doesn't match existing ports
                    'ifInOctets' => $iface['rx_bytes'] ?? 0,
                    'ifOutOctets' => $iface['tx_bytes'] ?? 0,
                    'ifInErrors' => $iface['rx_errors'] ?? 0,
                    'ifOutErrors' => $iface['tx_errors'] ?? 0,
                    'ifInUcastPkts' => $iface['rx_packets'] ?? 0,
                    'ifOutUcastPkts' => $iface['tx_packets'] ?? 0,
                    'ifInDiscards' => $iface['rx_dropped'] ?? 0,
                    'ifOutDiscards' => $iface['tx_dropped'] ?? 0,
                ];
            }

            // Extract transceiver/SFP data if present
            if (isset($iface['sfp']) && is_array($iface['sfp'])) {
                $sfp = $iface['sfp'];
                $transceivers[] = [
                    'ifIndex' => $ifIndex,
                    'index' => $ifIndex,
                    'type' => $sfp['type'] ?? null,
                    'vendor' => $sfp['vendor'] ?? null,
                    'model' => $sfp['part_number'] ?? null,
                    'serial' => $sfp['serial'] ?? null,
                    'connector' => $sfp['connector'] ?? null,
                ];
            }
        }

        // Return structured response with all available data
        $response = ['ports' => $ports];
        if (!empty($ipv4_addresses)) {
            $response['ipv4_addresses'] = $ipv4_addresses;
        }
        if (!empty($ipv4_mac)) {
            $response['ipv4_mac'] = $ipv4_mac;
        }
        if (!empty($ports_statistics)) {
            $response['ports_statistics'] = $ports_statistics;
        }
        if (!empty($transceivers)) {
            $response['transceivers'] = $transceivers;
        }

        return $response;
    }
}
