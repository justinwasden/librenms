<?php
namespace App\RestApi\Utils;

class JsonFlattener
{
    /**
     * Flatten a nested JSON array
     * @param array $data
     * @param string $prefix
     * @return array
     */
    public static function flatten(array $data, string $prefix = ''): array
    {
        $result = [];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveArrayIterator($data));
        foreach ($iterator as $value) {
            $keys = [];
            foreach (range(0, $iterator->getDepth()) as $depth) {
                $keys[] = $iterator->getSubIterator($depth)->key();
            }
            $key = $prefix . implode('_', $keys);
            $result[$key] = $value;
        }

        return $result;
    }
}
