<?php

namespace LibreNMS\Util\Normalizers\Pure;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * Pure - DeviceInfo Normalizer
 *
 * Capability: device_info
 * Vendor: pure
 */
class DeviceInfo extends BaseNormalizer
{
    protected string $capability = 'device_info';
    protected string $vendor = 'pure';

    protected function doNormalize(Device $device, array $payload): array
    {
$deviceInfo = [];
        $array = $payload['items'][0] ?? $payload;

        if (empty($array)) {
            return $deviceInfo;
        }

        // Hardware/Model
        if (isset($array['model'])) {
            $deviceInfo['hardware'] = $array['model'];
        }

        // Serial number
        if (isset($array['id'])) {
            $deviceInfo['serial'] = $array['id'];
        }

        // System Object ID (Pure Storage OID)
        // Pure Storage enterprise OID: .1.3.6.1.4.1.40482
        $deviceInfo['sysObjectID'] = '.1.3.6.1.4.1.40482';

        // Version (software version)
        if (isset($array['version'])) {
            // Version is already collected via OS discovery, but we could set it here if needed
        }

        // Uptime (if available in API response)
        // Pure Storage API doesn't directly provide uptime, but we can calculate from timestamps if available
        if (isset($array['uptime'])) {
            $deviceInfo['uptime'] = (int) $array['uptime'];
        }

        return $deviceInfo;
    }
}
