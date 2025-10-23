<?php
namespace App\Services\RestApi\Auth;

use Illuminate\Support\Facades\Http;
use App\Models\RestApiConnection;
use App\Models\RestApiCredential;

class BasicAuthStrategy implements AuthStrategyInterface
{
    public function getName(): string { return 'basic'; }

    public function prepareRequest(RestApiConnection $connection, RestApiCredential $credential, string $httpMethod)
    {
        $user = $credential->getParamValue('username', '');
        $pass = $credential->getParamValue('password', '');
        return Http::withOptions([
            'verify' => !$connection->disable_ssl_verify,
            'timeout' => 30
        ])->withBasicAuth($user, $pass);
    }
}