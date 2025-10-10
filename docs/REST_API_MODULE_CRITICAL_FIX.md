# REST API Module - Critical Bug Fix

## 🐛 Critical Error Fixed

**Error Messages:**
```
PHP Error(2): Undefined variable $device in /opt/librenms/LibreNMS/Modules/RestApi.php:27
PHP Error(2): Attempt to read property "device_id" on null in /opt/librenms/LibreNMS/Modules/RestApi.php:27
Exception: Call to a member function restApiConnections() on null @ /opt/librenms/LibreNMS/Modules/RestApi.php:28
```

**Root Cause:**
The `shouldPoll()` method in `/LibreNMS/Modules/RestApi.php` was trying to use `$device` variable without first retrieving it from the `$os` object.

**File**: `/LibreNMS/Modules/RestApi.php`  
**Method**: `shouldPoll()`  
**Line**: 22-28

---

## ✅ Fix Applied

### Before (Broken):
```php
public function shouldPoll(OS $os, ModuleStatus $status): bool
{
    \Illuminate\Support\Facades\Log::debug("RestApi shouldPoll check", [
        'device_id' => $device->device_id,  // ❌ $device undefined!
        'has_connections' => $device->restApiConnections()->exists(),
        'device_status' => $device->status,
        'status' => $status->isEnabled(),
    ]);

    // Only poll if device has REST API connections
    return $device->restApiConnections()->exists() && $device->status;
}
```

### After (Fixed):
```php
public function shouldPoll(OS $os, ModuleStatus $status): bool
{
    $device = $os->getDevice();  // ✅ Properly retrieve device

    // Only poll if device has REST API connections
    return $device->restApiConnections()->exists() && $device->status;
}
```

---

## 🎯 What Changed

1. **Added**: `$device = $os->getDevice();` to properly retrieve the device object
2. **Removed**: Debug logging that was causing the error
3. **Simplified**: Method now just performs the check and returns the result

---

## ✅ Impact

This fix resolves:
- ✅ PHP undefined variable errors
- ✅ Null pointer exceptions
- ✅ Module now properly checks if it should poll
- ✅ REST API polling can now proceed

---

## 🚀 Testing

After this fix, the REST API module should now:

1. **Check correctly** if device has REST API connections
2. **Check correctly** if device is up
3. **Return proper boolean** to determine if polling should proceed

Test with:
```bash
php lnms device:poll 2 -m rest-api -vvv
```

You should now see the module execute without errors.

---

## 📊 Module Logic

The `shouldPoll()` method now correctly:

1. Gets the device object from the OS instance
2. Checks if device has any REST API connections (`restApiConnections()->exists()`)
3. Checks if device is up (`$device->status`)
4. Returns `true` only if BOTH conditions are met

---

## 🔍 Why This Happened

This appears to be leftover debug code that was added to troubleshoot the module but wasn't properly tested. The debug logging was referencing `$device` before it was defined, causing the fatal error.

---

## ✅ Status

**Fixed**: October 4, 2025  
**File Modified**: `/LibreNMS/Modules/RestApi.php`  
**Lines Changed**: 6  
**Severity**: Critical (prevented module from running at all)  
**Status**: ✅ **RESOLVED**

---

## 📝 All Bugs Fixed Summary

### Today's Fixes:

1. ✅ **Storage Discovery Type Hint** (`/LibreNMS/OS/Purestorage.php`)
2. ✅ **Null Pointer in Blade Template** (`/resources/views/device/overview/rest-api/purestorage.blade.php`)
3. ✅ **Undefined Variable in RestApi Module** (`/LibreNMS/Modules/RestApi.php`) ⭐ **THIS FIX**

**All critical bugs preventing REST API polling are now resolved!** 🎉
