<?php
namespace LibreNMS\Modules;

use App\Services\RestApi\RestApiPollerService;
use LibreNMS\Devices\Device;
use LibreNMS\Interfaces\Module as ModuleInterface;

class RestApi implements ModuleInterface
{
    protected $pollerService;

    public function __construct()
    {
        $this->pollerService = app(RestApiPollerService::class);
    }

    /**
     * Poll a device if it has REST API connections configured.
     *
     * @param Device $device
     */
    public function poll(Device $device): void
    {
        if ($this->deviceHasApiConnections($device->id)) {
            $this->pollerService->pollDevice($device);
        }
    }

    protected function deviceHasApiConnections(int $deviceId): bool
    {
        return \DB::table('rest_api_connections')
            ->where('device_id', $deviceId)
            ->exists();
    }
}
