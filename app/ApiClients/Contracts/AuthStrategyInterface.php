<?php

namespace App\ApiClients\Contracts;

use App\ApiClients\TestableDevice;
use App\Models\Device;
use App\ApiClients\AuthStrategies\AuthContext;

interface AuthStrategyInterface
{
    public function authenticate(Device|TestableDevice $device, array $config): AuthContext;

    public function apply(array $httpOptions, AuthContext $authContext): array;

    public function refresh(AuthContext $context): AuthContext;
}