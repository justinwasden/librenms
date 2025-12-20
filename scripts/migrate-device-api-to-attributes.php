#!/usr/bin/env php
<?php

/**
 * Migrate Device API Configuration from Tables to Device Attributes
 *
 * This script migrates API configuration from the device_api_* tables to device attributes.
 * It supports dry-run mode, export/import for backup/rollback, and validation.
 *
 * Usage:
 *   php migrate-device-api-to-attributes.php --dry-run          # Preview changes (no modifications)
 *   php migrate-device-api-to-attributes.php --export           # Export current config to JSON
 *   php migrate-device-api-to-attributes.php --migrate          # Perform migration
 *   php migrate-device-api-to-attributes.php --validate         # Validate migration results
 *   php migrate-device-api-to-attributes.php --import <file>    # Import from JSON backup
 */

use App\Models\Device;
use App\Models\DeviceApiConfig;
use Illuminate\Support\Facades\DB;

$init_modules = [];
require __DIR__ . '/../includes/init.php';

/**
 * Parse command line arguments
 */
function parseArgs($argv): array
{
    $options = [
        'dry_run' => false,
        'export' => false,
        'migrate' => false,
        'validate' => false,
        'import' => null,
        'verbose' => false,
    ];

    for ($i = 1; $i < count($argv); $i++) {
        switch ($argv[$i]) {
            case '--dry-run':
                $options['dry_run'] = true;
                break;
            case '--export':
                $options['export'] = true;
                break;
            case '--migrate':
                $options['migrate'] = true;
                break;
            case '--validate':
                $options['validate'] = true;
                break;
            case '--import':
                if (isset($argv[$i + 1])) {
                    $options['import'] = $argv[$i + 1];
                    $i++;
                } else {
                    echo "Error: --import requires a file path\n";
                    exit(1);
                }
                break;
            case '--verbose':
            case '-v':
                $options['verbose'] = true;
                break;
            default:
                echo "Unknown option: {$argv[$i]}\n";
                printUsage();
                exit(1);
        }
    }

    return $options;
}

/**
 * Print usage information
 */
function printUsage()
{
    echo "Usage: php migrate-device-api-to-attributes.php [OPTIONS]\n\n";
    echo "Options:\n";
    echo "  --dry-run         Preview changes without modifying database\n";
    echo "  --export          Export current config to JSON (stdout)\n";
    echo "  --migrate         Perform the migration\n";
    echo "  --validate        Validate migration results\n";
    echo "  --import <file>   Import config from JSON backup file\n";
    echo "  --verbose, -v     Verbose output\n\n";
    echo "Examples:\n";
    echo "  # Preview migration:\n";
    echo "  php migrate-device-api-to-attributes.php --dry-run --verbose\n\n";
    echo "  # Export backup:\n";
    echo "  php migrate-device-api-to-attributes.php --export > backup.json\n\n";
    echo "  # Perform migration:\n";
    echo "  php migrate-device-api-to-attributes.php --migrate\n\n";
    echo "  # Rollback from backup:\n";
    echo "  php migrate-device-api-to-attributes.php --import backup.json\n";
}

/**
 * Export current API configuration to JSON
 */
function exportConfig(bool $verbose): void
{
    if ($verbose) {
        fwrite(STDERR, "Exporting API configuration...\n");
    }

    $configs = DeviceApiConfig::with(['template', 'schema', 'device.apiEndpoints'])
        ->get()
        ->map(function ($config) {
            $device = $config->device;

            return [
                'device_id' => $config->device_id,
                'hostname' => $device->hostname,
                'base_url' => $config->base_url,
                'verify_ssl' => $config->verify_ssl,
                'template_key' => $config->template->key ?? null,
                'auth_schema' => $config->schema->key ?? null,
                'credentials' => $config->values, // Already encrypted
                'extra_headers' => $config->extra_headers,
                'disabled_capabilities' => $device->apiEndpoints()
                    ->where('enabled', false)
                    ->pluck('capability')
                    ->unique()
                    ->toArray(),
            ];
        })
        ->toArray();

    if ($verbose) {
        fwrite(STDERR, "Exported " . count($configs) . " device configurations\n");
    }

    echo json_encode([
        'version' => '1.0',
        'export_time' => date('Y-m-d H:i:s'),
        'devices' => $configs,
    ], JSON_PRETTY_PRINT) . "\n";
}

/**
 * Import configuration from JSON backup
 */
function importConfig(string $file, bool $verbose): void
{
    if (!file_exists($file)) {
        echo "Error: File not found: $file\n";
        exit(1);
    }

    $json = file_get_contents($file);
    $data = json_decode($json, true);

    if (!$data || !isset($data['devices'])) {
        echo "Error: Invalid backup file format\n";
        exit(1);
    }

    if ($verbose) {
        echo "Importing configuration from backup...\n";
        echo "Backup created: {$data['export_time']}\n";
        echo "Devices in backup: " . count($data['devices']) . "\n\n";
    }

    $imported = 0;
    foreach ($data['devices'] as $deviceData) {
        $device = Device::find($deviceData['device_id']);

        if (!$device) {
            if ($verbose) {
                echo "Warning: Device {$deviceData['device_id']} ({$deviceData['hostname']}) not found, skipping\n";
            }
            continue;
        }

        // Set attributes from backup
        $device->setAttrib('api_base_url', $deviceData['base_url']);
        $device->setAttrib('api_verify_ssl', $deviceData['verify_ssl']);
        $device->setAttrib('api_template_key', $deviceData['template_key']);
        $device->setAttrib('api_auth_schema', $deviceData['auth_schema']);

        // Restore credentials (skip null values)
        foreach ($deviceData['credentials'] as $key => $value) {
            if ($value !== null) {
                $device->setAttrib("api_credential_{$key}", $value);
            }
        }

        // Restore extra headers
        if (!empty($deviceData['extra_headers'])) {
            $device->setAttrib('api_extra_headers', json_encode($deviceData['extra_headers']));
        }

        // Restore disabled capabilities
        if (!empty($deviceData['disabled_capabilities'])) {
            $device->setAttrib('api_disabled_capabilities', json_encode($deviceData['disabled_capabilities']));
        }

        $device->setAttrib('api_migrated_at', now()->toDateTimeString());

        $imported++;

        if ($verbose) {
            echo "✓ Imported device {$device->device_id} ({$device->hostname})\n";
        }
    }

    echo "\nImport complete: {$imported} devices restored\n";
}

/**
 * Perform dry-run migration (preview only, no changes)
 */
function dryRunMigration(bool $verbose): void
{
    echo "=== DRY RUN MODE - No changes will be made ===\n\n";

    $configs = DeviceApiConfig::with(['template', 'schema', 'device.apiEndpoints'])->get();

    if ($configs->isEmpty()) {
        echo "No devices with API configuration found.\n";
        return;
    }

    echo "Found " . $configs->count() . " devices with API configuration\n\n";

    foreach ($configs as $config) {
        $device = $config->device;

        echo "Device: {$device->hostname} (ID: {$device->device_id})\n";
        echo "  Template: {$config->template->key}\n";
        echo "  Auth Schema: {$config->schema->key}\n";
        echo "  Base URL: {$config->base_url}\n";
        echo "  Verify SSL: " . ($config->verify_ssl ? 'Yes' : 'No') . "\n";

        if ($verbose) {
            echo "  Credentials:\n";
            foreach ($config->values as $key => $value) {
                $masked = str_repeat('*', min(8, strlen($value)));
                echo "    - api_credential_{$key}: {$masked}\n";
            }
        }

        // Check for disabled endpoints
        $disabledCaps = $device->apiEndpoints()
            ->where('enabled', false)
            ->pluck('capability')
            ->unique();

        if ($disabledCaps->isNotEmpty()) {
            echo "  Disabled capabilities: " . $disabledCaps->implode(', ') . "\n";
        }

        echo "  Would create " . (count($config->values) + 4) . " device attributes\n";
        echo "\n";
    }

    echo "Total devices to migrate: " . $configs->count() . "\n";
}

/**
 * Perform actual migration
 */
function performMigration(bool $verbose): void
{
    echo "=== MIGRATING DEVICE API CONFIGURATION ===\n\n";

    $configs = DeviceApiConfig::with(['template', 'schema', 'device.apiEndpoints'])->get();

    if ($configs->isEmpty()) {
        echo "No devices with API configuration found.\n";
        return;
    }

    echo "Migrating " . $configs->count() . " devices...\n\n";

    $migrated = 0;
    $errors = 0;

    foreach ($configs as $config) {
        $device = $config->device;

        try {
            // Store base configuration
            $device->setAttrib('api_base_url', $config->base_url);
            $device->setAttrib('api_verify_ssl', $config->verify_ssl);
            $device->setAttrib('api_template_key', $config->template->key);
            $device->setAttrib('api_auth_schema', $config->schema->key);

            // Store credentials (already encrypted in values field)
            // Skip null values as database doesn't allow NULL in attrib_value
            foreach ($config->values as $key => $value) {
                if ($value !== null) {
                    $device->setAttrib("api_credential_{$key}", $value);
                }
            }

            // Store extra headers if any
            if ($config->extra_headers) {
                $device->setAttrib('api_extra_headers', json_encode($config->extra_headers));
            }

            // Store disabled capabilities (from device_api_endpoints)
            $disabledCaps = $device->apiEndpoints()
                ->where('enabled', false)
                ->pluck('capability')
                ->unique()
                ->toArray();

            if (!empty($disabledCaps)) {
                $device->setAttrib('api_disabled_capabilities', json_encode($disabledCaps));
            }

            // Mark as migrated
            $device->setAttrib('api_migrated_at', now()->toDateTimeString());

            $migrated++;

            if ($verbose) {
                echo "✓ Migrated device {$device->device_id} ({$device->hostname})\n";
            } else {
                echo ".";
            }
        } catch (\Exception $e) {
            $errors++;
            echo "\n✗ Error migrating device {$device->device_id} ({$device->hostname}): {$e->getMessage()}\n";
        }
    }

    if (!$verbose) {
        echo "\n";
    }

    echo "\n=== MIGRATION COMPLETE ===\n";
    echo "Successfully migrated: {$migrated}\n";
    echo "Errors: {$errors}\n";

    if ($errors === 0) {
        echo "\n✓ All devices migrated successfully!\n";
    }
}

/**
 * Validate migration results
 */
function validateMigration(bool $verbose): void
{
    echo "=== VALIDATING MIGRATION ===\n\n";

    $configs = DeviceApiConfig::with(['template', 'device'])->get();
    $total = $configs->count();
    $valid = 0;
    $invalid = 0;

    if ($total === 0) {
        echo "No devices with API configuration found in tables.\n";
        return;
    }

    foreach ($configs as $config) {
        $device = $config->device;
        $deviceId = $device->device_id;
        $hostname = $device->hostname;

        // Check if device has been migrated
        $migratedAt = $device->getAttrib('api_migrated_at');

        if (!$migratedAt) {
            echo "✗ Device {$deviceId} ({$hostname}): Not migrated\n";
            $invalid++;
            continue;
        }

        // Validate required attributes exist
        $baseUrl = $device->getAttrib('api_base_url');
        $templateKey = $device->getAttrib('api_template_key');

        if (!$baseUrl || !$templateKey) {
            echo "✗ Device {$deviceId} ({$hostname}): Missing required attributes\n";
            if ($verbose) {
                echo "  base_url: " . ($baseUrl ?? 'MISSING') . "\n";
                echo "  template_key: " . ($templateKey ?? 'MISSING') . "\n";
            }
            $invalid++;
            continue;
        }

        // Compare table vs attributes
        $tableBaseUrl = $config->base_url;
        $tableTemplateKey = $config->template->key;

        if ($baseUrl !== $tableBaseUrl || $templateKey !== $tableTemplateKey) {
            echo "✗ Device {$deviceId} ({$hostname}): Mismatch between table and attributes\n";
            if ($verbose) {
                echo "  Table base_url: {$tableBaseUrl}\n";
                echo "  Attribute base_url: {$baseUrl}\n";
                echo "  Table template: {$tableTemplateKey}\n";
                echo "  Attribute template: {$templateKey}\n";
            }
            $invalid++;
            continue;
        }

        $valid++;

        if ($verbose) {
            echo "✓ Device {$deviceId} ({$hostname}): Valid\n";
        }
    }

    echo "\n=== VALIDATION RESULTS ===\n";
    echo "Total devices: {$total}\n";
    echo "Valid: {$valid}\n";
    echo "Invalid: {$invalid}\n";

    if ($invalid === 0) {
        echo "\n✓ All devices validated successfully!\n";
    } else {
        echo "\n✗ Some devices failed validation. Please review errors above.\n";
        exit(1);
    }
}

/**
 * Main script execution
 */
function main($argv): void
{
    $options = parseArgs($argv);

    // Validate that at least one action is specified
    if (!$options['dry_run'] && !$options['export'] && !$options['migrate'] && !$options['validate'] && !$options['import']) {
        echo "Error: No action specified\n\n";
        printUsage();
        exit(1);
    }

    // Execute requested action
    if ($options['export']) {
        exportConfig($options['verbose']);
    } elseif ($options['import']) {
        importConfig($options['import'], $options['verbose']);
    } elseif ($options['dry_run']) {
        dryRunMigration($options['verbose']);
    } elseif ($options['migrate']) {
        // Confirm before migrating
        echo "WARNING: This will migrate API configuration from tables to device attributes.\n";
        echo "It is recommended to create a backup first using --export.\n\n";
        echo "Do you want to continue? (yes/no): ";

        $handle = fopen("php://stdin", "r");
        $line = trim(fgets($handle));
        fclose($handle);

        if (strtolower($line) !== 'yes') {
            echo "Migration cancelled.\n";
            exit(0);
        }

        performMigration($options['verbose']);
    } elseif ($options['validate']) {
        validateMigration($options['verbose']);
    }
}

// Run the script
main($argv);
