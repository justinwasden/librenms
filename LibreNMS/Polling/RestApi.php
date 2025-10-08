<?php
// /opt/librenms/LibreNMS/Polling/RestApi.php

use App\Pollers\RestApiPoller;

class RestApi extends \LibreNMS\Poller\PollerModule
{
    public function run($device)
    {
        // Instantiate your Laravel poller and run it
        $poller = new RestApiPoller($device);
        $poller->poll(); // make sure your RestApiPoller has a public poll() method
    }
}
