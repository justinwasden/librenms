<?php
/**
 * Fix API configuration issues after Phase 2 migration
 * - Set api_enabled=1 for devices with api_base_url
 * - Map old template keys to new template keys
 * - Set auth_type from template if not set
 */

require_once __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\Device;
use LibreNMS\Util\ApiTemplateManager;

echo "=== Fixing API Configuration Issues ===\n\n";

// Template key mapping from old to new
$templateKeyMapping = [
    'esxi_soap' => 'vmware_esxi',
    'vcenter_soap' => 'vmware_vcenter',
    'cisco_ucsm_xml' => 'cisco_ucsm',
    'proxmox_ve_token' => 'proxmox_ve',
    'purestorage_flasharray' => 'purestorage_flasharray', // Same
    'fortinet_fortigate' => 'fortinet_fortigate', // Same
    'vmware_velocloud' => 'vmware_velocloud', // Same
    'netapp_ontap' => 'netapp_ontap', // Same
    'cisco_ftd' => 'cisco_ftd', // Same
];

// Get all devices with API configuration
$devices = Device::whereHas('attribs', function($q) {
    $q->where('attrib_type', 'api_base_url');
})->get();

echo "Found " . $devices->count() . " devices with API configuration\n\n";

$fixed = 0;
foreach ($devices as $device) {
    $baseUrl = $device->getAttrib('api_base_url');
    $currentTemplate = $device->getAttrib('api_template_key');
    $apiEnabled = $device->getAttrib('api_enabled');
    $authType = $device->getAttrib('api_auth_type');

    $changes = [];

    // 1. Set api_enabled if not set
    if (!$apiEnabled && $baseUrl) {
        $device->setAttrib('api_enabled', '1');
        $changes[] = 'api_enabled=1';
    }

    // 2. Fix template key mapping
    if ($currentTemplate && isset($templateKeyMapping[$currentTemplate])) {
        $newTemplateKey = $templateKeyMapping[$currentTemplate];
        if ($newTemplateKey !== $currentTemplate) {
            $device->setAttrib('api_template_key', $newTemplateKey);
            $changes[] = "template: $currentTemplate -> $newTemplateKey";
        }
    }

    // 3. Set auth_type from template if not set
    if (empty($authType) || $authType === 'none') {
        $templateKey = $device->getAttrib('api_template_key');
        $template = ApiTemplateManager::loadTemplate($templateKey);
        if ($template && isset($template['auth_type'])) {
            $device->setAttrib('api_auth_type', $template['auth_type']);
            $device->setAttrib('api_auth_schema', $template['auth_type']);
            $changes[] = "auth_type=" . $template['auth_type'];
        }
    }

    if (!empty($changes)) {
        echo "Fixed: {$device->hostname} - " . implode(', ', $changes) . "\n";
        $fixed++;
    }
}

echo "\n=== Summary ===\n";
echo "Fixed $fixed devices\n";
