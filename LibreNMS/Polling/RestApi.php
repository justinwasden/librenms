<?php
// File: LibreNMS/Polling/RestApi.php

use LibreNMS\Interfaces\Polling\PollerModule;
use App\Pollers\RestApiPoller;

class RestApi extends PollerModule
{
    public function run($device)
    {
        // Call your Laravel poller class
        $poller = new RestApiPoller($device);
        $poller->poll();

        // Optional logging
        \Log::info("RestApi Poller executed for device {$device->hostname}");
    }
}
