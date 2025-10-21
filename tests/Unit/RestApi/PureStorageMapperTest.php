<?php

namespace Tests\Unit\RestApi;

use App\Models\Device;
use App\Models\RestApiEndpoint;
use App\RestApi\Vendors\Mappers\PureStorageMapper;
use PHPUnit\Framework\TestCase;

class PureStorageMapperTest extends TestCase
{
    private PureStorageMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new PureStorageMapper();
    }

    /**
     * Test mapper recognizes Pure Storage devices
     */
    public function testCanHandlePureStorageDevices()
    {
        $device = new Device(['os' => 'purestorage']);
        $endpoint = new RestApiEndpoint();

        $this->assertTrue($this->mapper->canHandle($device, $endpoint));
    }

    public function testRejectsNonPureStorageDevices()
    {
        $device = new Device(['os' => 'cisco']);
        $endpoint = new RestApiEndpoint();

        $this->assertFalse($this->mapper->canHandle($device, $endpoint));
    }

    /**
     * Test volume endpoint recommendations
     */
    public function testRecommendsVolumeMapping()
    {
        $endpoint = new RestApiEndpoint(['path' => '/volumes']);

        $response = [
            'items' => [
                [
                    'name' => 'volume1',
                    'provisioned' => 1000000,
                    'space' => ['total_used' => 500000],
                ]
            ]
        ];

        $recommendations = $this->mapper->getRecommendedMappings($response, $endpoint);

        $this->assertArrayHasKey('name', $recommendations);
        $this->assertEquals('storage.storage_descr',
            $recommendations['name']['table'] . '.' . $recommendations['name']['field']
        );
    }

    /**
     * Test interface endpoint recommendations
     */
    public function testRecommendsInterfaceMapping()
    {
        $endpoint = new RestApiEndpoint(['path' => '/network-interfaces']);

        $response = [
            'items' => [
                [
                    'name' => 'ct0.eth0',
                    'enabled' => true,
                    'speed' => 1000000000,
                ]
            ]
        ];

        $recommendations = $this->mapper->getRecommendedMappings($response, $endpoint);

        $this->assertArrayHasKey('name', $recommendations);
        $this->assertEquals('ports.ifName',
            $recommendations['name']['table'] . '.' . $recommendations['name']['field']
        );
    }

    /**
     * Test field validation
     */
    public function testValidatesStorageFields()
    {
        $result = $this->mapper->validateMapping(
            'name',
            'my-volume',
            'storage',
            'storage_descr'
        );

        $this->assertTrue($result['valid']);
    }

    public function testRejectsIncompatibleTypes()
    {
        $result = $this->mapper->validateMapping(
            'metadata',
            ['complex' => 'array'],
            'storage',
            'storage_descr'
        );

        $this->assertFalse($result['valid']);
    }

    /**
     * Test compatible fields
     */
    public function testGetsCompatibleStorageFields()
    {
        $fields = $this->mapper->getCompatibleFields('storage', 'integer');

        $this->assertArrayHasKey('storage_size', $fields);
        $this->assertArrayHasKey('storage_used', $fields);
    }
}