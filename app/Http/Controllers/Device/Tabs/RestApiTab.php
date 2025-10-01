<?php

namespace App\Http\Controllers\Device\Tabs;

use App\Models\Device;
use App\Models\RestApiTemplate;
use Illuminate\Http\Request;
use LibreNMS\Interfaces\UI\DeviceTab;

class RestApiTab implements DeviceTab
{
    public function visible(Device $device): bool
    {
        // Show tab if user is admin or device has connections
        return auth()->user()?->isAdmin() || $device->restApiConnections()->exists();
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

    public function data(Device $device, Request $request): array
    {
        $device->load([
            'restApiConnections.credential',
            'restApiConnections.endpoints'
        ]);

        $templates = RestApiTemplate::all();

        return [
            'device' => $device,
            'templates' => $templates,
        ];
    }
}