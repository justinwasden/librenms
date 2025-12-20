<?php

namespace LibreNMS\Util\Normalizers\Juniper;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * Juniper - DeviceInfo Normalizer
 *
 * Capability: device_info
 * Vendor: junos
 */
class DeviceInfo extends BaseNormalizer
{
    protected string $capability = 'device_info';
    protected string $vendor = 'junos';

    protected function doNormalize(Device $device, array $payload): array
    {
$deviceInfo = [];
        $sysInfo = $payload['system-information'] ?? $payload;

        if (empty($sysInfo)) {
            return $deviceInfo;
        }

        // Hardware/Model
        if (isset($sysInfo['hardware-model'])) {
            $deviceInfo['hardware'] = $sysInfo['hardware-model'];
        } elseif (isset($sysInfo['model'])) {
            $deviceInfo['hardware'] = $sysInfo['model'];
        }

        // Serial number
        if (isset($sysInfo['hardware-serial-number'])) {
            $deviceInfo['serial'] = $sysInfo['hardware-serial-number'];
        } elseif (isset($sysInfo['serial-number'])) {
            $deviceInfo['serial'] = $sysInfo['serial-number'];
        }

        // System Object ID (Juniper OID)
        // Juniper enterprise OID: .1.3.6.1.4.1.2636
        $deviceInfo['sysObjectID'] = '.1.3.6.1.4.1.2636';

        // System Contact
        if (isset($sysInfo['system-contact'])) {
            $deviceInfo['sysContact'] = $sysInfo['system-contact'];
        }

        // Uptime (Junos provides uptime in seconds)
        if (isset($sysInfo['system-uptime-information']['system-booted-time'])) {
            $bootTime = $sysInfo['system-uptime-information']['system-booted-time']['time-length']['seconds'] ?? 0;
            $deviceInfo['uptime'] = (int) $bootTime;
        } elseif (isset($sysInfo['uptime'])) {
            $deviceInfo['uptime'] = (int) $sysInfo['uptime'];
        }

        // Location
        if (isset($sysInfo['system-location'])) {
            $deviceInfo['location'] = $sysInfo['system-location'];
        }

        return $deviceInfo;
    }
}
