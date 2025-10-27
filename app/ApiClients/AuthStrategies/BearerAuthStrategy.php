<?php

namespace App\ApiClients\AuthStrategies;

use App\ApiClients\Contracts\AuthStrategyInterface;
use App\ApiClients\AuthStrategies\AuthContext;
use App\Models\Device;

class BearerAuthStrategy implements AuthStrategyInterface
{
    public function authenticate(Device $device, array $options): AuthContext
    {
        $ctx = new AuthContext();
        $v = $options['values'] ?? [];

        $token = (string) ($v['access_token'] ?? $v['api_bearer_token'] ?? $v['api_token'] ?? '');
        $ctx->headers['Authorization'] = 'Bearer ' . $token;

        return $ctx;
    }

    public function apply(array $requestOptions, AuthContext $context): array
    {
        $requestOptions['headers'] = array_merge(($requestOptions['headers'] ?? []), $context->headers);
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