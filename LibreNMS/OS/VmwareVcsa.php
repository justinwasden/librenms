<?php

namespace LibreNMS\OS;

use App\ApiClients\VMware\VCenterClient;
use App\Models\Vlan;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use LibreNMS\OS;

/**
 * VMware vCenter Server Appliance (VCSA) OS
 *
 * Extends base OS to provide REST API-based discovery for VLANs (port groups)
 */
class VmwareVcsa extends OS
{
    use Traits\ApiPolling;
    use Traits\VminfoVmware;

    /**
     * Skip port group interfaces discovered via SNMP
     * Port groups should only appear in the VLANs tab, not the ports tab
     *
     * @param string $ifName
     * @return bool
     */
    public function skipIfName($ifName): bool
    {
        // Skip VMware port groups (standard and distributed)
        // These typically have names like "VM Network", "DPortGroup", etc.
        // Also skip Network Adapter interfaces which are actually port groups
        $skipPatterns = [
            '/^Network adapter \d+/i',  // "Network adapter 1", "Network adapter 2", etc.
            '/^DPortGroup$/i',          // Distributed Port Group
            '/^DVS\./i',                // Distributed Virtual Switch ports
            '/^vmnic/i',                // Skip vmnic interfaces (these are ESXi host NICs, not vCenter appliance NICs)
        ];

        foreach ($skipPatterns as $pattern) {
            if (preg_match($pattern, $ifName)) {
                Log::debug("VmwareVcsa: Skipping port group interface: {$ifName}");
                return true;
            }
        }

        return parent::skipIfName($ifName);
    }

    /**
     * Override discoverVlans to use REST API instead of SNMP
     *
     * For vCenter devices with REST API configured, port groups are collected
     * as VLANs via the SOAP API instead of SNMP Q-Bridge MIBs.
     */
    public function discoverVlans(): Collection
    {
        // Check if REST API is configured
        if (!$this->hasApiConfig()) {
            // Fall back to SNMP discovery if REST API is not configured
            return parent::discoverVlans();
        }

        try {
            // Use REST API client to fetch VLANs (port groups)
            $client = new VCenterClient($this->getDevice());
            $vlansData = $client->fetchVlans($this->getDevice());

            $vlans = collect();
            foreach ($vlansData as $vlanData) {
                $vlan = new Vlan([
                    'device_id' => $this->getDeviceId(),
                    'vlan_vlan' => $vlanData['vlan_vlan'] ?? 0,  // Default to 0 if not set
                    'vlan_name' => $vlanData['vlan_name'] ?? '',
                    'vlan_domain' => $vlanData['vlan_domain'] ?? 1,
                    'vlan_type' => $vlanData['vlan_type'] ?? null,
                    'vlan_mtu' => $vlanData['vlan_mtu'] ?? null,
                ]);

                // Include all VLANs, even those without VLAN IDs (0)
                // Skip only if vlan_name is empty
                if (!empty($vlan->vlan_name)) {
                    $vlans->push($vlan);
                }
            }

            Log::info('VmwareVcsa: Discovered ' . $vlans->count() . ' VLANs via REST API', [
                'device_id' => $this->getDeviceId(),
            ]);

            return $vlans;
        } catch (\Exception $e) {
            Log::warning('VmwareVcsa: Failed to discover VLANs via REST API, falling back to SNMP', [
                'device_id' => $this->getDeviceId(),
                'error' => $e->getMessage(),
            ]);

            // Fall back to SNMP discovery
            return parent::discoverVlans();
        }
    }
}
