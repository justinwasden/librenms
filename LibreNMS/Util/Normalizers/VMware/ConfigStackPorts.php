<?php

namespace LibreNMS\Util\Normalizers\VMware;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * VMware - ConfigStackPorts Normalizer
 *
 * Capability: ports
 * Vendor: velocloud
 */
class ConfigStackPorts extends BaseNormalizer
{
    protected string $capability = 'ports';
    protected string $vendor = 'velocloud';

    protected function doNormalize(Device $device, array $payload): array
    {
$ports = [];

        // Get existing port labels from database to preserve them
        $existingLabels = [];
        if ($device && $device->device_id) {
            $existingPorts = \App\Models\Port::where('device_id', $device->device_id)
                ->whereNotNull('ifAlias')
                ->where('ifAlias', '!=', '')
                ->get(['ifName', 'ifAlias']);

            foreach ($existingPorts as $port) {
                $existingLabels[$port->ifName] = $port->ifAlias;
            }
        }

        // Get edge-specific config (first stack)
        $edgeConfig = $payload[0] ?? [];

        // Find deviceSettings module
        foreach ($edgeConfig['modules'] ?? [] as $module) {
            if (($module['name'] ?? '') === 'deviceSettings') {
                $routedInterfaces = $module['data']['routedInterfaces'] ?? [];

                foreach ($routedInterfaces as $idx => $intf) {
                    $ifName = $intf['name'] ?? "Interface$idx";
                    $addressing = $intf['addressing'] ?? [];
                    $l2 = $intf['l2'] ?? [];
                    $disabled = $intf['disabled'] ?? false;

                    // Map to LibreNMS port structure
                    $port = [
                        'ifName' => $ifName,
                        'ifDescr' => $ifName,
                        'ifType' => 'ethernetCsmacd',
                        'ifOperStatus' => $disabled ? 'down' : 'up',
                        'ifAdminStatus' => $disabled ? 'down' : 'up',
                        'ifMtu' => $l2['MTU'] ?? 1500,
                    ];

                    // Preserve existing label if it exists
                    if (isset($existingLabels[$ifName])) {
                        $port['ifAlias'] = $existingLabels[$ifName];
                    }

                    // Infer speed from interface name since VeloCloud config shows administrative
                    // speed (often 100M with autoneg) not actual negotiated speed
                    if (preg_match('/^GE\d+$/i', $ifName)) {
                        // Gigabit Ethernet - 1G
                        $port['ifSpeed'] = 1000000000;
                    } elseif (preg_match('/^SFP\d+$/i', $ifName)) {
                        // SFP slot - assume 1G (could be 10G but most common is 1G)
                        $port['ifSpeed'] = 1000000000;
                    } elseif (preg_match('/^LAG\d+$/i', $ifName)) {
                        // Link Aggregation - default to 1G (actual speed depends on member links)
                        $port['ifSpeed'] = 1000000000;
                    } else {
                        // Try to parse configured speed as fallback
                        $speed = $l2['speed'] ?? '1G';
                        if (preg_match('/(\d+)([MG])/', $speed, $matches)) {
                            $value = (int) $matches[1];
                            $unit = $matches[2];
                            $port['ifSpeed'] = $unit === 'G' ? ($value * 1000000000) : ($value * 1000000);
                        } else {
                            $port['ifSpeed'] = 1000000000; // Default to 1G
                        }
                    }

                    // Add metadata about addressing type and overlay
                    $port['ifVlan'] = $intf['vlanId'] ?? null;

                    $ports[] = $port;
                }

                break;
            }
        }

        return $ports;
    }
}
