<?php

namespace App\Polling\Modules;

use App\Models\Device;
use App\Models\RestApiDeviceTemplate;
use App\Services\RestApi\RestApiPollerService;
use Illuminate\Support\Facades\Log;

class RestApi extends \LibreNMS\Polling\Module
{
    protected string $name = 'rest-api';

    public function poll(): void
    {
        $device = $this->device;

        $deviceTemplate = RestApiDeviceTemplate::where('device_id', $device->device_id)->first();

        if (!$deviceTemplate) {
            return;
        }

        try {
            RestApiPollerService::pollViaLibreNMS($device);
        } catch (\Throwable $e) {
            Log::error("Error polling REST API", [
                'device_id' => $device->device_id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
