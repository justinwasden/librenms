<?php
// File: LibreNMS/Discovery/RestApi.php

use LibreNMS\Interfaces\Discovery\DiscoveryModule;
use App\Discovery\RestApiDiscovery;

class RestApi extends DiscoveryModule
{
    public function run($device)
    {
        $disco = new RestApiDiscovery($device);
        $disco->discover();

        \Log::info("RestApi Discovery executed for device {$device->hostname}");
    }
}
