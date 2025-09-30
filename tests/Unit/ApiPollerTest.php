<?php

namespace LibreNMS\Tests\Unit;

use App\Models\Device;
use App\Models\RestApiConnection;
use App\Models\RestApiEndpoint;
use App\Pollers\Api as ApiPoller;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use LibreNMS\Tests\DBTestCase;

class ApiPollerTest extends DBTestCase
{
    use DatabaseTransactions;

    public function testPollsAndMapsDataCorrectly()
    {
        // 1. Prepare the mock response
        $mockData = [
            'system' => [
                'cpu' => ['load' => 75.5],
                'memory' => ['free' => 1024, 'used' => 3072],
            ],
            'interfaces' => [
                ['name' => 'eth0', 'status' => 'up', 'in_errors' => 5],
                ['name' => 'eth1', 'status' => 'down', 'in_errors' => 0],
            ]
        ];
        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode($mockData)),
        ]);
        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);

        // 2. Set up the device and API configuration
        $device = Device::factory()->create();
        $connection = RestApiConnection::factory()->for($device)->create();
        $endpoint = RestApiEndpoint::factory()->for($connection)->create([
            'metric_map' => [
                'cpu_load' => 'system.cpu.load',
                'mem_free' => 'system.memory.free',
                'if_status' => 'interfaces.*.status',
            ]
        ]);

        // 3. Run the poller
        $poller = new ApiPoller($device, [], $client);
        $poller->poll();

        // 4. Assert the results
        $this->assertDatabaseHas('rest_api_metrics', [
            'endpoint_id' => $endpoint->id,
            'metric_name' => 'cpu_load',
            'metric_value' => '75.5',
        ]);

        $this->assertDatabaseHas('rest_api_metrics', [
            'endpoint_id' => $endpoint->id,
            'metric_name' => 'mem_free',
            'metric_value' => '1024',
        ]);

        $this->assertDatabaseHas('rest_api_metrics', [
            'endpoint_id' => $endpoint->id,
            'metric_name' => 'if_status.0',
            'metric_value' => 'up',
        ]);

        $this->assertDatabaseHas('rest_api_metrics', [
            'endpoint_id' => $endpoint->id,
            'metric_name' => 'if_status.1',
            'metric_value' => 'down',
        ]);

        // 5. Check that last_polled was updated
        $this->assertNotNull($endpoint->fresh()->last_polled);
    }
}