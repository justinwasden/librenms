<?php

namespace LibreNMS\Util\Normalizers\Cisco;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * Cisco - DeviceHostname Normalizer
 *
 * Capability: unknown
 * Vendor: ftd
 */
class DeviceHostname extends BaseNormalizer
{
    protected string $capability = 'unknown';
    protected string $vendor = 'ftd';

    protected function doNormalize(Device $device, array $payload): array
    {
$deviceInfo = [];

        // Extract hostname from FTD device settings
        if (!empty($payload['hostname'])) {
            $deviceInfo['sysName'] = $payload['hostname'];
        }

        if (!empty($payload['domainName'])) {
            $deviceInfo['sysDescr'] = 'Cisco FTD - ' . $payload['domainName'];
        }

        if (!empty($deviceInfo)) {
            return [$deviceInfo];
        }

        return [];
    }
}
