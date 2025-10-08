<?php
namespace App\RestApi\Normalize;

use App\RestApi\Utils\JsonFlattener;

class NormalizeStorage
{
    public static function normalize(array $items, string $vendor = null): array
    {
        $normalized = [];
        foreach ($items as $item) {
            switch ($vendor) {
                case 'PureStorage':
                    $normalized[] = [
                        'name' => $item['name'] ?? 'unknown',
                        'used' => $item['used_bytes'] ?? 0,
                        'total' => $item['total_bytes'] ?? 0,
                        'utilization' => $item['utilization'] ?? 0,
                    ];
                    break;

                case 'Fortinet':
                    $normalized[] = [
                        'name' => $item['disk_name'] ?? 'unknown',
                        'used' => $item['used'] ?? 0,
                        'total' => $item['total'] ?? 0,
                    ];
                    break;

                default:
                    $flattened = JsonFlattener::flatten($item, 'storage');
                    $normalized[] = $flattened;
            }
        }

        return $normalized;
    }
}
