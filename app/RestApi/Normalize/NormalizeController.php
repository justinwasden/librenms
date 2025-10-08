<?php
namespace App\RestApi\Normalize;

use App\RestApi\Utils\JsonFlattener;

class NormalizeController
{
    public static function normalize(array $items, string $vendor = null): array
    {
        $normalized = [];
        foreach ($items as $item) {
            $flattened = JsonFlattener::flatten($item, 'controller');
            $normalized[] = $flattened;
        }
        return $normalized;
    }
}
