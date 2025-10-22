<?php
namespace LibreNMS\Modules;

use LibreNMS\Polling\ModuleStatus;
use LibreNMS\OS;
use App\Models\Device;
use LibreNMS\Interfaces\Data\DataStorageInterface;
use LibreNMS\Interfaces\Module;
use Log;

class RestApi implements Module
{
    protected RestApiPollerService $pollerService;

    public function __construct()
    {
        $this->pollerService = app(RestApiPollerService::class);
    }

    public function poll(OS $os, DataStorageInterface $datastore): void
    {
        $deviceId = $os->device_id;
        if ($this->deviceHasApiConnections($deviceId)) {
            $this->pollerService->pollDeviceById($deviceId);
        }
    }

    public function shouldPoll(OS $os, ModuleStatus $status): bool
    {
        return $this->deviceHasApiConnections($os->device_id);
    }

    public function shouldDiscover(OS $os, ModuleStatus $status): bool
    {
        return $this->deviceHasApiConnections($os->device_id);
    }

    public function discover(OS $os): void
{
    $deviceId = $os->device_id;

    if ($this->deviceHasApiConnections($deviceId)) {
        // Optional: call your poller service discovery logic
        $this->pollerService->discoverDeviceById($deviceId);
    }
}

    public function dataExists(Device $device): bool
{
    return $this->deviceHasApiConnections($device->id);
}

    public function cleanup(Device $device): int
{
    $count = 0;

    // Delete all REST API connections and cascade deletes to endpoints/metrics
    $connections = $device->restApiConnections();
    $count += $connections->count();
    $connections->delete();

    return $count;
}

    public function dependencies(): array
    {
        return [];
    }

    protected function deviceHasApiConnections(int $deviceId): bool
    {
        return \DB::table('rest_api_connections')
            ->where('device_id', $deviceId)
            ->exists();
    }
}
