<?php

namespace App\Services;

use App\Models\Device;

class DeviceApiPersistor
{
    public static function savePorts(Device $device, array $ports): void
    {
        foreach ($ports as $p) {
            // Expected keys: ifIndex, ifName, ifDescr, ifType, ifSpeed, ifOperStatus, ifAdminStatus, ifMtu, ifPhysAddress, ifAlias, ifLastChange
            // Persist into ports table or via LibreNMS port importer
            // Example:
            // \DB::table('ports')->updateOrInsert(
            //     ['device_id' => $device->device_id, 'ifIndex' => $p['ifIndex']],
            //     array_merge($p, ['device_id' => $device->device_id])
            // );
        }
    }

    public static function saveSensors(Device $device, array $sensors): void
    {
        foreach ($sensors as $s) {
            // Expected keys: sensor_class, sensor_type, sensor_descr, sensor_index, sensor_current, sensor_limit, sensor_limit_low, optional states
            // Persist into sensors table or via LibreNMS sensor importer
        }
    }

    public static function saveProcessors(Device $device, array $processors): void
    {
        foreach ($processors as $pr) {
            // Expected keys: processor_index, processor_type, processor_descr, processor_usage
            // Persist into processors table
        }
    }

    public static function saveMempools(Device $device, array $mps): void
    {
        foreach ($mps as $mp) {
            // Expected keys: mempool_index, mempool_type, mempool_descr, mempool_used, mempool_free, mempool_total, mempool_perc
            // Persist into mempools table
        }
    }

    public static function saveInventory(Device $device, array $inv): void
    {
        foreach ($inv as $e) {
            // Expected entPhysical* keys
            // Persist into entPhysical/ inventory table
        }
    }
}