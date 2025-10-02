<?php

namespace App\Http\Controllers\Device\Tabs;

use App\Models\Device;
use LibreNMS\Interfaces\UI\DeviceTab;

class RestApiTab implements DeviceTab
{
    public function visible(Device $device): bool
    {
        // Show tab if device has API connections or if user is admin
        return $device->restApiConnections()->exists() || auth()->user()?->isAdmin();
    }

    public function slug(): string
    {
        return 'rest-api';
    }

    public function icon(): string
    {
        return 'fa-cloud';
    }

    public function name(): string
    {
        return 'REST API';
    }

    public function data(Device $device): array
    {
        return [];
    }
}