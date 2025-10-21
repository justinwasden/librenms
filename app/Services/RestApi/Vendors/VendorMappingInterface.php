<?php

namespace App\Services\RestApi\Vendors;

use App\Models\Device;
use Illuminate\Support\Facades\Log;

abstract class VendorMappingInterface
{
    abstract public static function getName(): string;
    
    abstract public static function getDescription(): string;
    
    abstract public static function getSupportedOs(): array;
    
    abstract public function mapDevice(array $data, Device $device): array;
    
    abstract public function mapPorts(array $data, Device $device): array;
    
    abstract public function mapStorage(array $data, Device $device): array;
    
    abstract public function mapSensors(array $data, Device $device): array;
    
    abstract public function mapCustom(array $data, Device $device): array;

    protected function extractValue(array $data, string $path): mixed
    {
        $keys = explode('.', $path);
        $value = $data;

        foreach ($keys as $key) {
            if (is_array($value) && isset($value[$key])) {
                $value = $value[$key];
            } else {
                return null;
            }
        }

        return $value;
    }

    protected function setNestedValue(array &$array, string $path, mixed $value): void
    {
        $keys = explode('.', $path);
        $current = &$array;

        foreach ($keys as $key) {
            if (!isset($current[$key])) {
                $current[$key] = [];
            }
            $current = &$current[$key];
        }

        $current = $value;
    }

    protected function convertBytes(mixed $value, string $unit = 'B'): int
    {
        if (!is_numeric($value)) {
            return 0;
        }

        return match(strtoupper($unit)) {
            'KB' => (int)$value * 1024,
            'MB' => (int)$value * 1024 * 1024,
            'GB' => (int)$value * 1024 * 1024 * 1024,
            'TB' => (int)$value * 1024 * 1024 * 1024 * 1024,
            default => (int)$value,
        };
    }

    protected function convertBitrate(mixed $value, string $unit = 'bps'): int
    {
        if (!is_numeric($value)) {
            return 0;
        }

        return match(strtoupper($unit)) {
            'KBPS' => (int)$value * 1000,
            'MBPS' => (int)$value * 1000 * 1000,
            'GBPS' => (int)$value * 1000 * 1000 * 1000,
            'MBD' => (int)$value * 1000 * 1000,
            default => (int)$value,
        };
    }

    protected function normalizeStatus(mixed $value): string
    {
        $status = strtolower(trim((string)$value));

        return match($status) {
            'ok', 'ready', 'healthy', 'online', 'up', 'active' => 'up',
            'down', 'offline', 'inactive', 'disabled' => 'down',
            'warning', 'degraded', 'troubled' => 'warning',
            'fault', 'error', 'critical', 'failed' => 'fault',
            default => $status,
        };
    }
}
