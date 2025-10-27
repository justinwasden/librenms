<?php

namespace App\ApiClients\AuthStrategies;

class BearerAuthStrategy implements AuthStrategyInterface
{
    public function authenticate(\App\Models\Device $device, array $options): AuthContext
    {
        $ctx = new AuthContext();
        $token = (string) ($options['values']['api_bearer_token'] ?? '');
        $ctx->headers['Authorization'] = 'Bearer ' . $token;

        return $ctx;
    }

    public function apply(array $requestOptions, AuthContext $context): array
    {
        $requestOptions['headers'] = array_merge(($requestOptions['headers'] ?? []), $context->headers);

        return $requestOptions;
    }

    public function refresh(AuthContext $context): AuthContext { return $context; }
}