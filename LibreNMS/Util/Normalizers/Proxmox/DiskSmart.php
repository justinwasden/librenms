<?php

namespace LibreNMS\Util\Normalizers\Proxmox;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * Proxmox - DiskSmart Normalizer
 *
 * Capability: unknown
 * Vendor: proxmox
 */
class DiskSmart extends BaseNormalizer
{
    protected string $capability = 'unknown';
    protected string $vendor = 'proxmox';

    protected function doNormalize(Device $device, array $payload): array
    {
$sensors = [];
        $data = $payload['data'] ?? $payload;

        if (empty($data) || !is_array($data)) {
            return ['sensors' => $sensors];
        }

        // Extract disk device path from parent item if available
        $devpath = $data['_parent_item']['devpath'] ?? $data['disk'] ?? 'unknown';
        $baseIndex = $this->stableIndexFromName($devpath);

        // SMART attributes can be in different formats depending on disk type
        $attributes = $data['attributes'] ?? [];

        // Temperature sensor
        if (isset($data['temperature'])) {
            $sensors[] = [
                'sensor_class' => 'temperature',
                'sensor_type' => 'proxmox-smart',
                'sensor_descr' => $devpath . ' Temperature',
                'sensor_index' => 'smart_temp_' . $baseIndex,
                'sensor_current' => (float) $data['temperature'],
                'sensor_limit' => 60,
                'sensor_limit_low' => 0,
            ];
        }

        // Power-on hours sensor
        if (isset($data['power_on_hours'])) {
            $sensors[] = [
                'sensor_class' => 'count',
                'sensor_type' => 'proxmox-smart',
                'sensor_descr' => $devpath . ' Power-On Hours',
                'sensor_index' => 'smart_poh_' . $baseIndex,
                'sensor_current' => (int) $data['power_on_hours'],
                'sensor_limit' => null,
                'sensor_limit_low' => 0,
            ];
        }

        // Health status (if available)
        if (isset($data['health'])) {
            $health = strtolower($data['health']);
            $healthValue = match ($health) {
                'passed', 'ok', 'healthy' => 2,
                'warning', 'degraded' => 1,
                'failed', 'critical' => 0,
                default => 3,
            };

            $sensors[] = [
                'sensor_class' => 'state',
                'sensor_type' => 'proxmox-smart',
                'sensor_descr' => $devpath . ' Health',
                'sensor_index' => 'smart_health_' . $baseIndex,
                'sensor_current' => $healthValue,
                'sensor_limit' => null,
                'sensor_limit_low' => null,
                'states' => [
                    ['value' => 0, 'generic' => 2, 'graph' => 0, 'descr' => 'failed'],
                    ['value' => 1, 'generic' => 1, 'graph' => 0, 'descr' => 'warning'],
                    ['value' => 2, 'generic' => 0, 'graph' => 1, 'descr' => 'healthy'],
                    ['value' => 3, 'generic' => 3, 'graph' => 0, 'descr' => 'unknown'],
                ],
            ];
        }

        // Wear level percentage (for SSDs)
        if (isset($data['wearout']) || isset($data['wear_leveling_count'])) {
            $wearout = $data['wearout'] ?? $data['wear_leveling_count'] ?? 0;
            $sensors[] = [
                'sensor_class' => 'percent',
                'sensor_type' => 'proxmox-smart',
                'sensor_descr' => $devpath . ' Wear Level',
                'sensor_index' => 'smart_wear_' . $baseIndex,
                'sensor_current' => (float) $wearout,
                'sensor_limit' => 90,
                'sensor_limit_low' => 0,
            ];
        }

        // Process individual SMART attributes if available
        if (is_array($attributes)) {
            foreach ($attributes as $attr) {
                $id = $attr['id'] ?? null;
                $name = $attr['name'] ?? null;
                $value = $attr['value'] ?? $attr['raw'] ?? null;

                if ($id === null || $value === null) {
                    continue;
                }

                // Map common SMART attributes to sensors
                $attrIndex = 'smart_attr_' . $id . '_' . $baseIndex;

                switch ($id) {
                    case 5: // Reallocated Sectors Count
                        $sensors[] = [
                            'sensor_class' => 'count',
                            'sensor_type' => 'proxmox-smart',
                            'sensor_descr' => $devpath . ' Reallocated Sectors',
                            'sensor_index' => $attrIndex,
                            'sensor_current' => (int) $value,
                            'sensor_limit' => 10,
                            'sensor_limit_low' => 0,
                        ];
                        break;

                    case 9: // Power-On Hours
                        if (!isset($data['power_on_hours'])) {
                            $sensors[] = [
                                'sensor_class' => 'count',
                                'sensor_type' => 'proxmox-smart',
                                'sensor_descr' => $devpath . ' Power-On Hours',
                                'sensor_index' => $attrIndex,
                                'sensor_current' => (int) $value,
                                'sensor_limit' => null,
                                'sensor_limit_low' => 0,
                            ];
                        }
                        break;

                    case 194: // Temperature
                        if (!isset($data['temperature'])) {
                            $sensors[] = [
                                'sensor_class' => 'temperature',
                                'sensor_type' => 'proxmox-smart',
                                'sensor_descr' => $devpath . ' Temperature',
                                'sensor_index' => $attrIndex,
                                'sensor_current' => (float) $value,
                                'sensor_limit' => 60,
                                'sensor_limit_low' => 0,
                            ];
                        }
                        break;
                }
            }
        }

        return ['sensors' => $sensors];
    }
}
