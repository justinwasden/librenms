<?php
namespace App\Discovery;

use App\Models\Device;
use Log;

class DiscoveryRunner
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
                $discovery = new RestApiDiscovery($device);
                $discovery->discover();
            } catch (\Exception $e) {
                Log::error("Discovery failed for device {$device['hostname']}: {$e->getMessage()}");
            }
        }
    }
}
