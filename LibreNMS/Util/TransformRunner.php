<?php

namespace LibreNMS\Util;

class TransformRunner
{
    /**
     * Run a transform and extract data for the given capability.
     * - If transform returns a keyed array, return the section matching $capability.
     * - If it returns a flat array, return it directly.
     */
    public static function run(?string $transform, $payload, string $capability): array
    {
        $cls = \LibreNMS\Modules\Support\RestNormalizers::class;

        if (!$transform || !method_exists($cls, $transform)) {
            return is_array($payload) ? $payload : [];
        }

        $result = $cls::$transform($payload);

        if (is_array($result) && array_key_exists($capability, $result)) {
            return $result[$capability] ?? [];
        }

        return is_array($result) ? $result : [];
    }
}