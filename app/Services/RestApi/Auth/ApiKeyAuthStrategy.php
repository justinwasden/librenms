<?php
namespace App\\Services\\RestApi\\Auth;

use Illuminate\\Support\\Facades\\Http;
use App\\Models\\RestApiConnection;
use App\\Models\\RestApiCredential;

class ApiKeyAuthStrategy implements AuthStrategyInterface
{
    public function getName(): string { return 'api_key'; }

    public function prepareRequest(RestApiConnection $connection, RestApiCredential $credential, string $httpMethod)
    {
        $header = $credential->getParamValue('header_name', 'X-API-Key');
        $value = $credential->getParamValue('api_key', '');
        return Http::withOptions([
            'verify' => !$connection->disable_ssl_verify,
            'timeout' => 30
        ])->withHeaders([$header => $value]);
    }
}