<?php

namespace App\Polling\Modules;

use App\Models\Device;
use App\Models\RestApiDeviceTemplate;
use App\Models\RestApiCredential;
use App\Services\RestApi\RestApiClient;
use App\Services\RestApi\RestApiDataProcessor;
use App\Services\RestApi\FieldExtractor;
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

        $template = $deviceTemplate->template;
        $credential = RestApiCredential::where('device_id', $device->device_id)->first();

        if (!$credential) {
            Log::warning("No REST API credentials for device", [
                'device_id' => $device->device_id,
            ]);
            return;
        }

        $templateData = $template->template_data;
        $connection = $templateData['connections'][0] ?? null;

        if (!$connection) {
            Log::warning("No connection configured in template", [
                'template_id' => $template->id,
            ]);
            return;
        }

        $baseUrl = str_replace('{device_hostname}', $device->hostname, $connection['base_url']);

        foreach ($template->endpoints as $endpoint) {
            $this->pollEndpoint($device, $endpoint, $credential, $baseUrl);
        }
    }

    protected function pollEndpoint(Device $device, $endpoint, RestApiCredential $credential, string $baseUrl): void
    {
        try {
            $client = new RestApiClient();
            $url = $endpoint->getUrl($baseUrl);
            $headers = $credential->getAuthHeader();

            Log::debug("Calling REST API endpoint", [
                'device_id' => $device->device_id,
                'endpoint' => $endpoint->name,
                'url' => $url,
            ]);

            $response = $client->get($url, $headers);

            if (!$response['success']) {
                Log::warning("REST API endpoint call failed", [
                    'endpoint' => $endpoint->name,
                    'error' => $response['error'] ?? 'Unknown error',
                    'status_code' => $response['status_code'] ?? 0,
                ]);
                return;
            }

            $extractor = new FieldExtractor();
            $processor = new RestApiDataProcessor($extractor);

            $result = $processor->processEndpointResponse($endpoint, $response['data'], $device);

            Log::debug("Endpoint processing result", [
                'endpoint' => $endpoint->name,
                'result' => $result,
            ]);
        } catch (Exception $e) {
            Log::error("Error polling REST API endpoint", [
                'endpoint' => $endpoint->name,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
