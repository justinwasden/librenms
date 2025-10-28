<?php

namespace App\ApiClients\AuthStrategies;

use App\ApiClients\Contracts\AuthStrategyInterface;
use App\Models\Device;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class PureTokenLoginStrategy implements AuthStrategyInterface
{
    public function authenticate(Device $device, array $options): AuthContext
    {
        $baseUrl = rtrim((string) ($options['base_url'] ?? ''), '/');
        $loginUrl = (string) ($options['login_url'] ?? ($baseUrl . '/login'));
        $loginHeaderKey = (string) ($options['login_header_key'] ?? 'api-token');
        $sessionHeaderKey = (string) ($options['session_header_key'] ?? 'X-Auth-Token');
        $expiryMinutes = (int) ($options['session_expiry_minutes'] ?? 30);

        $cachedKey = "api:device:{$device->device_id}:pure_session";
        $cached = Cache::get($cachedKey);

        $ctx = new AuthContext();

        if (is_array($cached) && isset($cached['token'], $cached['expires']) && time() < (int)$cached['expires']) {
            $ctx->token = $cached['token'];
            $ctx->expiresAtUnix = (int) $cached['expires'];
            $ctx->headers[$sessionHeaderKey] = $ctx->token;
            return $ctx;
        }

        $apiToken = (string) ($options['values']['api_login_header_value'] ?? '');

        if (empty($apiToken)) {
            throw new \RuntimeException('PureStorage API token is required for authentication');
        }

        $req = Http::withHeaders([$loginHeaderKey => $apiToken])
            ->timeout(($options['timeout_ms'] ?? 5000) / 1000)
            ->withOptions(['verify' => (bool) ($options['verify_ssl'] ?? true)]);

        if (!empty($options['proxy'])) {
            $req = $req->withOptions(['proxy' => $options['proxy']]);
        }

        $resp = $req->post($loginUrl);
        if ($resp->failed()) {
            $body = $resp->body();
            $errorDetail = $body ? " - Response: $body" : '';
            throw new \RuntimeException('Pure login failed: ' . $resp->status() . $errorDetail);
        }

        $sessionToken = $resp->header($sessionHeaderKey);
        if (!$sessionToken) {
            $json = $resp->json();
            $sessionToken = $json['token'] ?? $json[$sessionHeaderKey] ?? null;
        }

        if (!$sessionToken) {
            throw new \RuntimeException('Pure login did not return a session token');
        }

        $ctx->token = $sessionToken;
        $ctx->expiresAtUnix = time() + ($expiryMinutes * 60);
        $ctx->headers[$sessionHeaderKey] = $sessionToken;

        Cache::put($cachedKey, ['token' => $sessionToken, 'expires' => $ctx->expiresAtUnix], now()->addMinutes($expiryMinutes));

        return $ctx;
    }

    public function apply(array $requestOptions, AuthContext $context): array
    {
        $headers = $requestOptions['headers'] ?? [];
        foreach ($context->headers as $k => $v) {
            $headers[$k] = $v;
        }
        $requestOptions['headers'] = $headers;

        if (!empty($context->cookies)) {
            $requestOptions['_cookies'] = $context->cookies;
        }

        return $requestOptions;
    }

    public function refresh(AuthContext $context): AuthContext
    {
        return $context;
    }
}