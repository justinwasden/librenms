# REST API Polling Module - Debugging Guide

## 🐛 Issue: REST API Module Not Running During Polling

When running `./poller.php -h 2`, the REST API polling module is not executing for PureStorage devices with API connections and credentials configured.

---

## ✅ What We Verified

1. **Module File Exists**: `/LibreNMS/Modules/RestApi.php` ✓
2. **Module Registered**: Config definition exists for `poller_modules.rest-api` ✓
3. **Device Relationship**: `Device::restApiConnections()` relationship is defined ✓
4. **Module Default**: Set to `true` in config_definitions.json ✓

---

## 🔍 Troubleshooting Steps

### Step 1: Verify Module is Enabled Globally

```bash
# Check if rest-api module is enabled globally
mysql -u librenms -p librenms -e \
  "SELECT * FROM config WHERE config_name = 'poller_modules.rest-api';"
```

**Expected Result**: Should return `value = '1'` or `value = 'true'`

If not enabled:
```bash
# Enable globally via artisan
php artisan config:set poller_modules.rest-api true
```

Or via MySQL:
```sql
INSERT INTO config (config_name, config_value) 
VALUES ('poller_modules.rest-api', '1')
ON DUPLICATE KEY UPDATE config_value = '1';
```

---

### Step 2: Check if Module is Disabled for Device

```bash
# Check device attributes for poll_rest-api
mysql -u librenms -p librenms -e \
  "SELECT * FROM devices_attribs WHERE device_id=2 AND attrib_type='poll_rest-api';"
```

**Expected Result**: Should return nothing OR `attrib_value = 'true'`

If disabled:
```sql
-- Enable for device ID 2
INSERT INTO devices_attribs (device_id, attrib_type, attrib_value) 
VALUES (2, 'poll_rest-api', 'true')
ON DUPLICATE KEY UPDATE attrib_value = 'true';
```

---

### Step 3: Verify REST API Connection Exists and is Enabled

```bash
mysql -u librenms -p librenms -e \
  "SELECT id, device_id, name, enabled, base_url FROM rest_api_connections WHERE device_id=2;"
```

**Expected Result**: Should show at least one connection with `enabled = 1`

If not enabled:
```sql
-- Enable connection
UPDATE rest_api_connections SET enabled = 1 WHERE device_id = 2;
```

---

### Step 4: Check Device Status

```bash
mysql -u librenms -p librenms -e \
  "SELECT device_id, hostname, status, disabled, ignore FROM devices WHERE device_id=2;"
```

**Required**: 
- `status = 1` (device is up)
- `disabled = 0` (device not disabled)
- `ignore = 0` (device not ignored)

---

### Step 5: Test Module Manually

Run polling with explicit module specification and verbose output:

```bash
cd /opt/librenms

# Test with specific module
php lnms device:poll 2 -m rest-api -vvv

# Or test with legacy poller
./poller.php -h 2 -m rest-api -v
```

**Watch for these messages:**
- "Starting polling run"
- Module "rest-api" should appear in the module list
- Should see RestApi module executing

---

### Step 6: Check Module's shouldPoll Logic

The `RestApi` module's `shouldPoll()` method has this logic:

```php
public function shouldPoll(OS $os, ModuleStatus $status): bool
{
    $device = $os->getDevice();
    
    // Only poll if device has REST API connections
    return $device->restApiConnections()->exists() && $device->status;
}
```

**Debug this manually:**

```bash
php artisan tinker
```

Then run:
```php
$device = \App\Models\Device::find(2);

// Check if device has REST API connections
$has_connections = $device->restApiConnections()->exists();
echo "Has connections: " . ($has_connections ? 'YES' : 'NO') . "\n";

// Check device status
echo "Device status: " . ($device->status ? 'UP' : 'DOWN') . "\n";

// Check what shouldPoll would return
$os = new \LibreNMS\OS\Purestorage($device);
$status = new \LibreNMS\Polling\ModuleStatus(true, null, null, null);
$module = new \LibreNMS\Modules\RestApi();
$should_poll = $module->shouldPoll($os, $status);
echo "Should poll: " . ($should_poll ? 'YES' : 'NO') . "\n";

// List all connections
foreach ($device->restApiConnections as $conn) {
    echo "Connection: {$conn->name} - Enabled: " . ($conn->enabled ? 'YES' : 'NO') . "\n";
}
```

---

### Step 7: Check OS-Specific Module Configuration

Some OS types might have module overrides:

```bash
mysql -u librenms -p librenms -e \
  "SELECT * FROM config WHERE config_name LIKE '%purestorage%poller%rest%';"
```

---

### Step 8: Enable Debug Logging

Add debug logging to the RestApi module temporarily:

Edit `/LibreNMS/Modules/RestApi.php` and add logging to `shouldPoll`:

```php
public function shouldPoll(OS $os, ModuleStatus $status): bool
{
    $device = $os->getDevice();
    
    \Illuminate\Support\Facades\Log::debug("RestApi shouldPoll check", [
        'device_id' => $device->device_id,
        'has_connections' => $device->restApiConnections()->exists(),
        'device_status' => $device->status,
        'status' => $status->isEnabled(),
    ]);
    
    // Only poll if device has REST API connections
    return $device->restApiConnections()->exists() && $device->status;
}
```

Then check logs:
```bash
tail -f /opt/librenms/storage/logs/laravel.log | grep "RestApi shouldPoll"
```

---

### Step 9: Verify Poller Modules List

Check what modules are actually being loaded:

```bash
# Run with very verbose output
php lnms device:poll 2 -vvv 2>&1 | grep -i "rest"
```

Look for:
- "rest-api" in the module list
- Any errors related to RestApi module
- Module status (enabled/disabled)

---

### Step 10: Check for Module Name Case Issues

The module name should be `rest-api` (lowercase with hyphen). Verify:

```bash
# Search for any config with different casing
mysql -u librenms -p librenms -e \
  "SELECT * FROM config WHERE config_name LIKE '%rest%api%';"
```

---

## 🔧 Common Fixes

### Fix 1: Module Not in Database Config

```bash
php artisan config:set poller_modules.rest-api true
```

### Fix 2: Clear Config Cache

```bash
php artisan config:clear
php artisan cache:clear
```

### Fix 3: Re-enable REST API Connection

```sql
UPDATE rest_api_connections 
SET enabled = 1 
WHERE device_id = 2;
```

### Fix 4: Force Module Run

```bash
# Force run the module regardless of config
php lnms device:poll 2 --modules=rest-api
```

---

## 📊 Expected Polling Output

When working correctly, you should see:

```
Starting polling run:

#### Load disco module rest-api ####

Modules: rest-api
```

Then detailed API polling:
```
>> Runtime for Discovery module rest-api: X.XX seconds with XXXX bytes
```

---

## 🎯 Quick Diagnostic Script

Save this as `test_rest_api_poll.php` in LibreNMS root:

```php
#!/usr/bin/env php
<?php

require __DIR__ . '/includes/init.php';

$device_id = $argv[1] ?? 2;
$device = \App\Models\Device::find($device_id);

if (!$device) {
    echo "Device $device_id not found!\n";
    exit(1);
}

echo "Device: {$device->hostname} (ID: {$device->device_id})\n";
echo "OS: {$device->os}\n";
echo "Status: " . ($device->status ? 'UP' : 'DOWN') . "\n";
echo "\n";

// Check REST API connections
echo "REST API Connections:\n";
$connections = $device->restApiConnections;
if ($connections->isEmpty()) {
    echo "  ❌ NO CONNECTIONS FOUND\n";
} else {
    foreach ($connections as $conn) {
        echo "  - {$conn->name}: " . ($conn->enabled ? '✅ ENABLED' : '❌ DISABLED') . "\n";
        echo "    URL: {$conn->base_url}\n";
        echo "    Endpoints: " . $conn->endpoints->count() . "\n";
    }
}
echo "\n";

// Check module status
echo "Module Status:\n";
$global = \App\Facades\LibrenmsConfig::get("poller_modules.rest-api");
echo "  Global: " . ($global ? '✅ ENABLED' : '❌ DISABLED') . "\n";

$os_specific = \App\Facades\LibrenmsConfig::get("os.{$device->os}.poller_modules.rest-api");
echo "  OS-Specific ({$device->os}): " . ($os_specific === null ? 'NOT SET' : ($os_specific ? 'ENABLED' : 'DISABLED')) . "\n";

$device_attrib = $device->getAttrib("poll_rest-api");
echo "  Device Attrib: " . ($device_attrib === null ? 'NOT SET' : ($device_attrib == 'true' ? 'ENABLED' : 'DISABLED')) . "\n";

// Test shouldPoll
echo "\n";
echo "shouldPoll Test:\n";
try {
    $os = new \LibreNMS\OS\Purestorage($device);
    $status = \LibreNMS\Util\Module::pollingStatus('rest-api', $device);
    $module = new \LibreNMS\Modules\RestApi();
    
    echo "  ModuleStatus enabled: " . ($status->isEnabled() ? 'YES' : 'NO') . "\n";
    echo "  Has connections: " . ($device->restApiConnections()->exists() ? 'YES' : 'NO') . "\n";
    echo "  Device status: " . ($device->status ? 'UP' : 'DOWN') . "\n";
    
    $should_poll = $module->shouldPoll($os, $status);
    echo "  Result: " . ($should_poll ? '✅ SHOULD POLL' : '❌ SHOULD NOT POLL') . "\n";
} catch (\Exception $e) {
    echo "  ❌ ERROR: " . $e->getMessage() . "\n";
}
```

Run it:
```bash
chmod +x test_rest_api_poll.php
./test_rest_api_poll.php 2
```

---

## 📝 Summary Checklist

Before the REST API module will run, ALL of these must be true:

- [ ] Module enabled globally (`poller_modules.rest-api = true`)
- [ ] Module not disabled for device (no `poll_rest-api = false` attrib)
- [ ] Device has at least one REST API connection (`rest_api_connections` table)
- [ ] REST API connection is enabled (`enabled = 1`)
- [ ] Device status is up (`status = 1`)
- [ ] Device not disabled (`disabled = 0`)
- [ ] Device not ignored (`ignore = 0`)

---

**Next Steps**: Run through each troubleshooting step above to identify which condition is failing.
