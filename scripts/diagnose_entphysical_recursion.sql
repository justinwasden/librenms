-- Diagnose entPhysical Recursion Issues
-- Run this to find problematic records causing infinite loops

-- 1. Find records where entPhysicalContainedIn = entPhysicalIndex (self-reference)
SELECT
    entPhysical_id,
    device_id,
    entPhysicalName,
    entPhysicalIndex,
    entPhysicalContainedIn,
    'SELF-REFERENCE' as issue
FROM entPhysical
WHERE entPhysicalContainedIn = entPhysicalIndex
  AND entPhysicalContainedIn IS NOT NULL;

-- 2. Find records with NULL entPhysicalContainedIn
SELECT
    entPhysical_id,
    device_id,
    entPhysicalName,
    entPhysicalIndex,
    entPhysicalContainedIn,
    'NULL CONTAINER' as issue
FROM entPhysical
WHERE entPhysicalContainedIn IS NULL;

-- 3. Find records where entPhysicalContainedIn points to non-existent parent
SELECT
    e1.entPhysical_id,
    e1.device_id,
    e1.entPhysicalName,
    e1.entPhysicalIndex,
    e1.entPhysicalContainedIn,
    'ORPHAN (parent does not exist)' as issue
FROM entPhysical e1
LEFT JOIN entPhysical e2
    ON e1.device_id = e2.device_id
    AND e1.entPhysicalContainedIn = e2.entPhysicalIndex
WHERE e1.entPhysicalContainedIn IS NOT NULL
  AND e1.entPhysicalContainedIn != 0
  AND e2.entPhysicalIndex IS NULL;

-- 4. Find circular references (A → B → A)
SELECT
    e1.entPhysical_id as id1,
    e1.entPhysicalName as name1,
    e1.entPhysicalIndex as index1,
    e1.entPhysicalContainedIn as container1,
    e2.entPhysical_id as id2,
    e2.entPhysicalName as name2,
    e2.entPhysicalIndex as index2,
    e2.entPhysicalContainedIn as container2,
    'CIRCULAR REFERENCE' as issue
FROM entPhysical e1
JOIN entPhysical e2
    ON e1.device_id = e2.device_id
    AND e1.entPhysicalContainedIn = e2.entPhysicalIndex
WHERE e2.entPhysicalContainedIn = e1.entPhysicalIndex
  AND e1.entPhysicalIndex != e2.entPhysicalIndex;

-- 5. Count total records per device
SELECT
    device_id,
    COUNT(*) as total_records,
    COUNT(CASE WHEN entPhysicalContainedIn IS NULL THEN 1 END) as null_container,
    COUNT(CASE WHEN entPhysicalContainedIn = 0 THEN 1 END) as root_level,
    COUNT(CASE WHEN entPhysicalContainedIn = entPhysicalIndex THEN 1 END) as self_reference
FROM entPhysical
GROUP BY device_id
ORDER BY total_records DESC;

-- 6. Show all entPhysical records for PureStorage devices (likely device_id 2 or 3)
-- Adjust device_id as needed
SELECT
    entPhysical_id,
    entPhysicalName,
    entPhysicalClass,
    entPhysicalIndex,
    entPhysicalContainedIn,
    entPhysicalDescr
FROM entPhysical
WHERE device_id IN (2, 3)  -- Adjust device IDs as needed
ORDER BY entPhysicalIndex;

-- FIX QUERY: Run this if you find problematic records
-- Uncomment and run after reviewing the diagnostic results above

-- Fix NULL containers
-- UPDATE entPhysical
-- SET entPhysicalContainedIn = 0
-- WHERE entPhysicalContainedIn IS NULL;

-- Fix self-references
-- UPDATE entPhysical
-- SET entPhysicalContainedIn = 0
-- WHERE entPhysicalContainedIn = entPhysicalIndex;

-- Optionally: Delete all REST API created entPhysical records and let them be recreated
-- DELETE FROM entPhysical
-- WHERE device_id = 2
--   AND entPhysicalName LIKE 'CH%.%';
