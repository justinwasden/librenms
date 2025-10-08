<?php

use App\Pollers\RestApiPoller;

class RestApi extends \LibreNMS\Poller\PollerModule
{
    public function run($device)
    {
        $poller = new RestApiPoller($device);
        $poller->run();
    }
}
