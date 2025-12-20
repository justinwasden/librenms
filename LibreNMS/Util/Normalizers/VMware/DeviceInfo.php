<?php

namespace LibreNMS\Util\Normalizers\VMware;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * VMware - DeviceInfo Normalizer
 *
 * Capability: device_info
 * Vendor: vcenter
 */
class DeviceInfo extends BaseNormalizer
{
    protected string $capability = 'device_info';
    protected string $vendor = 'vcenter';

    protected function doNormalize(Device $device, array $payload): array
    {
$deviceInfo = [];

        if (!empty($payload['version'])) {
            $deviceInfo['version'] = $payload['version'];
        }

        if (!empty($payload['build'])) {
            $deviceInfo['features'] = $payload['product'] . ' build ' . $payload['build'];
        }

        if (!empty($payload['hostname'])) {
            $deviceInfo['sysName'] = $payload['hostname'];
        }

        if (!empty($deviceInfo)) {
            return [$deviceInfo];
        }

        return [];
    }
}
