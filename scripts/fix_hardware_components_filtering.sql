-- Fix entPhysicalIndex column to support larger values
-- Current error: Out of range value for column 'entPhysicalIndex'

USE librenms;

-- Check current column type
SELECT COLUMN_TYPE 
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = 'librenms' 
  AND TABLE_NAME = 'entPhysical' 
  AND COLUMN_NAME = 'entPhysicalIndex';

-- If it's INT, change to BIGINT to support larger hash values
ALTER TABLE entPhysical 
MODIFY COLUMN entPhysicalIndex BIGINT UNSIGNED;

-- Verify the change
SELECT COLUMN_TYPE 
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = 'librenms' 
  AND TABLE_NAME = 'entPhysical' 
  AND COLUMN_NAME = 'entPhysicalIndex';

SELECT 'entPhysicalIndex column fixed - now supports large hash values' AS status;
