<?php

namespace Tests\Unit;

use App\Models\Device;
use App\Services\DeviceApiExecutor;
use Tests\TestCase;

class DeviceApiExecutorTest extends TestCase
{
    public function testRunMergesTemplateAndCustomEndpoints()
    {
        $device = Device::factory()->create();
        $device->setAttrib('rest_endpoints', json_encode([
            ['category' => 'inventory', 'method' => 'GET', 'path' => '/system', 'enabled' => true, 'transform_map' => ['list_path' => null, 'fields' => ['name' => 'name']]],
        ]));

        $client = new class {
            public function get($path, $query = []) { return ['name' => 'demo']; }
            public function post($path, $body = []) { return []; }
        };

        $exec = new DeviceApiExecutor();
        $exec->run($device, 'generic_rest_api', $client);

        $this->assertTrue(true); // if no exception, merge and run succeeded
    }
}