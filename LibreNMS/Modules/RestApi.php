<?php
namespace LibreNMS\Modules;

use LibreNMS\Polling\ModuleStatus;
use LibreNMS\OS;
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

    public function dataExists(OS $os, DataStorageInterface $datastore): bool
    {
        // Return true if this module has already stored data for the device
        return $this->deviceHasApiConnections($os->device_id);
    }

    public function cleanup(OS $os, DataStorageInterface $datastore): void
    {
        // Optional: cleanup module-specific data for the device
        // Placeholder for now
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
