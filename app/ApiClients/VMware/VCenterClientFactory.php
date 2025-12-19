<?php

namespace App\ApiClients\VMware;

use App\Models\Device;
use App\Models\DeviceApiConfig;
use Illuminate\Support\Facades\Log;

class VCenterClientFactory
{
    /**
     * Create a VCenterClient from a device (REST).
     */
    public static function make(Device $device): VCenterClient
    {
        return new VCenterClient($device);
    }

    /**
     * Test if a device has vCenter REST API configured.
     */
    public static function hasConfig(Device $device): bool
    {
        $apiConfig = $device->apiConfig ?? DeviceApiConfig::where('device_id', $device->device_id)->first();

        return $apiConfig && $apiConfig->template && in_array($apiConfig->template->key, [
            'vmware_vcenter_default', // new template key
            'vmware_vcenter',         // legacy key, if present
        ], true);
    }
}