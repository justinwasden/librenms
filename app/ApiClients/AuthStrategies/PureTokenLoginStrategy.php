<?php

namespace App\ApiClients\AuthStrategies;

use App\ApiClients\Contracts\AuthStrategyInterface;
use App\Models\Device;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

class PureTokenLoginStrategy implements AuthStrategyInterface
{
    /**
     * Authenticate by sending login header (api-token) to login_url and obtaining session header (X-Auth-Token).
     *
     * Expected config keys:
     *  - base_url      (string)
     *  - verify_ssl    (bool)
     *  - timeout_ms    (int)
     *  - proxy         (string|null)
     *  - values        (array) contains:
     *      - api_login_url               string
     *      - api_login_header_key        string (e.g., 'api-token')
     *      - api_login_header_value      encrypted or plaintext token
     *      - api_session_header_key      string (e.g., 'X-Auth-Token')
     *      - api_session_expiry_minutes  int (optional, default 30)
     */
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

        // Use cached token if valid
        if (is_array($cached) && isset($cached['token'], $cached['expires'])) {
            $ctx->token = $cached['token'];
            $ctx->expiresAtUnix = (int) $cached['expires'];
            $ctx->headers[$sessionHeaderKey] = $ctx->token;

            return $ctx;
        }

        // Decrypted API token (provided by UI)
        $apiToken = (string) ($options['values']['api_login_header_value'] ?? '');
        if (empty($apiToken) && !empty($options['values']['api_login_header_value_enc'])) {
            // if you store encrypted, decrypt before calling this strategy
            $apiToken = (string) $options['values']['api_login_header_value_enc'];
        }

        $req = Http::withHeaders([$loginHeaderKey => $apiToken])
            ->timeout(($options['timeout_ms'] ?? 5000) / 1000)
            ->withOptions(['verify' => (bool) ($options['verify_ssl'] ?? true)]);

        if (!empty($options['proxy'])) {
            $req = $req->withOptions(['proxy' => $options['proxy']]);
        }

        $resp = $req->post($loginUrl);
        if ($resp->failed()) {
            throw new \RuntimeException('Pure login failed: ' . $resp->status());
        }

        // Token usually in response headers
        $sessionToken = $resp->header($sessionHeaderKey);
        if (!$sessionToken) {
            // Some arrays return in JSON body instead
            $json = $resp->json();
            $sessionToken = $json['token'] ?? $json[$sessionHeaderKey] ?? null;
        }

        if (!$sessionToken) {
            throw new \RuntimeException('Pure login did not return a session token');
        }

        $ctx->token = $sessionToken;
        $ctx->expiresAtUnix = time() + ($expiryMinutes * 60);
        $ctx->headers[$sessionHeaderKey] = $sessionToken;

        // Cache session
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
        // Cookies if needed:
        if (!empty($context->cookies)) {
            $requestOptions['_cookies'] = $context->cookies; // your DeviceHttpClient can read this key and apply withCookies()
        }

        return $requestOptions;
    }

    public function refresh(AuthContext $context): AuthContext
    {
        // For Pure, re-login when expired (handled by authenticate cache behavior in client).
        return $context;
    }
}