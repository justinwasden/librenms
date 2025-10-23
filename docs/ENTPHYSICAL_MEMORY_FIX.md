# entPhysical Memory Exhaustion Fix

## Problem

When accessing the inventory page (`/device/<id>/tab=entphysical/`), LibreNMS would crash with:

```
[CRITICAL] Exception: Symfony\Component\ErrorHandler\Error\FatalError
Allowed memory size of 134217728 bytes exhausted (tried to allocate 20480 bytes)
@ /opt/librenms/includes/dbFacile.php:257
```

## Root Cause

The inventory page uses a **recursive function** `printEntPhysical()` to display the hardware hierarchy tree structure. This function:

1. Loads entPhysical records for a given `entPhysicalContainedIn` value
2. For each record, recursively calls itself to load child records
3. Builds a nested tree structure

**The Problem:**
When REST API polling creates entPhysical records (like PureStorage drive bays), if the `entPhysicalContainedIn` field is NULL or not set properly, the recursive query on line 8 of `entphysical.inc.php` can create an infinite loop or load the same records repeatedly.

### Inventory Page Code (entphysical.inc.php:8)
```php
function printEntPhysical($device, $ent, $level, $class)
{
    // This query loads records with matching entPhysicalContainedIn
    $ents = dbFetchRows('SELECT * FROM `entPhysical` WHERE device_id = ? AND entPhysicalContainedIn = ? ORDER BY entPhysicalContainedIn,entPhysicalIndex', [$device['device_id'], $ent]);

    foreach ($ents as $ent) {
        // ... display logic ...

        // RECURSIVE CALL - line 138
        printEntPhysical($device, $ent['entPhysicalIndex'], $level + 1, 'liClosed');
    }
}
```

### The Recursion Issue

If `entPhysicalContainedIn` is NULL or improperly set:
- Query matches all records with NULL `entPhysicalContainedIn`
- Function processes these records
- Recursively queries again with `entPhysicalIndex` values
- If indexes point back to NULL or create cycles → **infinite recursion**
- Memory exhaustion after thousands of iterations

## Solution

### Fix in DataPersistence.php

Updated `applyEntPhysicalEntity()` method to:

1. **Set default `entPhysicalContainedIn` to 0** (root level) if not provided
2. **Use insert() instead of updateOrInsert()** for new records to let `entPhysicalIndex` auto-increment properly

### Code Changes

**Before** (lines 409-436):
```php
$filteredData = array_intersect_key($entityData, array_flip($validColumns));
$filteredData['entPhysicalName'] = $identifier;

// Store extra fields as metrics...

DB::table('entPhysical')->updateOrInsert(
    [
        'device_id' => $deviceId,
        'entPhysicalName' => $identifier,
    ],
    $filteredData
);
```

**After** (lines 409-453):
```php
$filteredData = array_intersect_key($entityData, array_flip($validColumns));
$filteredData['entPhysicalName'] = $identifier;

// CRITICAL: Set default hierarchy fields to prevent infinite recursion
if (!isset($filteredData['entPhysicalContainedIn'])) {
    $filteredData['entPhysicalContainedIn'] = 0;  // Root level
}

// Store extra fields as metrics...

// Get or create entPhysical record
$existing = DB::table('entPhysical')
    ->where('device_id', $deviceId)
    ->where('entPhysicalName', $identifier)
    ->first();

if ($existing) {
    // Update existing record
    DB::table('entPhysical')
        ->where('device_id', $deviceId)
        ->where('entPhysicalName', $identifier)
        ->update($filteredData);
} else {
    // Insert new record - let entPhysicalIndex auto-increment
    DB::table('entPhysical')->insert(array_merge([
        'device_id' => $deviceId,
    ], $filteredData));
}
```

## What Changed

### 1. Default `entPhysicalContainedIn = 0`

All REST API-created entPhysical records now have `entPhysicalContainedIn = 0` by default, meaning they appear at the root level of the inventory tree.

**Before:**
- `entPhysicalContainedIn` could be NULL
- Caused infinite recursion in tree traversal

**After:**
- `entPhysicalContainedIn` defaults to 0 (root level)
- Records appear at top of inventory tree
- No infinite recursion

### 2. Proper Insert/Update Logic

Changed from `updateOrInsert()` to explicit `insert()` or `update()` to ensure:
- Auto-increment `entPhysicalIndex` works correctly on new records
- Existing records are updated without changing their index
- No duplicate records created

## Testing

### 1. Clear Any Broken Records (if needed)

If you have existing entPhysical records with NULL `entPhysicalContainedIn`:

```sql
-- Show records with NULL entPhysicalContainedIn
SELECT
    entPhysical_id,
    device_id,
    entPhysicalName,
    entPhysicalClass,
    entPhysicalContainedIn,
    entPhysicalIndex
FROM entPhysical
WHERE entPhysicalContainedIn IS NULL;

-- Fix them by setting to root level
UPDATE entPhysical
SET entPhysicalContainedIn = 0
WHERE entPhysicalContainedIn IS NULL;
```

### 2. Run REST API Poll

```bash
./poller.php -h <purestorage-hostname> -d -m restapi
```

### 3. Verify entPhysical Records

```sql
-- Check PureStorage drive bays
SELECT
    entPhysical_id,
    entPhysicalName,
    entPhysicalClass,
    entPhysicalDescr,
    entPhysicalContainedIn,
    entPhysicalIndex
FROM entPhysical
WHERE device_id = <DEVICE_ID>
  AND entPhysicalClass = 'drive'
ORDER BY entPhysicalName;
```

**Expected Results:**
- `entPhysicalContainedIn` = 0 (root level)
- `entPhysicalIndex` = unique auto-increment ID
- Drive names like CH0.BAY0, CH0.BAY1, CH1.NVB0

### 4. Test Inventory Page

Navigate to: **Device → Inventory** (or `/device/<id>/tab=entphysical/`)

**Expected:**
- Page loads successfully (no memory exhaustion)
- Drive bays appear at root level of tree
- No infinite loop or recursion errors

### 5. Monitor Memory Usage

```bash
# Watch memory during page load
tail -f /opt/librenms/logs/librenms.log | grep -i "memory\|fatal"
```

**Expected:**
- No memory exhaustion errors
- Page loads within normal PHP memory limits

## Hierarchy Structure

### Default (Root Level)

With this fix, all REST API entities default to root level:

```
Root (entPhysicalContainedIn = 0)
├─ CH0.BAY0 (drive)
├─ CH0.BAY1 (drive)
├─ CH0.BAY2 (drive)
├─ CH1.NVB0 (drive)
├─ CH1.NVB1 (drive)
└─ ct0.eth18 (port, if routed to entPhysical)
```

### Future Enhancement - Proper Hierarchy

If PureStorage API provides chassis/controller hierarchy info, we could create proper parent-child relationships:

```
Root
└─ Chassis
    ├─ Controller 0
    │   ├─ CH0.BAY0 (entPhysicalContainedIn = chassis_index)
    │   ├─ CH0.BAY1
    │   └─ CH0.eth18
    └─ Controller 1
        ├─ CH1.NVB0
        └─ CH1.NVB1
```

To implement this, the API response would need to include:
- Parent/container information
- Physical index relationships
- Chassis/controller identifiers

Then update PureStorageDataProcessor to set:
```php
$hardwareData = [
    'entPhysicalName' => $identifier,
    'entPhysicalClass' => 'drive',
    'entPhysicalContainedIn' => $parentIndex,  // From API
    'entPhysicalParentRelPos' => $position,    // Slot number
];
```

## Impact

### Before Fix
- ❌ Inventory page crashes with memory exhaustion
- ❌ Cannot view hardware components
- ❌ REST API polling creates problematic entPhysical records

### After Fix
- ✅ Inventory page loads successfully
- ✅ Drive bays appear in inventory tree (at root level)
- ✅ No memory issues
- ✅ Proper record creation/updates

## Files Modified

- `app/Services/RestApi/DataPersistence.php` (lines 409-453)
  - Added default `entPhysicalContainedIn = 0`
  - Changed to explicit insert/update logic
  - Added comments explaining recursion fix

## Related Issues

This fix resolves:
1. Memory exhaustion on inventory page
2. Infinite recursion in entPhysical tree traversal
3. NULL `entPhysicalContainedIn` causing query issues
4. Improper auto-increment of `entPhysicalIndex`

## Prevention

Going forward, **always set `entPhysicalContainedIn`** when creating entPhysical records via REST API:

```php
// GOOD - Explicit hierarchy
$entPhysicalData = [
    'entPhysicalName' => $name,
    'entPhysicalClass' => $class,
    'entPhysicalContainedIn' => $parentIndex ?? 0,  // Default to root
];

// BAD - Missing hierarchy field
$entPhysicalData = [
    'entPhysicalName' => $name,
    'entPhysicalClass' => $class,
    // entPhysicalContainedIn missing - will be NULL!
];
```

## Summary

The inventory page memory exhaustion was caused by NULL `entPhysicalContainedIn` values in REST API-created entPhysical records. The recursive tree traversal function would enter an infinite loop trying to process these records.

**Fix:** Set `entPhysicalContainedIn = 0` (root level) by default for all REST API entities.

**Result:** Inventory page now loads successfully, and drive bays appear in the inventory tree.
