<?php

namespace LibreNMS\Util\Normalizers\Dell;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * Dell - DeviceInfo Normalizer
 *
 * Capability: device_info
 * Vendor: dell
 */
class DeviceInfo extends BaseNormalizer
{
    protected string $capability = 'device_info';
    protected string $vendor = 'dell';

    protected function doNormalize(Device $device, array $payload): array
    {
$deviceInfo = [];
        $system = $payload['SystemInformation'] ?? $payload['system'] ?? $payload;

        if (empty($system)) {
            return $deviceInfo;
        }

        // Hardware/Model
        if (isset($system['Model'])) {
            $deviceInfo['hardware'] = $system['Model'];
        } elseif (isset($system['model'])) {
            $deviceInfo['hardware'] = $system['model'];
        }

        // Serial number
        if (isset($system['ServiceTag'])) {
            $deviceInfo['serial'] = $system['ServiceTag'];
        } elseif (isset($system['SerialNumber'])) {
            $deviceInfo['serial'] = $system['SerialNumber'];
        } elseif (isset($system['serial'])) {
            $deviceInfo['serial'] = $system['serial'];
        }

        // System Object ID (Dell OID)
        // Dell enterprise OID: .1.3.6.1.4.1.674
        $deviceInfo['sysObjectID'] = '.1.3.6.1.4.1.674';

        // System Contact (if available)
        if (isset($system['Contact'])) {
            $deviceInfo['sysContact'] = $system['Contact'];
        } elseif (isset($system['contact'])) {
            $deviceInfo['sysContact'] = $system['contact'];
        }

        // Uptime
        if (isset($system['Uptime'])) {
            // Dell may provide uptime in various formats
            $uptime = $system['Uptime'];
            if (is_numeric($uptime)) {
                $deviceInfo['uptime'] = (int) $uptime;
            }
        }

        // Location
        if (isset($system['Location'])) {
            $deviceInfo['location'] = $system['Location'];
        } elseif (isset($system['location'])) {
            $deviceInfo['location'] = $system['location'];
        }

        return $deviceInfo;
    }
}
