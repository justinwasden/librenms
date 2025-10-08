<?php
// /opt/librenms/LibreNMS/Discovery/RestApi.php

use App\Discovery\RestApiDiscovery;

class RestApi extends \LibreNMS\Discovery\DiscoveryModule
{
    public function run($device)
    {
        // Instantiate your Laravel discovery class
        $disco = new RestApiDiscovery($device);
        $disco->discover(); // make sure your RestApiDiscovery has a public discover() method
    }
}
