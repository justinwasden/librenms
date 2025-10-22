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
     * Poll a device if it has REST API connections configured.
     *
     * @param OS $os
     * @param DataStorageInterface $datastore
     */
    public function poll(OS $os, DataStorageInterface $datastore): void
    {
        $deviceId = $os->device_id;

        if ($this->deviceHasApiConnections($deviceId)) {
            $this->pollerService->pollDeviceById($deviceId);
        }
    }

    /**
     * Check if device has REST API connections
     *
     * @param int $deviceId
     * @return bool
     */
    protected function deviceHasApiConnections(int $deviceId): bool
    {
        return \DB::table('rest_api_connections')
            ->where('device_id', $deviceId)
            ->exists();
    }
}
