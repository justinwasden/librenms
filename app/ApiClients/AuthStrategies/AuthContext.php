<?php

namespace App\ApiClients\AuthStrategies;

class AuthContext
{
    public array $headers = [];
    public array $cookies = [];
    public ?string $token = null;
    public ?int $expiresAtUnix = null;

    public function isExpired(): bool
    {
        return $this->expiresAtUnix !== null && time() >= $this->expiresAtUnix;
    }
}