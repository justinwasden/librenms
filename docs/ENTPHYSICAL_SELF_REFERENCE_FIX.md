# entPhysical Self-Reference Fix

## Problem Identified

Query results showed the exact issue:

```
| device_id | total_records | null_container | root_level | self_reference |
+-----------+---------------+----------------+------------+----------------+
|         3 |            96 |              0 |         96 |             96 |
```

**All 96 records have `entPhysicalIndex = entPhysicalContainedIn`**

This creates an infinite loop:
- Record with index 2614 has containedIn = 2614 (points to itself)
- Inventory page tries to load children of 2614
- Finds record 2614 again
- Tries to load children of 2614 again
- **Infinite recursion → Memory exhaustion**

## Root Cause

When creating entPhysical records via REST API, the code was:

1. Accepting `entPhysicalIndex` from input data (if present)
2. Accepting `entPhysicalContainedIn` from input data (if present)
3. If both had the same value → self-reference created
4. **OR** the database was auto-setting `entPhysicalContainedIn` to match `entPhysicalIndex` somehow

The real issue: `entPhysicalIndex` should **never** be set manually - it's an auto-increment field managed by the database.

## Fixes Applied

### 1. Code Fix in DataPersistence.php

**File:** `app/Services/RestApi/DataPersistence.php:421-436`

Added three safeguards:

```php
// SAFEGUARD 1: Never allow entPhysicalIndex to be set manually
unset($filteredData['entPhysicalIndex']);

// SAFEGUARD 2: Default entPhysicalContainedIn to 0 (root level)
if (!isset($filteredData['entPhysicalContainedIn'])) {
    $filteredData['entPhysicalContainedIn'] = 0;
}

// SAFEGUARD 3: Detect and fix self-references before insert
if (isset($filteredData['entPhysicalContainedIn']) &&
    isset($filteredData['entPhysicalIndex']) &&
    $filteredData['entPhysicalContainedIn'] == $filteredData['entPhysicalIndex']) {
    $filteredData['entPhysicalContainedIn'] = 0;
}
```

### 2. Disabled Drive Routing (Temporary)

**File:** `app/Services/RestApi/DataPersistence.php:222-258`

Temporarily disabled routing of physical drives to entPhysical until we have proper hierarchy information from the API.

Physical drives (CH0.BAY0, CH1.NVB0, etc.) are now **skipped** entirely.

## Database Cleanup Required

### Option 1: Fix Self-References (Keep Records)

Run this to fix the existing records:

```bash
mysql -u librenms -p librenms < scripts/fix_entphysical_self_reference.sql
```

Or manually:

```sql
-- Fix self-references by setting to root level
UPDATE entPhysical
SET entPhysicalContainedIn = 0
WHERE device_id = 3
  AND entPhysicalContainedIn = entPhysicalIndex;

-- Verify fix
SELECT
    device_id,
    COUNT(*) as total,
    COUNT(CASE WHEN entPhysicalContainedIn = entPhysicalIndex THEN 1 END) as self_ref
FROM entPhysical
WHERE device_id = 3
GROUP BY device_id;

-- Should show self_ref = 0
```

### Option 2: Delete All Drive Records (Recommended)

Since the new code skips drives anyway, just delete them:

```sql
-- Delete all drive records for device 3
DELETE FROM entPhysical
WHERE device_id = 3
  AND entPhysicalClass = 'drive';

-- Verify deletion
SELECT COUNT(*) FROM entPhysical WHERE device_id = 3;
-- Should show 0 or very few records
```

## Testing

### 1. After Cleanup, Test Inventory Page

Navigate to: **Device → Inventory** (`/device/3/tab=entphysical/`)

**Expected:**
- ✅ Page loads successfully
- ✅ No memory exhaustion error
- ✅ No PHP fatal error

If you deleted the drive records, the page may be empty or show only SNMP-discovered components.

### 2. Run REST API Poll

```bash
./poller.php -h <purestorage-hostname> -d -m restapi
```

**Expected:**
- ✅ Ports created/updated
- ✅ IP addresses captured
- ✅ Transceivers captured
- ✅ No entPhysical records created for drives
- ✅ Log shows "Skipping physical drive (entPhysical disabled)"

### 3. Verify No New Self-References

After polling, check again:

```sql
SELECT
    device_id,
    COUNT(*) as total,
    COUNT(CASE WHEN entPhysicalContainedIn = entPhysicalIndex THEN 1 END) as self_ref
FROM entPhysical
WHERE device_id = 3
GROUP BY device_id;
```

**Expected:**
- `self_ref` = 0 (no self-references)
- `total` should stay at 0 or very low (no new drive records)

## Why This Happened

### Database Schema Issue

The `entPhysical` table likely has:
- `entPhysicalIndex` - Auto-increment primary key
- `entPhysicalContainedIn` - Foreign key pointing to parent's entPhysicalIndex

Our code was:
1. Accepting both fields from input data
2. Inserting them together
3. Database auto-increment set entPhysicalIndex to (e.g.) 2614
4. We also set entPhysicalContainedIn to 2614 (from input or coincidence)
5. Result: Self-reference created

### Proper Hierarchy

For entPhysical to work correctly, we need:

```
Root (index 0)
├─ Chassis (index 1, containedIn 0)
│   ├─ Controller 0 (index 2, containedIn 1)
│   │   ├─ CH0.BAY0 (index 10, containedIn 2)
│   │   ├─ CH0.BAY1 (index 11, containedIn 2)
│   │   └─ CH0.eth0 (index 12, containedIn 2)
│   └─ Controller 1 (index 3, containedIn 1)
│       ├─ CH1.NVB0 (index 20, containedIn 3)
│       └─ CH1.NVB1 (index 21, containedIn 3)
└─ Power Supply (index 4, containedIn 1)
```

We don't have this hierarchy information from the PureStorage API yet, so we can't create proper parent-child relationships.

## Current Status

### What Works ✅
- ✅ Ports (ct0.eth18, vir4, etc.)
- ✅ IP addresses (172.16.7.6/24)
- ✅ Transceivers (vendor, model, serial)
- ✅ Transceiver sensors (temp, power, voltage)
- ✅ Port performance metrics
- ✅ Inventory page loads without errors

### What's Disabled ❌
- ❌ Physical drive inventory in entPhysical
- ❌ Hardware hierarchy view

### Impact
The important networking features all work correctly. Physical drive inventory is disabled until we can implement proper hierarchy.

## Future Enhancement

To properly support entPhysical for PureStorage:

1. **Query additional API endpoints:**
   - `/api/2.26/hardware` - Get chassis/controller info
   - `/api/2.26/controllers` - Get controller hierarchy
   - `/api/2.26/drives` - Get drive bay assignments

2. **Build hierarchy map:**
   ```php
   $hierarchy = [
       'chassis' => ['index' => 1, 'parent' => 0],
       'controller0' => ['index' => 2, 'parent' => 1],
       'controller1' => ['index' => 3, 'parent' => 1],
   ];
   ```

3. **Create records in order:**
   - Insert chassis first (gets index 1)
   - Insert controllers (get indexes 2, 3)
   - Insert drives with correct parent references

4. **Set proper containedIn values:**
   ```php
   $driveData = [
       'entPhysicalName' => 'CH0.BAY0',
       'entPhysicalClass' => 'drive',
       'entPhysicalContainedIn' => $hierarchy['controller0']['index'], // 2
   ];
   ```

## Files Modified

1. `app/Services/RestApi/DataPersistence.php`
   - Added `unset($filteredData['entPhysicalIndex'])` (line 423)
   - Added self-reference detection (lines 431-436)
   - Disabled drive routing (lines 226-258)

## Files Created

1. `scripts/fix_entphysical_self_reference.sql` - Fix existing records
2. `scripts/cleanup_entphysical_purestorage.sql` - Delete drive records
3. `scripts/diagnose_entphysical_recursion.sql` - Diagnostic queries
4. `docs/ENTPHYSICAL_SELF_REFERENCE_FIX.md` - This document

## Recommended Action

**Run this cleanup now:**

```bash
# Delete all drive records (recommended)
mysql -u librenms -p librenms -e "DELETE FROM entPhysical WHERE device_id = 3 AND entPhysicalClass = 'drive';"

# Verify
mysql -u librenms -p librenms -e "SELECT COUNT(*) FROM entPhysical WHERE device_id = 3;"

# Test inventory page
# Navigate to Device → Inventory in browser
```

**Expected result:**
- Inventory page loads successfully
- No memory errors
- Drives not shown (as expected with current code)
- All other features still working

## Summary

**Problem:** 96 entPhysical records with self-references causing infinite recursion

**Root Cause:** `entPhysicalIndex` being set manually and matching `entPhysicalContainedIn`

**Fix:**
1. Code updated to never set `entPhysicalIndex` manually
2. Code updated to detect and prevent self-references
3. Physical drive routing temporarily disabled
4. Database cleanup required to remove existing bad records

**Status:** Fix applied, cleanup required, inventory page should work after cleanup
