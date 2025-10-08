<?php

use App\RestApi\Discovery\RestApiDiscovery;

class RestApi extends \LibreNMS\Discovery\DiscoveryModule
{
    public function run($device)
    {
        $disco = new RestApiDiscovery($device);
        $disco->run();
    }
}
