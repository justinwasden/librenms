<?php
namespace App\Pollers;

use App\Models\Device;
use App\RestApi\Metrics\MetricsStager;
use App\RestApi\Credentials\CredentialHelper;
use App\RestApi\Utils\JsonFlattener;
use GuzzleHttp\Client;
use Log;

class RestApiPoller
{
    protected Device $device;
    protected MetricsStager $stager;

    public function __construct(Device $device)
    {
        $this->device = $device;
        $this->stager = new MetricsStager($device);
    }

    public function poll()
    {
        $connections = $this->device->restApiConnections()->where('enabled', 1)->get();

        foreach ($connections as $conn) {
            foreach ($conn->endpoints as $endpoint) {
                try {
                    $response = $this->requestEndpoint($conn, $endpoint);
                    $metrics = JsonFlattener::flatten($response, $endpoint->resource_type . '_');
                    $this->stager->stageMetrics($metrics, true); // true = poller (RRD)
                } catch (\Exception $e) {
                    Log::error("Polling failed for {$endpoint->name}: {$e->getMessage()}");
                }
            }
        }
    }

    protected function requestEndpoint($connection, $endpoint): array
    {
        $client = new Client([
            'base_uri' => $connection->base_url,
            'timeout' => 15,
            'verify' => false,
        ]);

        $headers = CredentialHelper::getAuthHeader($connection->credential->toArray());
        $res = $client->request($endpoint->method ?? 'GET', $endpoint->path, ['headers' => $headers]);

        if ($res->getStatusCode() != 200) {
            throw new \Exception("HTTP error {$res->getStatusCode()}");
        }

        $body = (string)$res->getBody();
        $decoded = json_decode($body, true);
        if (!$decoded) {
            throw new \Exception("Invalid JSON response");
        }

        return $decoded;
    }
}
