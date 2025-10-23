<?php
namespace App\Services\RestApi\Auth;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use App\Models\RestApiConnection;
use App\Models\RestApiCredential;

class ProxmoxAuthStrategy implements AuthStrategyInterface
{
    public function getName(): string
    {
        return 'proxmox';
    }

    public function prepareRequest(RestApiConnection $connection, RestApiCredential $credential, string $httpMethod)
    {
        $cacheKey = "proxmox:session:" . $connection->id;
        $session = Cache::get($cacheKey);

        if (!$session || !$this->isSessionValid($session)) {
            $session = $this->login($connection, $credential);
            if (!$session) {
                throw new \\Exception("Proxmox login failed: no session");
            }
            $ttl = (int)($credential->getParamValue('session_ttl', 3600));
            Cache::put($cacheKey, $session, $ttl);
        }

        $ticket = $session['ticket'] ?? null;
        $csrf = $session['csrf'] ?? null;
        if (!$ticket) {
            throw new \\Exception("Proxmox session missing PVEAuthCookie ticket");
        }

        $options = [
            'verify' => !$connection->disable_ssl_verify,
            'timeout' => 30,
            'headers' => [
                'Cookie' => "PVEAuthCookie={$ticket}"
            ]
        ];

        $request = Http::withOptions($options);

        // For write methods, add CSRF header
        $writeMethods = ['POST', 'PUT', 'DELETE', 'PATCH'];
        if (in_array(strtoupper($httpMethod), $writeMethods) && $csrf) {
            $request = $request->withHeaders([
                'CSRFPreventionToken' => $csrf
            ]);
        }

        return $request;
    }

    protected function login(RestApiConnection $connection, RestApiCredential $credential): ?array
    {
        $baseUrl = rtrim($connection->base_url, '/');
        $loginUrl = $baseUrl . '/api2/json/access/ticket';

        $username = $credential->getParamValue('username');
        $password = $credential->getParamValue('password');
        $realm = $credential->getParamValue('realm', 'pam');

        if (!$username || !$password) {
            throw new \\Exception("Proxmox credentials missing username or password");
        }

        $fullUser = "{$username}@{$realm}";
        $payload = [
            'username' => $fullUser,
            'password' => $password
        ];

        $response = Http::withOptions([
            'verify' => !$connection->disable_ssl_verify,
            'timeout' => 30,
            'headers' => ['Content-Type' => 'application/json']
        ])->post($loginUrl, $payload);

        if (!$response->successful()) {
            Log::error("Proxmox login HTTP error", ['status' => $response->status(), 'body' => $response->body()]);
            return null;
        }

        $data = $response->json();
        $ticket = $data['data']['ticket'] ?? null;
        $csrf = $data['data']['CSRFPreventionToken'] ?? null;

        if (!$ticket) {
            Log::error("Proxmox login did not return ticket", ['data' => $data]);
            return null;
        }

        Log::info("Proxmox login OK for connection {$connection->id}", [
            'device_id' => $connection->device_id
        ]);

        return ['ticket' => $ticket, 'csrf' => $csrf];
    }

    protected function isSessionValid(array $session): bool
    {
        return !empty($session['ticket']);
    }
}