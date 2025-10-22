<?php

namespace App\Services\RestApi;

use Flow\JSONPath\JSONPath;
use Illuminate\Support\Facades\Log;
use Exception;

class FieldExtractor
{
    public function extract(array $data, string $jsonPath): mixed
    {
        try {
            $path = new JSONPath($data);
            $result = $path->find($jsonPath);

            if ($result->isEmpty()) {
                return null;
            }

            $values = [];
            foreach ($result as $item) {
                $values[] = $item->getValue();
            }

            return count($values) === 1 ? $values[0] : $values;
        } catch (Exception $e) {
            Log::warning("JSONPath extraction failed: {$jsonPath}", [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function extractAllFields(array $data, array $mappings): array
    {
        $extracted = [];

        foreach ($mappings as $targetField => $sourcePath) {
            $value = $this->extract($data, $sourcePath);
            if ($value !== null) {
                $extracted[$targetField] = $value;
            }
        }

        return $extracted;
    }

    public function transform(mixed $value, string $dataType): mixed
    {
        return match($dataType) {
            'integer' => (int)$value,
            'float' => (float)$value,
            'boolean' => (bool)$value,
            'string' => (string)$value,
            'array' => (array)$value,
            default => $value,
        };
    }
}
