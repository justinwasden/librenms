<?php
namespace App\Services\RestApi\Auth;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use App\Models\RestApiConnection;
use App\Models\RestApiCredential;

class SessionTokenAuthStrategy implements AuthStrategyInterface
{
    public function getName(): string { return 'session token'; }

    public function prepareRequest(RestApiConnection $connection, RestApiCredential $credential, string $httpMethod)
    {
        $cacheKey = "pure:session:" . $connection->id;
        $token = Cache::get($cacheKey);

        if (!$token) {
            $token = $this->login($connection, $credential);
            if (!$token) {
                throw new \\Exception("PureStorage login failed: no session token");
            }
            $ttl = (int)($credential->getParamValue('session_ttl', 3600));
            Cache::put($cacheKey, $token, $ttl);
        }

        $header = $credential->getParamValue('token_header', 'x-auth-token');

        return Http::withOptions([
            'verify' => !$connection->disable_ssl_verify,
            'timeout' => 30
        ])->withHeaders([$header => $token]);
    }

    protected function login(RestApiConnection $connection, RestApiCredential $credential): ?string
    {
        $baseUrl = rtrim($connection->base_url, '/');
        $loginPath = $credential->getParamValue('login_path', 'api/2.26/login');
        $method = strtoupper($credential->getParamValue('login_method', 'POST'));
        $apiHeader = $credential->getParamValue('api_token_header', 'api-token');
        $apiKey = $credential->getParamValue('api_token');

        if (!$apiKey) {
            throw new \\Exception("PureStorage session login missing api_token");
        }

        $url = $baseUrl . '/' . ltrim($loginPath, '/');

        $req = Http::withOptions([
            'verify' => !$connection->disable_ssl_verify,
            'timeout' => 30,
        ])->withHeaders([
            $apiHeader => $apiKey,
            'Content-Type' => 'application/json',
        ]);

        $resp = $req->{$method}($url, []);

        if (!$resp->successful()) {
            Log::error("PureStorage login HTTP error", ['status' => $resp->status(), 'body' => $resp->body()]);
            return null;
        }

        $headerName = $credential->getParamValue('token_header', 'x-auth-token');
        $token = $resp->header($headerName);
        if (!$token) {
            Log::error("PureStorage login missing session token header", ['expected' => $headerName]);
            return null;
        }
        return $token;
    }
}