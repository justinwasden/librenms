<?php

namespace LibreNMS\Util\Normalizers\VMware;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * VMware - ConfigStackIpv4 Normalizer
 *
 * Capability: ipv4
 * Vendor: velocloud
 */
class ConfigStackIpv4 extends BaseNormalizer
{
    protected string $capability = 'ipv4';
    protected string $vendor = 'velocloud';

    protected function doNormalize(Device $device, array $payload): array
    {
$addresses = [];

        // Get edge-specific config (first stack)
        $edgeConfig = $payload[0] ?? [];

        // Find deviceSettings module
        foreach ($edgeConfig['modules'] ?? [] as $module) {
            if (($module['name'] ?? '') === 'deviceSettings') {
                $routedInterfaces = $module['data']['routedInterfaces'] ?? [];

                foreach ($routedInterfaces as $intf) {
                    $ifName = $intf['name'] ?? null;
                    $addressing = $intf['addressing'] ?? [];
                    $disabled = $intf['disabled'] ?? false;

                    // Skip disabled interfaces
                    if ($disabled || !$ifName) {
                        continue;
                    }

                    // Only process interfaces with static IPs
                    // DHCP addresses would be captured from runtime state, not config
                    if (($addressing['type'] ?? '') === 'STATIC' && !empty($addressing['cidrIp'])) {
                        $prefixLen = $addressing['cidrPrefix'] ?? 24;

                        // Validate it's a proper IPv4 address
                        if (filter_var($addressing['cidrIp'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                            $addresses[] = [
                                'ipv4_address' => $addressing['cidrIp'],
                                'ipv4_prefixlen' => $prefixLen,
                                'ipv4_network_id' => null,
                                'ifName' => $ifName,
                            ];
                        }
                    }
                }

                break;
            }
        }

        return $addresses;
    }
}
