-- Clean up PureStorage entPhysical records causing memory exhaustion
-- This removes drive bay records that were causing infinite recursion

-- Show what will be deleted (review first)
SELECT
    entPhysical_id,
    device_id,
    entPhysicalName,
    entPhysicalClass,
    entPhysicalDescr,
    entPhysicalIndex,
    entPhysicalContainedIn
FROM entPhysical
WHERE entPhysicalName REGEXP '\.(BAY|NVB|SSD|HDD|NVME)[0-9]+'
   OR entPhysicalName REGEXP '^(CH[0-9]+|SH[0-9]+)\.(BAY|NVB)';

-- Delete the problematic records
-- Uncomment and run after reviewing the SELECT results above
DELETE FROM entPhysical
WHERE entPhysicalName REGEXP '\.(BAY|NVB|SSD|HDD|NVME)[0-9]+'
   OR entPhysicalName REGEXP '^(CH[0-9]+|SH[0-9]+)\.(BAY|NVB)';

-- Verify they're gone
SELECT COUNT(*) as remaining_drive_records
FROM entPhysical
WHERE entPhysicalName REGEXP '\.(BAY|NVB|SSD|HDD|NVME)[0-9]+'
   OR entPhysicalName REGEXP '^(CH[0-9]+|SH[0-9]+)\.(BAY|NVB)';
