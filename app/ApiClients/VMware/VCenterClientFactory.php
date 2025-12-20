<?php

namespace App\ApiClients\VMware;

use App\Models\Device;

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
        return $device->getAttrib('api_base_url') !== null;
    }
}