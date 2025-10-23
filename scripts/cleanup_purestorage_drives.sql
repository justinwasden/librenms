-- Cleanup: Move physical drives from storage table to entPhysical table
-- PureStorage physical drives (.BAY, .NVB) were incorrectly stored in storage table
-- They should be in entPhysical as hardware components

-- First, let's see what we're moving
SELECT
    device_id,
    storage_descr,
    storage_size,
    storage_type
FROM storage
WHERE storage_descr REGEXP '\.(BAY|NVB|SSD|HDD|NVME)[0-9]+$'
   OR storage_descr REGEXP '^(CH[0-9]+|SH[0-9]+)\.(BAY|NVB)';

-- Insert physical drives into entPhysical table
INSERT INTO entPhysical (
    device_id,
    entPhysicalName,
    entPhysicalClass,
    entPhysicalDescr,
    entPhysicalModelName
)
SELECT
    device_id,
    storage_descr AS entPhysicalName,
    'drive' AS entPhysicalClass,
    storage_type AS entPhysicalDescr,
    CONCAT(storage_type, ' - ', ROUND(storage_size / 1024 / 1024 / 1024 / 1024, 2), ' TB') AS entPhysicalModelName
FROM storage
WHERE (storage_descr REGEXP '\.(BAY|NVB|SSD|HDD|NVME)[0-9]+$'
       OR storage_descr REGEXP '^(CH[0-9]+|SH[0-9]+)\.(BAY|NVB)')
  AND NOT EXISTS (
      SELECT 1 FROM entPhysical e
      WHERE e.device_id = storage.device_id
        AND e.entPhysicalName = storage.storage_descr
  );

-- Delete physical drives from storage table
DELETE FROM storage
WHERE storage_descr REGEXP '\.(BAY|NVB|SSD|HDD|NVME)[0-9]+$'
   OR storage_descr REGEXP '^(CH[0-9]+|SH[0-9]+)\.(BAY|NVB)';

-- Verify the cleanup
SELECT COUNT(*) as remaining_drive_bays
FROM storage
WHERE storage_descr REGEXP '\.(BAY|NVB)';

SELECT COUNT(*) as drives_in_entPhysical
FROM entPhysical
WHERE entPhysicalClass = 'drive'
  AND entPhysicalName REGEXP '\.(BAY|NVB)';
