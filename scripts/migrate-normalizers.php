#!/usr/bin/env php
<?php
/**
 * Automated Normalizer Migration Script
 *
 * Extracts methods from RestNormalizers.php and creates individual normalizer classes
 *
 * Usage:
 *   php scripts/migrate-normalizers.php                  # Interactive mode
 *   php scripts/migrate-normalizers.php --vendor=Pure    # Migrate specific vendor
 *   php scripts/migrate-normalizers.php --all            # Migrate all remaining
 *   php scripts/migrate-normalizers.php --list           # List unmigrated methods
 */

require __DIR__ . '/../vendor/autoload.php';

class NormalizerMigrator
{
    private string $sourceFile = '/opt/librenms/LibreNMS/Modules/Support/RestNormalizers.php';
    private string $targetDir = '/opt/librenms/LibreNMS/Util/Normalizers';
    private string $factoryFile = '/opt/librenms/LibreNMS/Util/Normalizers/NormalizerFactory.php';

    private array $vendorMap = [
        'Pure' => 'Pure',
        'Proxmox' => 'Proxmox',
        'Fortigate' => 'Fortinet',
        'Fortgate' => 'Fortinet',
        'Velocloud' => 'VMware',
        'Vcenter' => 'VMware',
        'Esxi' => 'VMware',
        'Ontap' => 'NetApp',
        'Netapp' => 'NetApp',
        'Unity' => 'NetApp',
        'Isilon' => 'NetApp',
        'Generic' => 'Generic',
        'Ftd' => 'Cisco',
        'Junos' => 'Juniper',
        'Dell' => 'Dell',
        'Hpe' => 'HPE',
        'Nimble' => 'HPE',
        'Nutanix' => 'Nutanix',
        'Ise' => 'Cisco',
        'Pan' => 'PaloAlto',
        'Nx' => 'Cisco',
        'Iosxr' => 'Cisco',
        'Cucm' => 'Cisco',
        'Calix' => 'Calix',
        'Ndfc' => 'Cisco',
        'Arista' => 'Arista',
        'Extreme' => 'Extreme',
        'Brocade' => 'Brocade',
        'Sonic' => 'SonicWall',
        'Checkpoint' => 'CheckPoint',
    ];

    private array $capabilityMap = [
        'Sensors' => 'sensors',
        'Interfaces' => 'ports',
        'Ports' => 'ports',
        'Network' => 'ports',
        'Inventory' => 'inventory',
        'Hardware' => 'inventory',
        'System' => 'device_info',
        'Status' => 'sensors',
        'Storage' => 'storage',
        'Processors' => 'processors',
        'Mempools' => 'mempools',
        'Ipv4' => 'ipv4',
        'Vlans' => 'vlans',
        'Routes' => 'routes',
        'DeviceInfo' => 'device_info',
        'Statistics' => 'statistics',
        'Performance' => 'statistics',
        'Alerts' => 'alerts',
        'Optics' => 'transceivers',
        'Transceivers' => 'transceivers',
    ];

    public function run(array $args): void
    {
        if (in_array('--list', $args)) {
            $this->listUnmigratedMethods();
            return;
        }

        if (in_array('--all', $args)) {
            $this->migrateAll();
            return;
        }

        $vendor = null;
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--vendor=')) {
                $vendor = substr($arg, 9);
                break;
            }
        }

        if ($vendor) {
            $this->migrateVendor($vendor);
        } else {
            $this->interactive();
        }
    }

    private function listUnmigratedMethods(): void
    {
        $methods = $this->extractMethods();
        $migrated = $this->getMigratedMethods();

        echo "Unmigrated Methods:\n";
        echo str_repeat('=', 80) . "\n\n";

        $byVendor = [];
        foreach ($methods as $method) {
            if (in_array($method['name'], $migrated)) {
                continue;
            }

            $vendor = $method['vendor'];
            if (!isset($byVendor[$vendor])) {
                $byVendor[$vendor] = [];
            }
            $byVendor[$vendor][] = $method['name'];
        }

        ksort($byVendor);

        foreach ($byVendor as $vendor => $methodList) {
            echo "$vendor (" . count($methodList) . " methods):\n";
            foreach ($methodList as $methodName) {
                echo "  - $methodName\n";
            }
            echo "\n";
        }

        $total = array_sum(array_map('count', $byVendor));
        echo "Total unmigrated: $total methods\n";
    }

    private function extractMethods(): array
    {
        $content = file_get_contents($this->sourceFile);
        $methods = [];

        // Match all normalize methods
        preg_match_all(
            '/public static function (normalize\w+)\s*\([^)]+\):\s*array/s',
            $content,
            $matches,
            PREG_OFFSET_CAPTURE
        );

        foreach ($matches[1] as $match) {
            $methodName = $match[0];

            // Extract vendor and capability from method name
            // normalize{Vendor}{Capability}
            if (preg_match('/^normalize([A-Z][a-z]+)(.+)$/', $methodName, $parts)) {
                $vendor = $parts[1];
                $capability = $parts[2];

                $methods[] = [
                    'name' => $methodName,
                    'vendor' => $vendor,
                    'capability' => $capability,
                    'directory' => $this->vendorMap[$vendor] ?? $vendor,
                ];
            }
        }

        return $methods;
    }

    private function getMigratedMethods(): array
    {
        // Parse NormalizerFactory to get already migrated methods
        if (!file_exists($this->factoryFile)) {
            return [];
        }

        $content = file_get_contents($this->factoryFile);
        preg_match_all("/'(normalize\w+)'/", $content, $matches);

        return $matches[1] ?? [];
    }

    private function migrateAll(): void
    {
        $methods = $this->extractMethods();
        $migrated = $this->getMigratedMethods();

        $toMigrate = array_filter($methods, fn($m) => !in_array($m['name'], $migrated));

        echo "Migrating " . count($toMigrate) . " methods...\n\n";

        foreach ($toMigrate as $method) {
            $this->migrateMethod($method);
        }

        echo "\n✅ Migration complete!\n";
        echo "Migrated: " . count($toMigrate) . " methods\n";
    }

    private function migrateVendor(string $vendor): void
    {
        $methods = $this->extractMethods();
        $migrated = $this->getMigratedMethods();

        $toMigrate = array_filter($methods, function($m) use ($vendor, $migrated) {
            return $m['vendor'] === $vendor && !in_array($m['name'], $migrated);
        });

        if (empty($toMigrate)) {
            echo "No unmigrated methods found for vendor: $vendor\n";
            return;
        }

        echo "Migrating " . count($toMigrate) . " $vendor methods...\n\n";

        foreach ($toMigrate as $method) {
            $this->migrateMethod($method);
        }

        echo "\n✅ Migration complete for $vendor!\n";
    }

    private function migrateMethod(array $method): void
    {
        echo "Migrating: {$method['name']}...\n";

        $className = $method['capability'];
        $directory = $method['directory'];
        $vendor = strtolower($method['vendor']);

        // Create vendor directory if it doesn't exist
        $vendorDir = "$this->targetDir/$directory";
        if (!is_dir($vendorDir)) {
            mkdir($vendorDir, 0755, true);
        }

        // Extract method body from source file
        $sourceContent = file_get_contents($this->sourceFile);
        $methodBody = $this->extractMethodBody($sourceContent, $method['name']);

        // Determine capability
        $capability = $this->guessCapability($className);

        // Generate class file
        $classContent = $this->generateClassFile($directory, $className, $vendor, $capability, $methodBody);

        $targetFile = "$vendorDir/$className.php";
        file_put_contents($targetFile, $classContent);

        echo "  ✓ Created: $targetFile\n";

        // Update factory mapping
        $this->updateFactory($method['name'], "$directory\\$className");
    }

    private function extractMethodBody(string $content, string $methodName): string
    {
        // Find method start
        $pattern = "/public static function $methodName\s*\([^)]+\):\s*array\s*\{/";
        if (!preg_match($pattern, $content, $match, PREG_OFFSET_CAPTURE)) {
            return "// TODO: Could not extract method body\nreturn [];";
        }

        $start = $match[0][1] + strlen($match[0][0]);

        // Find method end by matching braces
        $depth = 1;
        $pos = $start;
        $len = strlen($content);

        while ($pos < $len && $depth > 0) {
            if ($content[$pos] === '{') {
                $depth++;
            } elseif ($content[$pos] === '}') {
                $depth--;
            }
            $pos++;
        }

        $body = substr($content, $start, $pos - $start - 1);

        // Convert self:: to $this->
        $body = str_replace('self::', '$this->', $body);

        // Clean up body indentation
        $lines = explode("\n", $body);
        $lines = array_map(fn($line) => preg_replace('/^        /', '        ', $line), $lines);

        return trim(implode("\n", $lines));
    }

    private function guessCapability(string $className): string
    {
        foreach ($this->capabilityMap as $keyword => $capability) {
            if (str_contains($className, $keyword)) {
                return $capability;
            }
        }

        return 'unknown';
    }

    private function generateClassFile(string $namespace, string $className, string $vendor, string $capability, string $body): string
    {
        return <<<PHP
<?php

namespace LibreNMS\\Util\\Normalizers\\$namespace;

use App\\Models\\Device;
use LibreNMS\\Util\\Normalizers\\BaseNormalizer;

/**
 * $namespace - $className Normalizer
 *
 * Capability: $capability
 * Vendor: $vendor
 */
class $className extends BaseNormalizer
{
    protected string \$capability = '$capability';
    protected string \$vendor = '$vendor';

    protected function doNormalize(Device \$device, array \$payload): array
    {
$body
    }
}

PHP;
    }

    private function updateFactory(string $methodName, string $className): void
    {
        $content = file_get_contents($this->factoryFile);

        // Find the normalizerMap array
        $pattern = "/(private static array \\\$normalizerMap = \[)(.*?)(\];)/s";

        if (preg_match($pattern, $content, $matches)) {
            $mapContent = $matches[2];

            // Add new mapping
            $newEntry = "\n        '$methodName' => $className::class,";
            $mapContent .= $newEntry;

            $newContent = $matches[1] . $mapContent . "\n    " . $matches[3];
            $content = preg_replace($pattern, $newContent, $content);

            file_put_contents($this->factoryFile, $content);
        }
    }

    private function interactive(): void
    {
        echo "Normalizer Migration Tool\n";
        echo str_repeat('=', 80) . "\n\n";

        $methods = $this->extractMethods();
        $migrated = $this->getMigratedMethods();

        $total = count($methods);
        $done = count($migrated);
        $remaining = $total - $done;

        echo "Progress: $done / $total migrated ($remaining remaining)\n\n";

        echo "Options:\n";
        echo "  1) List unmigrated methods\n";
        echo "  2) Migrate specific vendor\n";
        echo "  3) Migrate all remaining\n";
        echo "  4) Exit\n\n";

        echo "Choose option: ";
        $choice = trim(fgets(STDIN));

        switch ($choice) {
            case '1':
                $this->listUnmigratedMethods();
                break;
            case '2':
                echo "Enter vendor name: ";
                $vendor = trim(fgets(STDIN));
                $this->migrateVendor($vendor);
                break;
            case '3':
                $this->migrateAll();
                break;
            default:
                echo "Exiting.\n";
                break;
        }
    }
}

// Run migration
$migrator = new NormalizerMigrator();
$migrator->run(array_slice($argv, 1));
