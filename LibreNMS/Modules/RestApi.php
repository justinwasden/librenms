<?php
namespace LibreNMS\Modules;

use LibreNMS\Polling\ModuleStatus;
use LibreNMS\OS;
use LibreNMS\Interfaces\Data\DataStorageInterface;

class RestApi implements ModuleInterface
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

    public function discover(OS $os, DataStorageInterface $datastore): void
    {
        // Optional: implement discovery logic for REST API devices
        // For now, just a placeholder
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

    public function getName(): string
    {
        return 'REST API';
    }

    public function getVersion(): string
    {
        return '1.0';
    }

    public function getAuthor(): string
    {
        return 'JDub';
    }

    protected function deviceHasApiConnections(int $deviceId): bool
    {
        return \DB::table('rest_api_connections')
            ->where('device_id', $deviceId)
            ->exists();
    }
}
