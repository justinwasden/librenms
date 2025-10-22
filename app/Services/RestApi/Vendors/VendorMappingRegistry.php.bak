<?php

namespace App\Services\RestApi\Vendors;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class VendorMappingRegistry
{
    private static array $registry = [];
    private static bool $initialized = false;

    public static function initialize(): void
    {
        if (self::$initialized) {
            return;
        }

        $vendorPath = app_path('Services/RestApi/Vendors');

        if (!File::exists($vendorPath)) {
            self::$initialized = true;
            return;
        }

        $files = File::files($vendorPath);

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php' || $file->getFilename() === 'VendorMappingInterface.php') {
                continue;
            }

            $className = 'App\\Services\\RestApi\\Vendors\\' . $file->getFilenameWithoutExtension();

            if (class_exists($className) && is_subclass_of($className, VendorMappingInterface::class)) {
                try {
                    $instance = new $className();
                    self::$registry[$className::getName()] = $className;
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::warning("Failed to register vendor mapping: {$className}", [
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        self::$initialized = true;
    }

    public static function getAll(): array
    {
        self::initialize();
        return self::$registry;
    }

    public static function get(string $name): ?string
    {
        self::initialize();
        return self::$registry[$name] ?? null;
    }

    public static function getOptions(): array
    {
        self::initialize();
        $options = [];

        foreach (self::$registry as $name => $className) {
            $options[$name] = $className::getDescription();
        }

        return $options;
    }

    public static function getForOs(string $os): ?string
    {
        self::initialize();

        foreach (self::$registry as $className) {
            if (in_array($os, $className::getSupportedOs())) {
                return $className;
            }
        }

        return null;
    }

    public static function getCustomMappings(): array
    {
        $customPath = storage_path('app/rest-api-mappings');

        if (!File::exists($customPath)) {
            return [];
        }

        $mappings = [];
        $files = File::files($customPath);

        foreach ($files as $file) {
            if ($file->getExtension() === 'json') {
                $name = $file->getFilenameWithoutExtension();
                $mappings[$name] = 'custom-' . $name;
            }
        }

        return $mappings;
    }

    public static function loadCustomMapping(string $name): ?array
    {
        $path = storage_path('app/rest-api-mappings/' . $name . '.json');

        if (!File::exists($path)) {
            return null;
        }

        $content = File::get($path);
        return json_decode($content, true);
    }
}
