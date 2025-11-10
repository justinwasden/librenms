<?php

namespace LibreNMS\Util\Normalizers;

use App\Models\Device;

class PureStorageNormalizer
{
    /**
     * Normalize Pure Storage network interfaces into LibreNMS ports rows.
     * Input payload: GET /network-interfaces
     * Output: flat array for DeviceApiPersistor::savePorts()
     */
    public static function normalizeNetworkInterfaces(Device $device, array $payload, array $ep = []): array
    {
        $items = [];
        if (isset($payload['items']) && is_array($payload['items'])) {
            $items = $payload['items'];
        } elseif (!empty($payload)) {
            $items = is_array($payload) ? $payload : [$payload];
        }

        $ports = [];
        foreach ($items as $idx => $interface) {
            $name = strtolower($interface['name'] ?? ('port' . $idx));
            $eth  = $interface['eth'] ?? [];
            $services = $interface['services'] ?? [];

            // Map interface_type to ifType. Pure returns 'eth' or 'fc'.
            $ifType = 'ethernetCsmacd';
            if (!empty($interface['interface_type']) && strtolower($interface['interface_type']) === 'fc') {
                $ifType = 'fiberChannel';
            }

            // Extract VLAN ID from port name if it follows pattern: ethX.VLAN or ethX.Y.VLAN
            // Examples: ct0.eth19.315, ct1.eth18.323
            $ifVlan = null;
            if (preg_match('/\.(\d+)$/', $name, $matches)) {
                $ifVlan = (int) $matches[1];
            }

            $ports[] = [
                'ifIndex'       => $idx + 1,
                'ifName'        => $name,
                'ifDescr'       => $name,
                'ifAlias'       => implode(',', $services),
                'ifType'        => $ifType,
                'ifOperStatus'  => (!empty($interface['enabled'])) ? 'up' : 'down',
                'ifAdminStatus' => (!empty($interface['enabled'])) ? 'up' : 'down',
                'ifSpeed'       => $interface['speed'] ?? 0,                         // bps
                'ifMtu'         => $eth['mtu'] ?? null,
                'ifPhysAddress' => $eth['mac_address'] ?? '',
                'ifVlan'        => $ifVlan,
            ];
        }

        return $ports;
    }

    /**
     * Normalize Pure Storage IPv4 addresses per interface.
     * Input payload: GET /network-interfaces
     * Output: flat array for DeviceApiPersistor::saveIpv4Addresses()
     */
//    public static function normalizeIpv4(Device $device, array $payload, array $ep = []): array
//    {
//        $items = [];
//        if (isset($payload['items']) && is_array($payload['items'])) {
//            $items = $payload['items'];
//        } elseif (!empty($payload)) {
//            $items = is_array($payload) ? $payload : [$payload];
//        }
//
//        $addresses = [];
//        foreach ($items as $idx => $interface) {
//            $name = strtolower($interface['name'] ?? ('port' . $idx));
//            $eth  = $interface['eth'] ?? [];
//
//            $ip = $eth['address'] ?? null;
//            $mask = $eth['netmask'] ?? null;
//            if (!$ip) {
//                continue;
//            }
//
//            $addresses[] = [
//                'ifName'         => $name,
//                'ipv4_address'   => $ip,
//                'netmask'        => $mask,
//                'context_name'   => 'purestorage',
//            ];
//        }
//
//        return $addresses;
//    }
//
		public static function normalizeIpv4(Device $device, array $payload, array $ep = []): array
		{
		$items = [];
		if (isset($payload['items']) && is_array($payload['items'])) {
		$items = $payload['items'];
		} elseif (!empty($payload)) {
		$items = is_array($payload) ? $payload : [$payload];
		}

		$addresses = [];
		foreach ($items as $idx => $interface) {
		    $name = strtolower($interface['name'] ?? ('port' . $idx));
		    $eth  = $interface['eth'] ?? [];

		    $ip   = $eth['address'] ?? null;
		    $mask = $eth['netmask'] ?? null;
		    if (!$ip) {
		        continue;
		    }

		    // Convert dotted netmask to CIDR if present, else default to 24
		    $prefixlen = 24;
		    if ($mask !== null) {
		        $prefixlen = self::netmaskToCidr((string) $mask);
		    } elseif (isset($eth['prefixlen'])) {
		        $prefixlen = (int) $eth['prefixlen'];
		    }

		    $addresses[] = [
		        // Persistor will resolve port_id via ifName or ifIndex
		        'ifName'        => $name,
		        'ipv4_address'  => $ip,
		        'ipv4_prefixlen'=> $prefixlen,
		        'context_name'  => 'purestorage',
		    ];
		}

		return $addresses;

		}
    /**
     * Normalize VLANs from Pure Storage network interface names.
     * Input payload: GET /network-interfaces
     * Output: flat array of VLANs for DeviceApiPersistor::saveVlans()
     */
    public static function normalizeVlans(Device $device, array $payload, array $ep = []): array
    {
        $items = [];
        if (isset($payload['items']) && is_array($payload['items'])) {
            $items = $payload['items'];
        } elseif (!empty($payload)) {
            $items = is_array($payload) ? $payload : [$payload];
        }

        $vlans = [];
        $seen_vlans = [];

        foreach ($items as $idx => $interface) {
            $name = strtolower($interface['name'] ?? '');
            if (empty($name)) {
                continue;
            }

            // Extract VLAN ID from port name (e.g., ct0.eth19.315 -> 315)
            if (preg_match('/\.(\d+)$/', $name, $matches)) {
                $vlan_id = (int) $matches[1];

                // Only add each VLAN once
                if (!isset($seen_vlans[$vlan_id])) {
                    $vlans[] = [
                        'vlan_vlan' => $vlan_id,
                        'vlan_name' => "VLAN{$vlan_id}",
                        'vlan_type' => 'ethernet',
                    ];
                    $seen_vlans[$vlan_id] = true;
                }
            }
        }

        return $vlans;
    }

    /**
     * Normalize Pure Storage per-interface traffic into sensors (rates).
     * Input payload: GET /network-performance
     * Output: flat sensors array for DeviceApiPersistor::saveSensors()
     */
    public static function normalizeNetworkPerformance(Device $device, array $payload, array $ep = []): array
    {
        $items = [];
        if (isset($payload['items']) && is_array($payload['items'])) {
            $items = $payload['items'];
        } elseif (!empty($payload)) {
            $items = is_array($payload) ? $payload : [$payload];
        }

        $sensors = [];
        foreach ($items as $it) {
            $name = strtolower($it['name'] ?? '');
            $eth  = $it['eth'] ?? [];
            if (!$name) {
                continue;
            }

            $mk = function (string $descr, $value, string $indexSuffix, string $class = 'count', string $type = 'purestorage') use ($name) {
                if ($value === null) {
                    return null;
                }
                return [
                    'sensor_class'  => $class,
                    'sensor_type'   => $type,
                    'sensor_descr'  => sprintf('%s %s', $name, $descr),
                    'sensor_index'  => sprintf('pure_net:%s:%s', $name, $indexSuffix),
                    'sensor_current'=> $value,
                    'rrd_type'      => 'GAUGE',
                ];
            };

            $in_bps   = $eth['received_bytes_per_sec'] ?? null;
            $out_bps  = $eth['transmitted_bytes_per_sec'] ?? null;
            $in_pkts  = $eth['received_packets_per_sec'] ?? null;
            $out_pkts = $eth['transmitted_packets_per_sec'] ?? null;

            $in_err = null;
            $sum_in_err = 0;
            if (isset($eth['received_crc_errors_per_sec'])) {
                $sum_in_err += (float) $eth['received_crc_errors_per_sec'];
            }
            if (isset($eth['received_frame_errors_per_sec'])) {
                $sum_in_err += (float) $eth['received_frame_errors_per_sec'];
            }
            if ($sum_in_err > 0) {
                $in_err = $sum_in_err;
            } elseif (isset($eth['total_errors_per_sec'])) {
                $in_err = $eth['total_errors_per_sec'];
            }

            $out_err = null;
            $sum_out_err = 0;
            if (isset($eth['transmitted_dropped_errors_per_sec'])) {
                $sum_out_err += (float) $eth['transmitted_dropped_errors_per_sec'];
            }
            if (isset($eth['transmitted_carrier_errors_per_sec'])) {
                $sum_out_err += (float) $eth['transmitted_carrier_errors_per_sec'];
            }
            if ($sum_out_err > 0) {
                $out_err = $sum_out_err;
            }

            foreach ([
                $mk('In Bytes/s', $in_bps,  'in_bytes_per_sec'),
                $mk('Out Bytes/s', $out_bps,'out_bytes_per_sec'),
                $mk('In Packets/s', $in_pkts,  'in_pkts_per_sec'),
                $mk('Out Packets/s', $out_pkts,'out_pkts_per_sec'),
                $mk('In Errors/s', $in_err,    'in_errors_per_sec'),
                $mk('Out Errors/s', $out_err,  'out_errors_per_sec'),
            ] as $row) {
                if ($row) {
                    $sensors[] = $row;
                }
            }
        }

        return $sensors;
    }

    /**
     * Normalize Pure Storage per-interface traffic into ports_statistics (rates for port pages).
     * Input payload: GET /network-performance
     * Output: structured array ['ports_statistics' => [...]] for DeviceApiPersistor::savePortsStatistics()
     */
    public static function normalizeNetworkPerformanceToPortsStats(Device $device, array $payload, array $ep = []): array
    {
        $items = [];
        if (isset($payload['items']) && is_array($payload['items'])) {
            $items = $payload['items'];
        } elseif (!empty($payload)) {
            $items = is_array($payload) ? $payload : [$payload];
        }

        $stats = [];
        foreach ($items as $it) {
            $name = strtolower($it['name'] ?? '');
            $eth  = $it['eth'] ?? [];
            if (!$name) {
                continue;
            }

            $time_ms = $it['time'] ?? null;
            $poll_time = $time_ms ? (int) floor(((int) $time_ms) / 1000) : time();
            $poll_period = 300; // default poll period if unknown

            $in_bytes_per_sec  = isset($eth['received_bytes_per_sec']) ? (float) $eth['received_bytes_per_sec'] : null;
            $out_bytes_per_sec = isset($eth['transmitted_bytes_per_sec']) ? (float) $eth['transmitted_bytes_per_sec'] : null;
            $in_pkts_per_sec   = isset($eth['received_packets_per_sec']) ? (float) $eth['received_packets_per_sec'] : null;
            $out_pkts_per_sec  = isset($eth['transmitted_packets_per_sec']) ? (float) $eth['transmitted_packets_per_sec'] : null;

            // Errors per sec
            $in_err_rate = null;
            $sum_in_err = 0.0;
            if (isset($eth['received_crc_errors_per_sec'])) {
                $sum_in_err += (float) $eth['received_crc_errors_per_sec'];
            }
            if (isset($eth['received_frame_errors_per_sec'])) {
                $sum_in_err += (float) $eth['received_frame_errors_per_sec'];
            }
            if ($sum_in_err > 0) {
                $in_err_rate = $sum_in_err;
            } elseif (isset($eth['total_errors_per_sec'])) {
                $in_err_rate = (float) $eth['total_errors_per_sec'];
            }

            $out_err_rate = null;
            $sum_out_err = 0.0;
            if (isset($eth['transmitted_dropped_errors_per_sec'])) {
                $sum_out_err += (float) $eth['transmitted_dropped_errors_per_sec'];
            }
            if (isset($eth['transmitted_carrier_errors_per_sec'])) {
                $sum_out_err += (float) $eth['transmitted_carrier_errors_per_sec'];
            }
            if ($sum_out_err > 0) {
                $out_err_rate = $sum_out_err;
            }

            $row = [
                'ifName' => $name,
                'poll_time' => $poll_time,
                'poll_period' => $poll_period,

                // Rates for octets and bits
                'ifInOctets_rate' => $in_bytes_per_sec,
                'ifOutOctets_rate' => $out_bytes_per_sec,
                'ifInBits_rate' => ($in_bytes_per_sec !== null) ? ($in_bytes_per_sec * 8.0) : null,
                'ifOutBits_rate' => ($out_bytes_per_sec !== null) ? ($out_bytes_per_sec * 8.0) : null,

                // Packet rates
                'ifInUcastPkts_rate' => $in_pkts_per_sec,
                'ifOutUcastPkts_rate' => $out_pkts_per_sec,

                // Error rates
                'ifInErrors_rate' => $in_err_rate,
                'ifOutErrors_rate' => $out_err_rate,
            ];

            $stats[] = $row;
        }

        return $stats;
    }

    /**
     * Convert dotted decimal netmask to CIDR prefix length
     *
     * @param string $netmask Dotted decimal netmask (e.g., "255.255.255.0")
     * @return int CIDR prefix length (e.g., 24)
     */
    protected static function netmaskToCidr(string $netmask): int
    {
        // Convert netmask to binary
        $long = ip2long($netmask);
        if ($long === false) {
            return 24; // Default to /24 if invalid
        }

        // Convert to binary string and count the number of 1s
        $base = ip2long('255.255.255.255');
        $netmask_binary = decbin($long);

        // Count consecutive 1s from the left
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

    /**
     * Normalize Pure Storage array details including controllers, volumes, and hosts.
     * Input payload: GET /arrays (or any endpoint - we'll fetch what we need)
     * Output: structured array with 'controllers', 'volumes', 'hosts' keys
     */
    public static function normalizeStorageDetails(Device $device, array $payload, array $ep = []): array
    {
        try {
            // Get the FlashArrayClient instance
            $client = \App\ApiClients\DeviceApiClientFactory::make($device);
            if (!$client || !method_exists($client, 'fetchControllers')) {
                \Log::warning("PureStorage normalizeStorageDetails: client does not support fetchControllers");
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
            \Log::error("PureStorage normalizeStorageDetails failed for device {$device->device_id}: {$e->getMessage()}");
            return [];
        }
    }
}