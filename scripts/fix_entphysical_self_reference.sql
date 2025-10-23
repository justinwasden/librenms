-- Fix entPhysical Self-Reference Issue
-- Problem: entPhysicalContainedIn = entPhysicalIndex (96 records)
-- This causes infinite recursion on inventory page

-- Step 1: Show the problematic records
SELECT
    entPhysical_id,
    device_id,
    entPhysicalName,
    entPhysicalIndex,
    entPhysicalContainedIn,
    'SELF-REFERENCE' as issue
FROM entPhysical
WHERE device_id = 3
  AND entPhysicalContainedIn = entPhysicalIndex;

-- Step 2: Fix self-references by setting container to 0 (root level)
UPDATE entPhysical
SET entPhysicalContainedIn = 0
WHERE device_id = 3
  AND entPhysicalContainedIn = entPhysicalIndex;

-- Step 3: Verify the fix
SELECT
    device_id,
    COUNT(*) as total_records,
    COUNT(CASE WHEN entPhysicalContainedIn IS NULL THEN 1 END) as null_container,
    COUNT(CASE WHEN entPhysicalContainedIn = 0 THEN 1 END) as root_level,
    COUNT(CASE WHEN entPhysicalContainedIn = entPhysicalIndex THEN 1 END) as self_reference
FROM entPhysical
WHERE device_id = 3
GROUP BY device_id;

-- Expected result after fix:
-- | device_id | total_records | null_container | root_level | self_reference |
-- |         3 |            96 |              0 |         96 |              0 |

-- Step 4: Optionally delete drive records entirely (as per new code behavior)
-- Uncomment if you want to remove them completely
-- DELETE FROM entPhysical
-- WHERE device_id = 3
--   AND entPhysicalClass = 'drive';
