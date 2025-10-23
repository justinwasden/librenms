<?php
namespace App\\Services\\RestApi\\Auth;

use Illuminate\\Support\\Facades\\Http;
use App\\Models\\RestApiConnection;
use App\\Models\\RestApiCredential;

class BearerAuthStrategy implements AuthStrategyInterface
{
    public function getName(): string { return 'bearer'; }

    public function prepareRequest(RestApiConnection $connection, RestApiCredential $credential, string $httpMethod)
    {
        $token = $credential->getParamValue('token', '');
        return Http::withOptions([
            'verify' => !$connection->disable_ssl_verify,
            'timeout' => 30
        ])->withToken($token);
    }
}