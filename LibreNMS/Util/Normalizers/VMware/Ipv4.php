<?php

namespace LibreNMS\Util\Normalizers\VMware;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * VMware - Ipv4 Normalizer
 *
 * Capability: ipv4
 * Vendor: velocloud
 */
class Ipv4 extends BaseNormalizer
{
    protected string $capability = 'ipv4';
    protected string $vendor = 'velocloud';

    protected function doNormalize(Device $device, array $payload): array
    {
$data = $payload['data'] ?? $payload;
        $addresses = [];

        if (!is_array($data)) {
            return [];
        }

        foreach ($data as $edge) {
            // Get edge interface links
            $links = $edge['links'] ?? [];
            if (!is_array($links)) {
                continue;
            }

            // Build map of IP addresses to interface names from the links
            $ipToInterface = [];
            foreach ($links as $link) {
                $ipAddress = $link['ipAddress'] ?? null;
                if ($ipAddress && filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    // Use the interface name from the link
                    $interfaceName = $link['interface'] ?? null;
                    if ($interfaceName) {
                        $ipToInterface[$ipAddress] = $interfaceName;
                    }
                }
            }

            // Also check for the linkIpAddress field in the nested link object
            // This provides better mapping when link details are available
            foreach ($links as $link) {
                $linkInfo = $link['link'] ?? [];
                $linkIpAddress = $linkInfo['linkIpAddress'] ?? null;
                $interfaceName = $linkInfo['interface'] ?? $link['name'] ?? null;

                if ($linkIpAddress && filter_var($linkIpAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && $interfaceName) {
                    $ipToInterface[$linkIpAddress] = $interfaceName;
                }
            }

            // Now create address records with proper ifName matching
            foreach ($ipToInterface as $ipAddress => $interfaceName) {
                // Try to determine prefix length from IP class
                $prefixLen = 30; // Default to /30 for point-to-point links (common for SD-WAN)
                $firstOctet = (int) explode('.', $ipAddress)[0];
                if ($firstOctet >= 1 && $firstOctet <= 126) {
                    $prefixLen = 8; // Class A
                } elseif ($firstOctet >= 128 && $firstOctet <= 191) {
                    $prefixLen = 16; // Class B
                } elseif ($firstOctet >= 192 && $firstOctet <= 223) {
                    $prefixLen = 24; // Class C
                }

                $addresses[] = [
                    'ipv4_address' => $ipAddress,
                    'ipv4_prefixlen' => $prefixLen,
                    'ipv4_network_id' => null,
                    'ifName' => $interfaceName, // Use ifName instead of context_name for proper linking
                ];
            }
        }

        return $addresses;
    }
}
