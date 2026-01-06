<?php

/**
 * storage-array.inc.php
 *
 * Display storage array overview information for storage devices
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

$isStorageDevice = ($device_obj->type === 'storage')
    || in_array($device_obj->os, [
        'purestorage', 'netapp', 'ontap', 'intelliflash', 'unity', 'powerstore',
        'isilon', 'nimble', 'threepar', 'primera', 'svc', 'flashsystem',
        'vsp', 'oceanstor', 'ceph',
    ]);

if ($isStorageDevice) {
    $array = $device_obj->storageArray()->first();

    if ($array) {
        $arrayCapacityRow = $device_obj->storage()
            ->where('type', 'array')
            ->orWhere('storage_descr', 'Array Capacity')
            ->first();

        // Load controllers from entPhysical (native table)
        $controllers = collect(DB::table('entPhysical')
            ->where('device_id', $device_obj->device_id)
            ->where('entPhysicalClass', 'module')
            ->where('entPhysicalVendorType', 'controller')
            ->get())
            ->map(function ($ctrl) {
                return (object) [
                    'controller_name' => $ctrl->entPhysicalName,
                    'serial' => $ctrl->entPhysicalSerialNum,
                    'model' => $ctrl->entPhysicalModelName,
                    'status' => 'ready',  // Default to ready since entPhysical doesn't track status
                    'mode' => null,
                ];
            });

        // Load volumes and hosts from legacy tables (if they exist)
        $volumes = $array->volumes()->get();
        $hosts = $array->hosts()->get();

        echo view('device.overview.storage-array', [
            'device' => $device_obj,
            'array' => $array,
            'arrayCapacityRow' => $arrayCapacityRow,
            'controllers' => $controllers,
            'volumes' => $volumes,
            'hosts' => $hosts,
        ]);
    }
}
