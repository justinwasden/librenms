<?php
namespace LibreNMS\Modules;

use App\Services\RestApi\RestApiPollerService;
use LibreNMS\Interfaces\Module as ModuleInterface;
use LibreNMS\OS;
use LibreNMS\Interfaces\Data\DataStorageInterface;

class RestApi implements ModuleInterface
{
    protected RestApiPollerService $pollerService;

    public function __construct()
    {
        $this->pollerService = app(RestApiPollerService::class);
    }

    /**
     * Called by the poller
     */
    public function poll(OS $os, DataStorageInterface $datastore): void
    {
        $deviceId = $os->device_id;

        if ($this->deviceHasApiConnections($deviceId)) {
            $this->pollerService->pollDeviceById($deviceId);
        }
    }

    /**
     * Called by discovery engine to determine if polling should happen
     */
    public function shouldPoll(OS $os): bool
    {
        return $this->deviceHasApiConnections($os->device_id);
    }

    /**
     * Called by discovery engine to determine if discovery should happen
     */
    public function shouldDiscover(OS $os): bool
    {
        return $this->deviceHasApiConnections($os->device_id);
    }

    /**
     * List any module dependencies
     */
    public function dependencies(): array
    {
        return [];
    }

    /**
     * Module name
     */
    public function getName(): string
    {
        return 'REST API';
    }

    /**
     * Module version
     */
    public function getVersion(): string
    {
        return '1.0';
    }

    /**
     * Module author
     */
    public function getAuthor(): string
    {
        return 'JDub';
    }

    /**
     * Check if a device has REST API connections
     */
    protected function deviceHasApiConnections(int $deviceId): bool
    {
        return \DB::table('rest_api_connections')
            ->where('device_id', $deviceId)
            ->exists();
    }
}
