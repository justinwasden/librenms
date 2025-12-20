<?php

namespace LibreNMS\Util\Normalizers\Proxmox;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * Proxmox - Ipv4 Normalizer
 *
 * Capability: ipv4
 * Vendor: proxmox
 */
class Ipv4 extends BaseNormalizer
{
    protected string $capability = 'ipv4';
    protected string $vendor = 'proxmox';

    protected function doNormalize(Device $device, array $payload): array
    {
$addresses = [];

        if (!isset($payload['data']) || !is_array($payload['data'])) {
            return $addresses;
        }

        foreach ($payload['data'] as $iface) {
            $ifName = trim($iface['iface'] ?? '');
            if (!$ifName || strtolower($ifName) === 'lo') {
                continue;
            }

            // Proxmox API can return multiple IP addresses for the same interface
            // Handle both 'cidr' field (format: "IP/PREFIX") and separate 'address'/'netmask' fields
            $cidr = $iface['cidr'] ?? null;
            $ipAddr = $iface['address'] ?? null;
            $netmask = $iface['netmask'] ?? null;

            // If cidr field exists and contains a slash, parse it
            if ($cidr && strpos($cidr, '/') !== false) {
                [$ipAddr, $prefixLenStr] = explode('/', $cidr, 2);
                $prefixLen = (int) $prefixLenStr;
            } elseif ($ipAddr) {
                // Calculate prefix length from netmask
                $prefixLen = 24; // Default safe value
                
                if ($netmask) {
                    if (is_numeric($netmask)) {
                        // Netmask is already a CIDR prefix length
                        $prefixLen = (int) $netmask;
                    } else {
                        // Netmask is a dotted quad (e.g., "255.255.255.0")
                        try {
                            $prefixLen = $this->netmaskToCidr($netmask);
                        } catch (\Exception $e) {
                            // If conversion fails, use default
                            $prefixLen = 24;
                        }
                    }
                }
            } else {
                // No IP address information for this interface entry
                continue;
            }

            // Validate IP address
            if ($ipAddr && filter_var($ipAddr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                // Use stable index for consistency with port matching
                // But also include ifName for persistor to match by name (more reliable)
                $addresses[] = [
                    'ifIndex' => $this->stableIndexFromName($ifName),
                    'ifName' => $ifName,
                    'ipv4_address' => $ipAddr,
                    'ipv4_prefixlen' => $prefixLen,
                    'context_name' => '',
                ];
            }
        }

        return $addresses;
    }
}
