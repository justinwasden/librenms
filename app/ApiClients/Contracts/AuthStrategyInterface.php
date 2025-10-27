<?php

namespace App\ApiClients\Contracts;

use App\Models\Device;

interface AuthStrategyInterface
{
    /**
     * Perform authentication and return an auth context (tokens/cookies/headers).
     *
     * @param Device $device
     * @param array $config Per-device API config: base_url, verify_ssl, timeout_ms, proxy, values (field map), extra_headers
     * @return array Auth context to be applied to subsequent requests (e.g., headers, expiry_ts)
     *
     * Throws on unrecoverable errors.
     */
    public function authenticate(Device $device, array $config): array;

    /**
     * Apply authentication context to an HTTP request options array.
     *
     * @param array $httpOptions e.g., ['headers' => [], 'cookies' => [], 'verify' => true, 'timeout' => 5]
     * @param array $authContext Returned from authenticate()
     * @return array Modified options with auth headers/cookies
     */
    public function apply(array $httpOptions, array $authContext): array;

     /**
     * Optionally refresh tokens/cookies if expired.
     */
    public function refresh(AuthContext $context): AuthContext;
}