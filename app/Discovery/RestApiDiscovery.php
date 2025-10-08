<?php
namespace app\Discovery;

use App\Models\Device;
use App\Pollers\ApiMetricsCollector;
use App\RestApi\Utils\JsonFlattener;
use App\RestApi\Credentials\CredentialHelper;
use GuzzleHttp\Client;
use Log;

class RestApiDiscovery
{
    protected Device $device;
    protected ApiMetricsCollector $collector;

    public function __construct(Device $device)
    {
        $this->device = $device;
        $this->collector = new ApiMetricsCollector($device);
    }

    public function discover()
    {
        $connections = $this->device->restApiConnections()->where('enabled', 1)->get();

        foreach ($connections as $conn) {
            foreach ($conn->endpoints as $endpoint) {
                try {
                    $response = $this->requestEndpoint($conn, $endpoint);
                    $metrics = JsonFlattener::flatten($response, $endpoint->resource_type . '_');
                    $this->collector->storeMetric($endpoint->resource_type, $endpoint->name, $metrics);
                } catch (\Exception $e) {
                    Log::error("Discovery failed for {$endpoint->name} on {$this->device->hostname}: {$e->getMessage()}");
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
