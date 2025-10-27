<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Device;
use App\Models\DeviceApiConfig;
use App\Services\DeviceApiExecutor;
use App\ApiClients\DeviceApiClientFactory;
use LibreNMS\Util\ApiTemplateManager;
use LibreNMS\Util\DeviceApiSettings;

class PollDeviceApi extends Command
{
    protected $signature = 'device:poll-api {device_id}';
    protected $description = 'Poll REST API endpoints for a device using the selected template';

    public function handle(): int
    {
        $deviceId = (int) $this->argument('device_id');
        $device = Device::with('apiConfig.template')->find($deviceId);
        if (!$device) {
            $this->error("Device $deviceId not found.");
            return 1;
        }

        // Check if device has API configuration
        if (!$device->apiConfig) {
            $this->warn("Device {$deviceId} has no API configuration.");
            return 0;
        }

        $tplKey = $device->apiConfig->template->key ?? null;
        if (!$tplKey) {
            $this->warn("Device {$deviceId} has no template assigned.");
            return 0;
        }

        // Resolve base URL
        DeviceApiSettings::ensureResolvedBaseUrl($device);

        $tpl = ApiTemplateManager::loadTemplate($tplKey);
        if (!$tpl) {
            $this->error("Template {$tplKey} not found or disabled.");
            return 1;
        }

        // Use the factory to create the appropriate client
        $client = DeviceApiClientFactory::make($device);
        if (!$client) {
            $this->error("Could not create API client for device {$deviceId}.");
            return 1;
        }

        // Execute endpoints
        $executor = new DeviceApiExecutor();
        try {
            $executor->run($device, $tplKey, $client);
            $this->info("API poll successful for device {$deviceId}.");

            // Record success
            DeviceApiSettings::recordSuccess($device, 0);

            return 0;
        } catch (\Throwable $e) {
            $this->error("API poll failed: " . $e->getMessage());

            // Record error
            DeviceApiSettings::recordError($device, $e->getMessage());

            return 1;
        }
    }
}