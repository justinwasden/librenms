<?php

namespace App\ApiClients\AuthStrategies;

use App\ApiClients\Contracts\AuthStrategyInterface;
use App\ApiClients\AuthStrategies\AuthContext;
use App\Models\Device;

class BasicAuthStrategy implements AuthStrategyInterface
{
    public function authenticate(Device $device, array $options): AuthContext
    {
        $ctx = new AuthContext();
        $v = $options['values'] ?? [];

        // Canonical names with backward-compatible aliases
        $user = (string) ($v['username'] ?? $v['api_username'] ?? '');
        $pass = (string) ($v['password'] ?? $v['api_password'] ?? '');

        $ctx->headers['Authorization'] = 'Basic ' . base64_encode($user . ':' . $pass);

        return $ctx;
    }

    public function apply(array $requestOptions, AuthContext $context): array
    {
        $requestOptions['headers'] = array_merge(($requestOptions['headers'] ?? []), $context->headers);

        // Apply cookies if present
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