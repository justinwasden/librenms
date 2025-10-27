<?php

namespace App\ApiClients\Contracts;

use App\Models\Device;
use App\ApiClients\AuthStrategies\AuthContext;

interface AuthStrategyInterface
{
    /**
     * Perform authentication and return an auth context (tokens/cookies/headers).
     *
     * @param Device $device
     * @param array $config Per-device API config: base_url, verify_ssl, timeout_ms, proxy, values (field map), extra_headers
     * @return AuthContext
     */
    public function authenticate(Device $device, array $config): AuthContext;

    /**
     * Apply authentication context to an HTTP request options array.
     *
     * @param array $httpOptions e.g., ['headers' => [], 'cookies' => [], 'verify' => true, 'timeout' => 5]
     * @param AuthContext $authContext Returned from authenticate()
     * @return array Modified options with auth headers/cookies
     */
    public function apply(array $httpOptions, AuthContext $authContext): array;

    /**
     * Optionally refresh tokens/cookies if expired.
     *
     * @param AuthContext $context
     * @return AuthContext
     */
    public function refresh(AuthContext $context): AuthContext;
}