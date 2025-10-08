<?php
namespace App\RestApi\Credentials;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CredentialHelper
{
    /**
     * Get an authorization header for a given connection type.
     * Supports Basic Auth, API Token, and Session Token.
     *
     * @param array $credential
     *  [
     *      'type' => 'basic'|'token'|'session',
     *      'username' => '',
     *      'password' => '',
     *      'token' => '',
     *      'auth_url' => '', // For session
     *  ]
     */
    public static function getAuthHeader(array $credential): array
    {
        $type = strtolower($credential['type'] ?? 'none');

        switch ($type) {
            case 'basic':
                $user = $credential['username'] ?? '';
                $pass = $credential['password'] ?? '';
                $encoded = base64_encode("{$user}:{$pass}");
                return ['Authorization' => "Basic {$encoded}"];
            case 'token':
                $token = $credential['token'] ?? '';
                return ['Authorization' => "Bearer {$token}"];
            case 'session':
                // Cache session token per connection
                $cacheKey = 'restapi_session_' . md5($credential['auth_url'] ?? '');
                if (Cache::has($cacheKey)) {
                    $token = Cache::get($cacheKey);
                } else {
                    $token = self::fetchSessionToken($credential['auth_url'], $credential);
                    Cache::put($cacheKey, $token, 300); // 5 min cache
                }
                return ['X-Auth-Token' => $token];
            default:
                Log::warning("Unknown credential type: {$type}");
                return [];
        }
    }

    protected static function fetchSessionToken(string $url, array $credential): string
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'username' => $credential['username'] ?? '',
            'password' => $credential['password'] ?? ''
        ]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        $response = curl_exec($ch);
        $info = curl_getinfo($ch);
        if ($info['http_code'] != 200) {
            throw new \Exception("Failed to fetch session token from {$url}");
        }
        $data = json_decode($response, true);
        return $data['token'] ?? '';
    }
}
