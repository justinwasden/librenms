<?php
namespace LibreNMS\Modules;

use App\Services\RestApi\RestApiPollerService;
use LibreNMS\Interfaces\Module as ModuleInterface;
use LibreNMS\OS;
use LibreNMS\Interfaces\Data\DataStorageInterface;
use LibreNMS\Polling\ModuleStatus;

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

    // Updated signatures with ModuleStatus
    public function shouldPoll(OS $os, ModuleStatus $status): bool
    {
        return $this->deviceHasApiConnections($os->device_id);
    }

    public function shouldDiscover(OS $os, ModuleStatus $status): bool
    {
        return $this->deviceHasApiConnections($os->device_id);
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