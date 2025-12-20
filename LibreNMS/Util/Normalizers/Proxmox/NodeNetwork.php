<?php

namespace LibreNMS\Util\Normalizers\Proxmox;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * Proxmox - NodeNetwork Normalizer
 *
 * Capability: ports
 * Vendor: proxmox
 */
class NodeNetwork extends BaseNormalizer
{
    protected string $capability = 'ports';
    protected string $vendor = 'proxmox';

    protected function doNormalize(Device $device, array $payload): array
    {
// Handle both old signature (array $payload) and new signature ($device, array $payload)
        if (is_array($device) && $payload === null) {
            // Old signature: called with just payload
            $payload = $device;
            $deviceId = 0; // No device ID available in old signature
        } else {
            // New signature: called with device and payload
            $deviceId = is_object($device) ? $device->device_id : ($device['device_id'] ?? 0);
        }

        $ports = [];
        $interfaceData = []; // Collect all data for each interface, merging best information

        if (!isset($payload['data']) || !is_array($payload['data'])) {
            return $ports;
        }

        // First pass: collect and merge interface data
        // Proxmox API may return multiple entries for the same interface with different IPs
        // We want to merge the best information from all entries
        foreach ($payload['data'] as $iface) {
            $rawName = $iface['iface'] ?? '';
            $name = trim($rawName);
            if (!$name || strtolower($name) === 'lo') {
                continue;
            }

            // Use normalized name as key for matching, but preserve original for display
            $key = $name; // Use trimmed name as key for consistent matching

            // Extract MAC address from altnames if not in hwaddr
            // Proxmox often stores MAC in altnames like "enx0025b518a0ed"
            $hwaddr = $iface['hwaddr'] ?? '';
            if (empty($hwaddr) && !empty($iface['altnames'])) {
                foreach ($iface['altnames'] as $altname) {
                    // Check if altname starts with "enx" followed by 12 hex chars (MAC)
                    if (preg_match('/^enx([0-9a-f]{12})$/i', $altname, $matches)) {
                        $hwaddr = $matches[1];
                        break;
                    }
                }
            }

            // Parse bridge_ports and VLAN info for additional details
            $bridgePorts = [];
            if (!empty($iface['bridge_ports'])) {
                $bridgePorts = array_map('trim', explode(' ', $iface['bridge_ports']));
            }

            // Initialize interface data if not seen before
            if (!isset($interfaceData[$key])) {
                $interfaceData[$key] = [
                    'iface' => $name, // Store trimmed name (original without whitespace)
                    'type' => $iface['type'] ?? 'unknown',
                    'active' => $iface['active'] ?? 0,
                    'autostart' => $iface['autostart'] ?? 0,
                    'mtu' => $iface['mtu'] ?? 1500,
                    'hwaddr' => $hwaddr,
                    'comments' => $iface['comments'] ?? '',
                    'bridge_ports' => $bridgePorts,
                    'vlan_id' => $iface['vlan-id'] ?? '',
                    'vlan_raw_device' => $iface['vlan-raw-device'] ?? '',
                ];
            } else {
                // Merge data: prefer non-empty values and better information
                // Prefer MAC address if available
                if (empty($interfaceData[$key]['hwaddr']) && !empty($hwaddr)) {
                    $interfaceData[$key]['hwaddr'] = $hwaddr;
                }
                // Prefer comments if available (longer/comments are usually more descriptive)
                if (empty($interfaceData[$key]['comments']) && !empty($iface['comments'])) {
                    $interfaceData[$key]['comments'] = $iface['comments'];
                } elseif (!empty($iface['comments']) && strlen($iface['comments']) > strlen($interfaceData[$key]['comments'])) {
                    // If both have comments, prefer the longer one (more descriptive)
                    $interfaceData[$key]['comments'] = $iface['comments'];
                }
                // Prefer active status (if any entry is active, mark as active)
                if (($iface['active'] ?? 0) && !$interfaceData[$key]['active']) {
                    $interfaceData[$key]['active'] = 1;
                }
                // Prefer autostart if set
                if (($iface['autostart'] ?? 0) && !$interfaceData[$key]['autostart']) {
                    $interfaceData[$key]['autostart'] = 1;
                }
                // Prefer larger MTU (usually more accurate)
                if (($iface['mtu'] ?? 0) > ($interfaceData[$key]['mtu'] ?? 0)) {
                    $interfaceData[$key]['mtu'] = $iface['mtu'];
                }
                // Preserve type if current is 'unknown' and new one is not
                if ($interfaceData[$key]['type'] === 'unknown' && !empty($iface['type'])) {
                    $interfaceData[$key]['type'] = $iface['type'];
                }
                // Merge bridge ports
                if (empty($interfaceData[$key]['bridge_ports']) && !empty($bridgePorts)) {
                    $interfaceData[$key]['bridge_ports'] = $bridgePorts;
                }
                // Merge VLAN info
                if (empty($interfaceData[$key]['vlan_id']) && !empty($iface['vlan-id'])) {
                    $interfaceData[$key]['vlan_id'] = $iface['vlan-id'];
                }
                if (empty($interfaceData[$key]['vlan_raw_device']) && !empty($iface['vlan-raw-device'])) {
                    $interfaceData[$key]['vlan_raw_device'] = $iface['vlan-raw-device'];
                }
            }
        }

        // Second pass: create port entries from merged interface data
        foreach ($interfaceData as $name => $iface) {
            $active = $iface['active'] ? 'up' : 'down';
            $type = $iface['type'];

            // Get MAC address and normalize format
            $macAddress = '';
            if (!empty($iface['hwaddr'])) {
                $macAddress = strtolower(trim($iface['hwaddr']));
                // Normalize MAC address format (remove colons, dashes, spaces, then add colons)
                $macAddress = preg_replace('/[^0-9a-f]/i', '', $macAddress);
                if (strlen($macAddress) === 12) {
                    $macAddress = implode(':', str_split($macAddress, 2));
                }
            }

            // Use stable index based on interface name AND device_id for unique port matching across cluster nodes
            // This prevents ifIndex collisions when multiple Proxmox nodes have identically named interfaces
            $ifIndex = $this->stableIndexFromName($deviceId . ':' . $name);

            // Build description with type and additional info
            $description = $name;
            $descParts = [];

            if ($type && $type !== 'unknown') {
                $descParts[] = ucfirst($type);
            }

            // Add VLAN info for VLAN interfaces
            if ($type === 'vlan' && !empty($iface['vlan_id'])) {
                $descParts[] = "VLAN {$iface['vlan_id']}";
                if (!empty($iface['vlan_raw_device'])) {
                    $descParts[] = "on {$iface['vlan_raw_device']}";
                }
            }

            // Add bridge ports for bridges
            if ($type === 'bridge' && !empty($iface['bridge_ports'])) {
                $descParts[] = 'ports: ' . implode(', ', $iface['bridge_ports']);
            }

            // Add comment if available
            $comment = trim($iface['comments']);
            if (!empty($comment)) {
                $descParts[] = $comment;
            }

            if (!empty($descParts)) {
                $description = $name . ' (' . implode(', ', $descParts) . ')';
            }

            // Determine interface type for ifType field
            $ifType = 'ethernetCsmacd';
            if ($type === 'bridge') {
                $ifType = 'bridge';
            } elseif ($type === 'bond') {
                $ifType = 'ieee8023adLag';
            } elseif ($type === 'vlan') {
                $ifType = 'l2vlan';
            } elseif ($type === 'eth') {
                $ifType = 'ethernetCsmacd';
            }

            // Determine port speed - default to 10Gbps if MTU is 9000 (jumbo frames often used for 10G)
            $ifSpeed = 1000000000; // 1Gbps default
            if ($iface['mtu'] >= 9000) {
                $ifSpeed = 10000000000; // 10Gbps for jumbo frame interfaces
            }

            $ports[] = [
                'ifIndex' => $ifIndex,
                'ifName' => $name,
                'ifDescr' => $description,
                'ifType' => $ifType,
                'ifSpeed' => $ifSpeed,
                'ifOperStatus' => $active,
                'ifAdminStatus' => $iface['autostart'] ? 'up' : 'down',
                'ifMtu' => $iface['mtu'],
                'ifPhysAddress' => $macAddress,
                'ifAlias' => $comment,
                'ifLastChange' => 0,
            ];
        }

        return $ports;
    }
}
