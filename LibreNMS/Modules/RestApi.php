<?php

/**
 * RestApi.php
 *
 * REST API Discovery and Polling Module
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

namespace LibreNMS\Modules;

use App\Discovery\RestApiDiscovery;
use App\Models\Device;
use App\Pollers\RestApiPoller;
use LibreNMS\Interfaces\Data\DataStorageInterface;
use LibreNMS\Interfaces\Module;
use LibreNMS\OS;
use LibreNMS\Polling\ModuleStatus;
use Log;

class RestApi implements Module
{
    /**
     * @inheritDoc
     */
    public function dependencies(): array
    {
        return ['ports']; // Add dependencies if needed, e.g., if you need ports discovered first
    }

    /**
     * @inheritDoc
     */
    public function shouldDiscover(OS $os, ModuleStatus $status): bool
    {
        // Check if device has REST API connections configured
        $device = $os->getDevice();
        
        return $status->isEnabledAndDeviceUp($device, check_snmp: false) 
            && $device->restApiConnections()->where('enabled', 1)->exists();
    }

    /**
     * Discover this module. Run during discovery cycle.
     *
     * @param  OS  $os
     */
    public function discover(OS $os): void
    {
        $device = $os->getDevice();
        
        try {
            $discovery = new RestApiDiscovery($device);
            $discovery->discover();
            
            Log::info("REST API Discovery completed for device {$device->hostname}");
        } catch (\Exception $e) {
            Log::error("REST API Discovery failed for device {$device->hostname}: {$e->getMessage()}");
        }
    }

    /**
     * @inheritDoc
     */
    public function shouldPoll(OS $os, ModuleStatus $status): bool
    {
        // Check if device has REST API connections configured
        $device = $os->getDevice();
        
        return $status->isEnabledAndDeviceUp($device, check_snmp: false)
            && $device->restApiConnections()->where('enabled', 1)->exists();
    }

    /**
     * Poll data for this module and update the DB / RRD.
     *
     * @param  OS  $os
     * @param  DataStorageInterface  $datastore
     */
    public function poll(OS $os, DataStorageInterface $datastore): void
    {
        $device = $os->getDevice();
        
        try {
            $poller = new RestApiPoller($device);
            $poller->poll();
            
            Log::info("REST API Polling completed for device {$device->hostname}");
        } catch (\Exception $e) {
            Log::error("REST API Polling failed for device {$device->hostname}: {$e->getMessage()}");
        }
    }

    /**
     * Check if data exists for this module
     */
    public function dataExists(Device $device): bool
    {
        return $device->restApiConnections()->exists();
    }

    /**
     * Remove all DB data for this module.
     * This will be run when the module is disabled.
     *
     * @param  Device  $device
     */
    public function cleanup(Device $device): int
    {
        $count = 0;
        
        // Delete all connections (cascades to endpoints and metrics via foreign keys)
        $connections = $device->restApiConnections();
        $count += $connections->count();
        $connections->delete();
        
        return $count;
    }

    /**
     * Dump current module data for the given device for tests.
     *
     * @param  Device  $device
     * @param  string  $type  Type is either discovery or poller
     */
    public function dump(Device $device, string $type): ?array
    {
        return [
            'rest_api_connections' => $device->restApiConnections()
                ->with(['credential', 'endpoints'])
                ->get()
                ->map(function ($conn) {
                    return [
                        'name' => $conn->name,
                        'base_url' => $conn->base_url,
                        'enabled' => $conn->enabled,
                        'credential_type' => $conn->credential?->authenticationType?->name,
                        'endpoints_count' => $conn->endpoints->count(),
                    ];
                })
                ->toArray(),
        ];
    }
}
