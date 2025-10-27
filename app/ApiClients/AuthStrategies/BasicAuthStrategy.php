<?php

namespace App\ApiClients\AuthStrategies;

class BasicAuthStrategy implements AuthStrategyInterface
{
    public function authenticate(\App\Models\Device $device, array $options): AuthContext
    {
        $ctx = new AuthContext();
        $user = (string) ($options['values']['api_username'] ?? '');
        $pass = (string) ($options['values']['api_password'] ?? '');
        $ctx->headers['Authorization'] = 'Basic ' . base64_encode($user . ':' . $pass);

        return $ctx;
    }

    public function apply(array $requestOptions, AuthContext $context): array
    {
        $requestOptions['headers'] = array_merge(($requestOptions['headers'] ?? []), $context->headers);

        return $requestOptions;
    }

    public function refresh(AuthContext $context): AuthContext { return $context; }
}