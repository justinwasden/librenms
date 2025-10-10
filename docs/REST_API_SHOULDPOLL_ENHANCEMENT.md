# REST API Module - shouldPoll Logic Update

## ✅ Enhanced Poll Validation

The `shouldPoll()` method in `/LibreNMS/Modules/RestApi.php` has been improved to properly validate all necessary conditions before polling.

---

## 🎯 Complete Validation Logic

The module now checks **5 critical conditions** before attempting to poll:

### 1. **Device Status is UP**
- Checks: `$device->status == 1`
- Why: No point polling a device that's down

### 2. **Device is NOT Disabled**
- Checks: `$device->disabled == 0`
- Why: Disabled devices should not be polled at all

### 3. **Device is NOT Ignored**
- Checks: `$device->ignore == 0`
- Why: Ignored devices are intentionally excluded from monitoring

### 4. **Device Has REST API Connections**
- Checks: `$device->restApiConnections()->exists()`
- Why: Can't poll REST API if no connections are configured

### 5. **At Least One Connection is ENABLED**
- Checks: `$device->restApiConnections()->where('enabled', 1)->exists()`
- Why: Connections can exist but be disabled - we should only poll enabled ones

---

## 📝 Updated Code

```php
public function shouldPoll(OS $os, ModuleStatus $status): bool
{
    $device = $os->getDevice();

    // Only poll if:
    // 1. Device has REST API connections
    // 2. At least one connection is enabled
    // 3. Device status is up
    // 4. Device is not disabled
    // 5. Device is not ignored
    if ($device->disabled || $device->ignore || !$device->status) {
        return false;
    }

    // Check if device has any enabled REST API connections
    return $device->restApiConnections()->where('enabled', 1)->exists();
}
```

---

## 🔍 Logic Flow

```
                    START
                      |
                      v
         Is device disabled? ----YES----> RETURN FALSE (Don't Poll)
                      |
                     NO
                      v
         Is device ignored? -----YES----> RETURN FALSE (Don't Poll)
                      |
                     NO
                      v
         Is device down? --------YES----> RETURN FALSE (Don't Poll)
                      |
                     NO
                      v
    Has REST API connections? --NO-----> RETURN FALSE (Don't Poll)
                      |
                     YES
                      v
    Any connection enabled? ----NO-----> RETURN FALSE (Don't Poll)
                      |
                     YES
                      v
              RETURN TRUE (Poll!)
```

---

## 🚀 Why This Matters

### Before (Inadequate):
```php
// Old logic - only checked 2 things
return $device->restApiConnections()->exists() && $device->status;
```

**Problems:**
- ❌ Would attempt to poll disabled devices
- ❌ Would attempt to poll ignored devices  
- ❌ Would attempt to poll if ALL connections were disabled
- ❌ Wasted resources on devices that shouldn't be polled

### After (Comprehensive):
```php
// New logic - checks 5 things
if ($device->disabled || $device->ignore || !$device->status) {
    return false;
}
return $device->restApiConnections()->where('enabled', 1)->exists();
```

**Benefits:**
- ✅ Respects device disabled flag
- ✅ Respects device ignore flag
- ✅ Only polls devices that are up
- ✅ Only polls if at least one connection is enabled
- ✅ Prevents wasted API calls to disabled/ignored devices

---

## 📊 Database Fields Checked

| Field | Table | Check | Purpose |
|-------|-------|-------|---------|
| `status` | `devices` | `= 1` | Device is reachable/up |
| `disabled` | `devices` | `= 0` | Device monitoring enabled |
| `ignore` | `devices` | `= 0` | Device not ignored |
| `enabled` | `rest_api_connections` | `= 1` | Connection is active |
| (exists) | `rest_api_connections` | `> 0` | Has configurations |

---

## 🧪 Testing the Logic

### Test Case 1: Normal Device (Should Poll)
```
Device: UP, Not Disabled, Not Ignored
Connection: Exists and Enabled
Result: ✅ POLLS
```

### Test Case 2: Disabled Device (Should NOT Poll)
```
Device: UP, DISABLED, Not Ignored
Connection: Exists and Enabled
Result: ❌ DOES NOT POLL
```

### Test Case 3: Ignored Device (Should NOT Poll)
```
Device: UP, Not Disabled, IGNORED
Connection: Exists and Enabled
Result: ❌ DOES NOT POLL
```

### Test Case 4: Down Device (Should NOT Poll)
```
Device: DOWN, Not Disabled, Not Ignored
Connection: Exists and Enabled
Result: ❌ DOES NOT POLL
```

### Test Case 5: No Connections (Should NOT Poll)
```
Device: UP, Not Disabled, Not Ignored
Connection: NONE
Result: ❌ DOES NOT POLL
```

### Test Case 6: All Connections Disabled (Should NOT Poll)
```
Device: UP, Not Disabled, Not Ignored
Connection: Exists but ALL DISABLED
Result: ❌ DOES NOT POLL
```

---

## 🔧 Manual Verification

Test the logic manually:

```bash
php artisan tinker
```

```php
$device = \App\Models\Device::find(2);

echo "Device Status Checks:\n";
echo "  UP: " . ($device->status ? 'YES' : 'NO') . "\n";
echo "  Disabled: " . ($device->disabled ? 'YES' : 'NO') . "\n";
echo "  Ignored: " . ($device->ignore ? 'YES' : 'NO') . "\n";

echo "\nConnection Checks:\n";
echo "  Has connections: " . ($device->restApiConnections()->exists() ? 'YES' : 'NO') . "\n";
echo "  Has ENABLED connections: " . ($device->restApiConnections()->where('enabled', 1)->exists() ? 'YES' : 'NO') . "\n";

echo "\nConnection Details:\n";
foreach ($device->restApiConnections as $conn) {
    echo "  - {$conn->name}: " . ($conn->enabled ? 'ENABLED' : 'DISABLED') . "\n";
}

// Test shouldPoll logic
$os = new \LibreNMS\OS\Purestorage($device);
$status = new \LibreNMS\Polling\ModuleStatus(true, null, null, null);
$module = new \LibreNMS\Modules\RestApi();
$should_poll = $module->shouldPoll($os, $status);

echo "\nFinal Result: " . ($should_poll ? '✅ SHOULD POLL' : '❌ SHOULD NOT POLL') . "\n";
```

---

## 📋 Quick Troubleshooting

If device is NOT polling when it should:

1. **Check device is UP:**
   ```sql
   SELECT device_id, hostname, status FROM devices WHERE device_id = 2;
   ```
   Expected: `status = 1`

2. **Check device is NOT disabled:**
   ```sql
   SELECT device_id, hostname, disabled FROM devices WHERE device_id = 2;
   ```
   Expected: `disabled = 0`

3. **Check device is NOT ignored:**
   ```sql
   SELECT device_id, hostname, ignore FROM devices WHERE device_id = 2;
   ```
   Expected: `ignore = 0`

4. **Check REST API connection exists:**
   ```sql
   SELECT * FROM rest_api_connections WHERE device_id = 2;
   ```
   Expected: At least one row

5. **Check connection is ENABLED:**
   ```sql
   SELECT * FROM rest_api_connections WHERE device_id = 2 AND enabled = 1;
   ```
   Expected: At least one row with `enabled = 1`

---

## ✅ Summary

The `shouldPoll()` method now performs comprehensive validation:

| Check | Before | After |
|-------|--------|-------|
| Device UP | ✅ | ✅ |
| Device NOT Disabled | ❌ | ✅ |
| Device NOT Ignored | ❌ | ✅ |
| Has Connections | ✅ | ✅ |
| Connection Enabled | ❌ | ✅ |

**Result**: More robust, efficient, and respects all LibreNMS device management flags! 🎯

---

**Updated**: October 4, 2025  
**File**: `/LibreNMS/Modules/RestApi.php`  
**Method**: `shouldPoll()`  
**Status**: ✅ Complete and Tested
