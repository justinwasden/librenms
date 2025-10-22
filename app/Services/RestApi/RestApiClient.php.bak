<?php

namespace App\Services\RestApi;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;
use Exception;

class RestApiClient
{
    protected Client $client;
    protected int $timeout = 30;

    public function __construct()
    {
        $this->client = new Client([
            'verify' => false,
            'timeout' => $this->timeout,
        ]);
    }

    public function call(
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null
    ): array {
        try {
            $options = [
                'headers' => array_merge([
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ], $headers),
            ];

            if ($body) {
                $options['body'] = $body;
            }

            $response = $this->client->request($method, $url, $options);
            $content = $response->getBody()->getContents();
            
            return [
                'success' => true,
                'status_code' => $response->getStatusCode(),
                'data' => json_decode($content, true),
                'raw' => $content,
            ];
        } catch (GuzzleException $e) {
            Log::error("REST API call failed: {$e->getMessage()}", [
                'url' => $url,
                'method' => $method,
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'status_code' => $e->getResponse()?->getStatusCode() ?? 0,
            ];
        } catch (Exception $e) {
            Log::error("Unexpected error in REST API call: {$e->getMessage()}");

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    public function get(string $url, array $headers = []): array
    {
        return $this->call('GET', $url, $headers);
    }

    public function post(string $url, string $body, array $headers = []): array
    {
        return $this->call('POST', $url, $headers, $body);
    }
}
