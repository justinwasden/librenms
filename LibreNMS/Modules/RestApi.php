<?php
/**
 * File: /opt/librenms/LibreNMS/Modules/RestApi.php
 * Purpose: Legacy RestApi poller module updated to use RestApiPollerService
 */

namespace LibreNMS\Modules;

use App\Services\RestApi\RestApiPollerService;
use LibreNMS\Devices\Device;

class RestApi
{
    protected $pollerService;

    public function __construct()
    {
        // Use Laravel service container to instantiate the new poller service
        $this->pollerService = app(RestApiPollerService::class);
    }

    /**
     * Poll a device if it has REST API connections configured.
     *
     * @param Device $device
     * @return void
     */
    public function pollDevice(Device $device)
    {
        // Check if this device has API connections enabled
        if ($this->deviceHasApiConnections($device->id)) {
            $this->pollerService->pollDevice($device);
        }
    }

    /**
     * Determine if a device has REST API connections configured
     *
     * @param int $deviceId
     * @return bool
     */
    protected function deviceHasApiConnections(int $deviceId): bool
    {
        // Assumes you have a table `rest_api_connections` with device_id column
        return \DB::table('rest_api_connections')
            ->where('device_id', $deviceId)
            ->exists();
    }

    /**
     * Legacy hook for poller modules (called by native LibreNMS poller)
     *
     * @param Device $device
     * @return void
     */
    public function poll(Device $device)
    {
        $this->pollDevice($device);
    }
}
