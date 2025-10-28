<?php

/**
 * DeviceApi.php
 *
 * LibreNMS Device API Polling/Discovery Module
 * Polls devices via REST APIs using vendor-specific templates
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 * @link       https://www.librenms.org
 *
 * @copyright  2025
 * @author     LibreNMS Contributors
 */

namespace LibreNMS\Modules;

use App\ApiClients\DeviceApiClientFactory;
use App\Models\Device;
use App\Models\DeviceApiConfig;
use App\Services\DeviceApiExecutor;
use LibreNMS\Interfaces\Data\DataStorageInterface;
use LibreNMS\Interfaces\Module;
use LibreNMS\OS;
use LibreNMS\Polling\ModuleStatus;
use LibreNMS\Util\ApiTemplateManager;
use LibreNMS\Util\DeviceApiSettings;
use Illuminate\Support\Facades\Log;

class DeviceApi implements Module
{

    public function dependencies(): array
    {
        return [];
    }


    public function shouldDiscover(OS $os, ModuleStatus $status): bool
    {
        $device = $os->getDevice();

        // Skip if device is disabled
        if (!$device->status) {
            return false;
        }

        // Check if device has API configuration
        $apiConfig = $device->apiConfig ?? DeviceApiConfig::where('device_id', $device->device_id)->first();

        return $apiConfig !== null;
    }


    public function discover(OS $os): void
    {
        $device = $os->getDevice();

        Log::info("Running API discovery for device {$device->device_id}");

        try {
            $this->executeApiPoll($device);
        } catch (\Throwable $e) {
            Log::error("API discovery failed for device {$device->device_id}: " . $e->getMessage());
            DeviceApiSettings::recordError($device, $e->getMessage());
        }
    }


    public function shouldPoll(OS $os, ModuleStatus $status): bool
    {
        $device = $os->getDevice();

        // Skip if device is disabled
        if (!$device->status) {
            return false;
        }

        // Check if device has API configuration
        $apiConfig = $device->apiConfig ?? DeviceApiConfig::where('device_id', $device->device_id)->first();

        if (!$apiConfig) {
            return false;
        }

        // Check circuit breaker - skip if too many consecutive errors
        if (DeviceApiSettings::shouldTripCircuitBreaker($device)) {
            Log::warning("Circuit breaker tripped for device {$device->device_id} - skipping API poll");
            return false;
        }

        return true;
    }


    public function poll(OS $os, DataStorageInterface $datastore): void
    {
        $device = $os->getDevice();

        Log::info("Running API poll for device {$device->device_id}");

        $startTime = microtime(true);

        try {
            $this->executeApiPoll($device);

            $latencyMs = (int) ((microtime(true) - $startTime) * 1000);
            DeviceApiSettings::recordSuccess($device, $latencyMs);

            // Reset circuit breaker on success
            DeviceApiSettings::resetCircuitBreaker($device);
        } catch (\Throwable $e) {
            Log::error("API poll failed for device {$device->device_id}: " . $e->getMessage());
            DeviceApiSettings::recordError($device, $e->getMessage());
        }
    }


    protected function executeApiPoll(Device $device): void
    {
        // Load device with relationships
        $device->load(['apiConfig.template', 'apiConfig.schema']);

        if (!$device->apiConfig) {
            throw new \RuntimeException("Device has no API configuration");
        }

        $templateKey = $device->apiConfig->template->key ?? null;
        if (!$templateKey) {
            throw new \RuntimeException("Device has no template assigned");
        }

        // Ensure base URL is resolved
        DeviceApiSettings::ensureResolvedBaseUrl($device);

        // Load template
        $template = ApiTemplateManager::loadTemplate($templateKey);
        if (!$template) {
            throw new \RuntimeException("Template {$templateKey} not found or disabled");
        }

        // Create API client via factory
        $client = DeviceApiClientFactory::make($device);
        if (!$client) {
            throw new \RuntimeException("Could not create API client for device");
        }

        // Execute endpoints
        $executor = new DeviceApiExecutor();
        $executor->run($device, $templateKey, $client);
    }


    public function dataExists(Device $device): bool
    {
        $apiConfig = $device->apiConfig ?? DeviceApiConfig::where('device_id', $device->device_id)->first();

        return $apiConfig !== null;
    }


    public function cleanup(Device $device): int
    {
        // Delete API configuration
        $deleted = DeviceApiConfig::where('device_id', $device->device_id)->delete();

        // Clear API-related attribs (for migration cleanup)
        $device->forgetAttrib('rest_enabled');
        $device->forgetAttrib('rest_template_key');
        $device->forgetAttrib('rest_auth_type');
        $device->forgetAttrib('rest_base_url');
        $device->forgetAttrib('rest_headers');
        $device->forgetAttrib('rest_verify_tls');
        $device->forgetAttrib('rest_endpoints');
        $device->forgetAttrib('rest_timeout_ms');
        $device->forgetAttrib('rest_proxy');
        $device->forgetAttrib('rest_last_success');
        $device->forgetAttrib('rest_last_error');
        $device->forgetAttrib('rest_last_error_message');
        $device->forgetAttrib('rest_error_count');
        $device->forgetAttrib('rest_avg_latency_ms');

        return $deleted;
    }


    public function dump(Device $device, string $type): ?array
    {
        $apiConfig = $device->apiConfig()->with(['template', 'schema.fields'])->first();

        if (!$apiConfig) {
            return null;
        }

        return [
            'template_key' => $apiConfig->template->key ?? null,
            'schema_key' => $apiConfig->schema->key ?? null,
            'base_url' => $apiConfig->base_url,
            'verify_ssl' => $apiConfig->verify_ssl,
            'has_credentials' => !empty($apiConfig->values),
        ];
    }
}
