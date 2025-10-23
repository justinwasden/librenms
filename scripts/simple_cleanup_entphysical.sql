-- Simple cleanup: Just delete the problematic drive records
-- This is the safest approach

-- Step 1: See what will be deleted
SELECT
    entPhysical_id,
    device_id,
    entPhysicalName,
    entPhysicalClass,
    entPhysicalIndex,
    entPhysicalContainedIn
FROM entPhysical
WHERE device_id = 3
  AND entPhysicalClass = 'drive';

-- Step 2: Delete them
DELETE FROM entPhysical
WHERE device_id = 3
  AND entPhysicalClass = 'drive';

-- Step 3: Verify deletion
SELECT
    device_id,
    COUNT(*) as remaining_records
FROM entPhysical
WHERE device_id = 3
GROUP BY device_id;

-- Should show 0 or very few remaining records
