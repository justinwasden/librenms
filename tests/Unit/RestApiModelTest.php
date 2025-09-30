<?php

namespace LibreNMS\Tests\Unit;

use App\Models\Device;
use App\Models\RestApiAuthenticationType;
use App\Models\RestApiConnection;
use App\Models\RestApiCredential;
use App\Models\RestApiCredentialParam;
use App\Models\RestApiEndpoint;
use App\Models\RestApiMetric;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use LibreNMS\Tests\DBTestCase;

class RestApiModelTest extends DBTestCase
{
    use DatabaseTransactions;

    public function testRestApiAuthenticationTypeRelationships()
    {
        $type = RestApiAuthenticationType::factory()
            ->has(RestApiCredential::factory()->count(3))
            ->create();

        $this->assertCount(3, $type->credentials);
        $this->assertInstanceOf(RestApiCredential::class, $type->credentials->first());
    }

    public function testRestApiCredentialRelationships()
    {
        $credential = RestApiCredential::factory()
            ->for(RestApiAuthenticationType::factory())
            ->has(RestApiCredentialParam::factory()->count(2))
            ->has(RestApiConnection::factory()->count(1))
            ->create();

        $this->assertInstanceOf(RestApiAuthenticationType::class, $credential->authenticationType);
        $this->assertCount(2, $credential->params);
        $this->assertInstanceOf(RestApiCredentialParam::class, $credential->params->first());
        $this->assertCount(1, $credential->connections);
        $this->assertInstanceOf(RestApiConnection::class, $credential->connections->first());
    }

    public function testRestApiCredentialParamRelationships()
    {
        $param = RestApiCredentialParam::factory()
            ->for(RestApiCredential::factory())
            ->create();

        $this->assertInstanceOf(RestApiCredential::class, $param->credential);
    }

    public function testRestApiConnectionRelationships()
    {
        $connection = RestApiConnection::factory()
            ->for(Device::factory())
            ->for(RestApiCredential::factory())
            ->has(RestApiEndpoint::factory()->count(5))
            ->create();

        $this->assertInstanceOf(Device::class, $connection->device);
        $this->assertInstanceOf(RestApiCredential::class, $connection->credential);
        $this->assertCount(5, $connection->endpoints);
        $this->assertInstanceOf(RestApiEndpoint::class, $connection->endpoints->first());
    }

    public function testRestApiEndpointRelationships()
    {
        $endpoint = RestApiEndpoint::factory()
            ->for(RestApiConnection::factory())
            ->has(RestApiMetric::factory()->count(10))
            ->create();

        $this->assertInstanceOf(RestApiConnection::class, $endpoint->connection);
        $this->assertCount(10, $endpoint->metrics);
        $this->assertInstanceOf(RestApiMetric::class, $endpoint->metrics->first());
    }

    public function testRestApiMetricRelationships()
    {
        $metric = RestApiMetric::factory()
            ->for(RestApiEndpoint::factory())
            ->create();

        $this->assertInstanceOf(RestApiEndpoint::class, $metric->endpoint);
    }

    public function testDeviceRelationship()
    {
        $device = Device::factory()
            ->has(RestApiConnection::factory()->count(2))
            ->create();

        $this->assertCount(2, $device->restApiConnections);
        $this->assertInstanceOf(RestApiConnection::class, $device->restApiConnections->first());
    }
}