<?php
namespace App\RestApi\Normalize;

use App\RestApi\Utils\JsonFlattener;

class NormalizeHost
{
    public static function normalize(array $items, string $vendor = null): array
    {
        $normalized = [];
        foreach ($items as $item) {
            $flattened = JsonFlattener::flatten($item, 'host');
            $normalized[] = $flattened;
        }
        return $normalized;
    }
}
