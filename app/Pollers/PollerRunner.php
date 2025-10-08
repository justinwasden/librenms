<?php
namespace App\Pollers;

use App\Models\Device;
use Log;

class PollerRunner
{
    protected array $devices;

    public function __construct(array $devices = [])
    {
        $this->devices = $devices ?: Device::where('rest_api_enabled', 1)->get()->toArray();
    }

    public function run()
    {
        foreach ($this->devices as $device) {
            try {
                $poller = new RestApiPoller($device);
                $poller->poll();
            } catch (\Exception $e) {
                Log::error("Polling failed for device {$device['hostname']}: {$e->getMessage()}");
            }
        }
    }
}
