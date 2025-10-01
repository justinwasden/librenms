<?php

namespace App\Pollers;

use App\Models\Device;
use App\Models\RestApiEndpoint;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class Api
{
    protected Device $device;
    protected array $poller_options;
    protected Client $client;

    public function __construct(Device $device, array $poller_options = [], Client $client = null)
    {
        $this->device = $device;
        $this->poller_options = $poller_options;
        $this->client = $client ?? new Client(['timeout' => 10, 'connect_timeout' => 5]);
    }

    public function poll()
    {
		    $this->device->load([
		        'restApiConnections.credential.params',
		        'restApiConnections.credential.authenticationType',
		        'restApiConnections.endpoints'
		    ]);

		    if ($this->device->restApiConnections->isEmpty()) {
		        return;
		    }

        Log::info("Polling REST APIs for device {$this->device->hostname}");

        foreach ($this->device->restApiConnections as $connection) {
            $this->device->load('restApiConnections.credential.params', 'restApiConnections.credential.authenticationType');

            foreach ($connection->endpoints as $endpoint) {
                try {
                    $options = [];
                    // Handle Authentication
                    if ($credential = $connection->credential) {
                        $authType = strtolower($credential->authenticationType->name);
                        $params = $credential->params->pluck('value', 'key');

                        if ($authType === 'basic auth' && isset($params['username'], $params['password'])) {
                            $options['auth'] = [$params['username'], $params['password']];
                        } elseif ($authType === 'token' && isset($params['token'], $params['header'])) {
                            $scheme = !empty($params['scheme']) ? $params['scheme'] . ' ' : '';
                            $options['headers'][$params['header']] = $scheme . $params['token'];
                        }
                    }

                    // Add other headers, query params, body from endpoint definition
                    if ($endpoint->headers) {
                        $options['headers'] = array_merge($options['headers'] ?? [], $endpoint->headers);
                    }
                    if ($endpoint->query_params) {
                        $options['query'] = $endpoint->query_params;
                    }
                    if ($endpoint->body) {
                        $options['json'] = $endpoint->body;
                    }

                    $url = $this->replacePlaceholders($connection->base_url . $endpoint->path);
                    Log::debug("Polling URL: {$url} for device {$this->device->hostname}");

                    $response = $this->client->request($endpoint->method, $url, $options);

                    $body = json_decode($response->getBody()->getContents(), true);

                    if (json_last_error() !== JSON_ERROR_NONE) {
                        Log::warning("Invalid JSON response from {$url} for device {$this->device->hostname}");
                        continue;
                    }

                    if ($body && $endpoint->metric_map) {
                        $this->mapData($endpoint, $body);
                    }

                    $endpoint->update(['last_polled' => Carbon::now()]);
                } catch (RequestException $e) {
                    $message = $e->getMessage();
                    if ($e->hasResponse()) {
                        $message .= ' | Response: ' . Str::limit($e->getResponse()->getBody(), 200);
                    }
                    Log::error("Failed to poll REST API endpoint {$endpoint->name} for device {$this->device->hostname}: " . $message);
                } catch (\Exception $e) {
                    Log::error("An unexpected error occurred while polling endpoint {$endpoint->name}: " . $e->getMessage());
                }
            }
        }
    }

    protected function mapData(RestApiEndpoint $endpoint, array $data)
    {
        Log::debug("Mapping data for endpoint {$endpoint->name}", ['map' => $endpoint->metric_map]);
        foreach ($endpoint->metric_map as $metricName => $apiPath) {
            $values = data_get($data, $apiPath);

            if ($values === null) {
                Log::debug("Metric '$metricName' with path '$apiPath' not found in API response for endpoint {$endpoint->name}.");
                continue;
            }

            if (is_array($values) && Arr::isList($values)) {
                foreach ($values as $index => $value) {
                    $this->storeMetric($endpoint, "{$metricName}.{$index}", $value);
                }
            } else {
                $this->storeMetric($endpoint, $metricName, $values);
            }
        }
    }

    protected function storeMetric(RestApiEndpoint $endpoint, string $metricName, $value)
    {
        $storageValue = is_scalar($value) ? $value : json_encode($value);

        Log::info("Storing metric for {$this->device->hostname}", [
            'endpoint' => $endpoint->name,
            'metric' => $metricName,
            'value' => Str::limit((string)$storageValue, 100),
        ]);

        $endpoint->metrics()->create([
            'metric_name' => $metricName,
            'metric_value' => $storageValue,
            'collected_at' => Carbon::now(),
        ]);
    }

    private function replacePlaceholders(string $string): string
    {
        $string = Str::replace('{{ $device->hostname }}', $this->device->hostname, $string);
        $string = Str::replace('{{ $device->ip }}', $this->device->ip, $string);

        preg_match_all('/\{\{ \$device->getAttrib\(\'(.*?)\'\) \}\}/', $string, $matches);
        foreach ($matches[1] as $attribName) {
            $attribValue = $this->device->getAttrib($attribName);
            $string = Str::replace("{{ \$device->getAttrib('$attribName') }}", $attribValue, $string);
        }

        return $string;
    }
}