<?php

/**
 * ucsm-cluster.inc.php
 *
 * Display Cisco UCS Manager cluster overview information
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

$device_obj = DeviceCache::getPrimary();

// Only show for UCSM devices
if ($device_obj->os === 'cisco-ucsm' || str_contains($device_obj->sysDescr ?? '', 'UCS Manager')) {
    // Check if device has API configuration via device attributes
    $hasApiConfig = !empty($device_obj->getAttrib('api_base_url'));
    $templateKey = $device_obj->getAttrib('api_template_key', '');

    if ($hasApiConfig && $templateKey === 'cisco_ucsm_xml') {
        // Fetch cluster information via API
        try {
            $client = \App\ApiClients\Cisco\UcsmXmlClientFactory::make($device_obj);

            // Fetch data needed for cluster info
            $topSystemData = $client->fetchTopSystem($device_obj);
            $fabricData = $client->fetchFabricInterconnects($device_obj);
            $mgmtEntityData = $client->fetchManagementEntity($device_obj);

            // Normalize cluster information
            $clusterInfo = \LibreNMS\Util\Normalizers\UcsmXmlNormalizer::normalizeClusterInfo($device_obj, [
                'topSystem' => $topSystemData,
                'fabricInterconnects' => $fabricData,
                'managementEntity' => $mgmtEntityData,
            ]);

            if (!empty($clusterInfo['fabric_interconnects'])) {
                echo view('device.overview.ucsm-cluster', [
                    'device' => $device_obj,
                    'clusterInfo' => $clusterInfo,
                ]);
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::debug("UCSM cluster overview failed: {$e->getMessage()}");
        }
    }
}
