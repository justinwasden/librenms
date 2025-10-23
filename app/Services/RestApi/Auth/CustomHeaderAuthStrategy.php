<?php
namespace App\\Services\\RestApi\\Auth;

use Illuminate\\Support\\Facades\\Http;
use App\\Models\\RestApiConnection;
use App\\Models\\RestApiCredential;

class CustomHeaderAuthStrategy implements AuthStrategyInterface
{
    public function getName(): string { return 'custom'; }

    public function prepareRequest(RestApiConnection $connection, RestApiCredential $credential, string $httpMethod)
    {
        $headers = [];
        foreach ($credential->params as $param) {
            $headers[$param->key] = $param->value;
        }
        return Http::withOptions([
            'verify' => !$connection->disable_ssl_verify,
            'timeout' => 30
        ])->withHeaders($headers);
    }
}