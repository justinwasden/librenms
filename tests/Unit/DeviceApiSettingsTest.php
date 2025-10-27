<?php

namespace Tests\Unit;

use App\Models\Device;
use LibreNMS\Util\DeviceApiSettings;
use Tests\TestCase;

class DeviceApiSettingsTest extends TestCase
{
    public function testHttpOptionsReadsHeadersTlsTimeoutProxy()
    {
        $device = Device::factory()->create();
        $device->setAttrib('rest_base_url', 'https://example/api');
        $device->setAttrib('rest_verify_tls', 1);
        $device->setAttrib('rest_timeout_ms', 7000);
        $device->setAttrib('rest_proxy', 'http://proxy.example:3128');
        $device->setAttrib('rest_headers', json_encode(['X-Test' => 'abc']));

        $opts = DeviceApiSettings::httpOptions($device);
        $this->assertEquals('https://example/api', $opts['base_url']);
        $this->assertTrue($opts['verify_tls']);
        $this->assertEquals(7000, $opts['timeout_ms']);
        $this->assertEquals('http://proxy.example:3128', $opts['proxy']);
        $this->assertEquals(['X-Test' => 'abc'], $opts['headers']);
    }
}