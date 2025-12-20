<?php

namespace LibreNMS\Util\Normalizers\HPE;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * HPE - DeviceInfo Normalizer
 *
 * Capability: device_info
 * Vendor: nimble
 */
class DeviceInfo extends BaseNormalizer
{
    protected string $capability = 'device_info';
    protected string $vendor = 'nimble';

    protected function doNormalize(Device $device, array $payload): array
    {
$deviceInfo = [];
        $data = $payload['data'] ?? $payload['items'] ?? $payload;

        // Get first array if it's a list
        $array = is_array($data) && isset($data[0]) ? $data[0] : $data;

        if (empty($array)) {
            return $deviceInfo;
        }

        // Hardware/Model
        if (isset($array['model'])) {
            $deviceInfo['hardware'] = $array['model'];
        }

        // Serial number
        if (isset($array['serial'])) {
            $deviceInfo['serial'] = $array['serial'];
        }

        // System Object ID (HPE Nimble OID - part of HPE)
        $deviceInfo['sysObjectID'] = '.1.3.6.1.4.1.11';

        // System Contact (if available)
        if (isset($array['contact'])) {
            $deviceInfo['sysContact'] = $array['contact'];
        }

        // Uptime (if available)
        if (isset($array['uptime'])) {
            $deviceInfo['uptime'] = (int) $array['uptime'];
        }

        // Location (if available)
        if (isset($array['location'])) {
            $deviceInfo['location'] = $array['location'];
        }

        return $deviceInfo;
    }
}
