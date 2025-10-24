<?php

/**LibreNMS\Modules\RestApi.php
 * RestApi.php
 *
 * LibreNMS REST API Poller Module
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

use App\Models\Device as DeviceModel;
use App\Models\RestApiDeviceTemplate;
use App\Services\RestApi\RestApiPollerService;
use Illuminate\Support\Facades\Log;
use LibreNMS\Interfaces\Module;
use LibreNMS\OS;
use LibreNMS\Interfaces\Data\DataStorageInterface;
use LibreNMS\Polling\ModuleStatus;

class RestApi implements Module
{
    /**
     * An array of all modules this module depends on
     */
    public function dependencies(): array
    {
        return [];
    }

    /**
     * Should discovery run for this device?
     */
    public function shouldDiscover(OS $os, ModuleStatus $status): bool
    {
        $device = $os->getDevice();
        $deviceModel = DeviceModel::find($device->device_id);

        if (!$deviceModel) {
            return false;
        }

        $hasTemplate = RestApiDeviceTemplate::where('device_id', $deviceModel->device_id)->exists();

        return $hasTemplate && $status->isEnabled();
    }

    /**
     * Should polling run for this device?
     */
    public function shouldPoll(OS $os, ModuleStatus $status): bool
    {
        $device = $os->getDevice();
        $deviceModel = DeviceModel::find($device->device_id);

        if (!$deviceModel) {
            return false;
        }

        $hasTemplate = RestApiDeviceTemplate::where('device_id', $deviceModel->device_id)->exists();

        return $hasTemplate && $status->isEnabled();
    }

    /**
     * Discover this module
     */
    public function discover(OS $os): void
    {
        // REST API discovery would go here if needed
        // For now, nothing specific to discover
    }

    /**
     * Poll data for this module
     */
    public function poll(OS $os, DataStorageInterface $datastore): void
    {
        $device = $os->getDevice();
        $deviceModel = DeviceModel::find($device->device_id);

        if (!$deviceModel) {
            return;
        }

        try {
            RestApiPollerService::pollViaLibreNMS($deviceModel);
        } catch (\Throwable $e) {
            Log::error("Error polling REST API", [
                'device_id' => $deviceModel->device_id,
                'device' => $deviceModel->hostname,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Check if data exists for this module
     */
    public function dataExists(DeviceModel $device): bool
    {
        return RestApiDeviceTemplate::where('device_id', $device->device_id)->exists();
    }

    /**
     * Remove all DB data for this module
     */
    public function cleanup(DeviceModel $device): int
    {
        // Nothing to clean up for REST API
        return 0;
    }

    /**
     * Dump current module data for the given device for tests
     */
    public function dump(DeviceModel $device, string $type): ?array
    {
        return null; // Testing not implemented
    }
}
