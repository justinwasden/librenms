<?php
// LibreNMS/Polling/RestApi.php

use LibreNMS\Interfaces\Polling\PollerModule;
use App\Pollers\RestApiPoller;

class RestApi extends PollerModule
{
    public function run($device)
    {
        $poller = new RestApiPoller($device);
        $poller->poll();
        \Log::info("RestApi Poller executed for device {$device->hostname}");
    }
}
