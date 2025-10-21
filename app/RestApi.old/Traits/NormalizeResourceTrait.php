<?php
namespace App\RestApi\Traits;

trait NormalizeResourceTrait
{
    public function normalizeByResourceType(string $type, string $name, $value, array $labels = []): array
    {
        $metricType = match(strtolower($type)) {
            'processor', 'cpu' => 'processor',
            'memory', 'mempool' => 'mempool',
            'port', 'interface' => 'port',
            'storage', 'volume' => 'storage',
            'sensor', 'temperature', 'fan', 'power' => 'sensor',
            default => 'custom'
        };

        return [
            'type' => $metricType,
            'metric_name' => $name,
            'value' => $value,
            'descr' => $labels['descr'] ?? $name,
            'labels' => $labels
        ];
    }
}
