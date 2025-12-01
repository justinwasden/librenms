<?php

namespace App\ApiClients\Cisco;

use App\Models\Device;

class UcsmXmlClientFactory
{
    public static function make(Device $device): UcsmXmlClient
    {
        return new UcsmXmlClient($device);
    }
}
