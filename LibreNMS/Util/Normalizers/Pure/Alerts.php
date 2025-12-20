<?php

namespace LibreNMS\Util\Normalizers\Pure;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * Pure - Alerts Normalizer
 *
 * Capability: alerts
 * Vendor: pure
 */
class Alerts extends BaseNormalizer
{
    protected string $capability = 'alerts';
    protected string $vendor = 'pure';

    protected function doNormalize(Device $device, array $payload): array
    {
if (!is_array($payload)) {
            return [];
        }

        $sensors = [];

        if (!isset($payload['items']) || !is_array($payload['items'])) {
            return $sensors;
        }

        // Count alerts by severity
        $critical = 0;
        $warning = 0;
        $info = 0;

        foreach ($payload['items'] as $alert) {
            $severity = strtolower($alert['severity'] ?? 'info');
            if ($severity === 'critical') {
                $critical++;
            } elseif ($severity === 'warning') {
                $warning++;
            } else {
                $info++;
            }
        }

        $sensors[] = [
            'sensor_class' => 'count',
            'sensor_type' => 'purestorage',
            'sensor_descr' => 'Critical Alerts',
            'sensor_index' => 'alerts_critical',
            'sensor_current' => $critical,
            'sensor_limit' => 10,
            'sensor_limit_low' => null,
        ];

        $sensors[] = [
            'sensor_class' => 'count',
            'sensor_type' => 'purestorage',
            'sensor_descr' => 'Warning Alerts',
            'sensor_index' => 'alerts_warning',
            'sensor_current' => $warning,
            'sensor_limit' => 20,
            'sensor_limit_low' => null,
        ];

        return $sensors;
    }
}
