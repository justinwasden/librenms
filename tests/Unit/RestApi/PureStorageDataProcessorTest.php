<?php

namespace Tests\Unit\RestApi;

use App\RestApi\DataProcessors\PureStorageDataProcessor;
use PHPUnit\Framework\TestCase;

class PureStorageDataProcessorTest extends TestCase
{
    /**
     * Test volume filtering
     */
    public function testFiltersESXiVolumes()
    {
        $item = ['name' => 'ITS-RSA-ESXI-VM01'];
        $this->assertTrue(
            PureStorageDataProcessor::shouldFilter($item, 'volume')
        );
    }

    public function testFiltersZeroProvisionedVolumes()
    {
        $item = ['name' => 'test-vol', 'provisioned' => 0];
        $this->assertTrue(
            PureStorageDataProcessor::shouldFilter($item, 'volume')
        );
    }

    public function testKeepsValidVolumes()
    {
        $item = ['name' => 'app-data', 'provisioned' => 1000000];
        $this->assertFalse(
            PureStorageDataProcessor::shouldFilter($item, 'volume')
        );
    }

    /**
     * Test interface filtering
     */
    public function testFiltersInvalidInterfaces()
    {
        $item = ['name' => 'invalid-port'];
        $this->assertTrue(
            PureStorageDataProcessor::shouldFilter($item, 'network-interface')
        );
    }

    public function testKeepsValidControllerInterfaces()
    {
        $item = ['name' => 'ct0.eth0'];
        $this->assertFalse(
            PureStorageDataProcessor::shouldFilter($item, 'network-interface')
        );
    }

    public function testKeepsReplicationBond()
    {
        $item = ['name' => 'replbond'];
        $this->assertFalse(
            PureStorageDataProcessor::shouldFilter($item, 'network-interface')
        );
    }

    /**
     * Test data transformation
     */
    public function testTransformsStorageSize()
    {
        $data = ['provisioned' => '1099511627776'];
        $mapping = ['provisioned' => 'storage_size'];

        $result = PureStorageDataProcessor::transform($data, $mapping);

        $this->assertIsInt($result['storage_size']);
        $this->assertEquals(1099511627776, $result['storage_size']);
    }

    public function testConvertsBooleanToInt()
    {
        $data = ['enabled' => true];
        $mapping = ['enabled' => 'ifAdminStatus'];

        $result = PureStorageDataProcessor::transform($data, $mapping);

        $this->assertEquals(1, $result['ifAdminStatus']);
    }

    /**
     * Test validation
     */
    public function testValidatesStorageDescr()
    {
        $result = PureStorageDataProcessor::validate(
            'name',
            'my-volume',
            'storage',
            'storage_descr'
        );

        $this->assertTrue($result['valid']);
    }

    public function testRejectsIncompatibleTypes()
    {
        $result = PureStorageDataProcessor::validate(
            'name',
            ['array', 'value'],
            'storage',
            'storage_descr'
        );

        $this->assertFalse($result['valid']);
    }

    /**
     * Test complex metrics
     */
    public function testIdentifiesComplexMetrics()
    {
        $this->assertTrue(
            PureStorageDataProcessor::isComplexMetric('space_data_reduction')
        );

        $this->assertTrue(
            PureStorageDataProcessor::isComplexMetric('data_reduction')
        );

        $this->assertFalse(
            PureStorageDataProcessor::isComplexMetric('name')
        );
    }
}