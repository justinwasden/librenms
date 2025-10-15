-- Fix entPhysicalIndex column and verify cleanup
USE librenms;

-- 1. Fix the column type to support large hash values
ALTER TABLE entPhysical 
MODIFY COLUMN entPhysicalIndex BIGINT UNSIGNED;

SELECT 'entPhysicalIndex column changed to BIGINT UNSIGNED' AS status;

-- 2. Verify no network interfaces in entPhysical
SELECT 
  COUNT(*) as network_interfaces_in_entPhysical
FROM entPhysical
WHERE device_id IN (SELECT device_id FROM devices WHERE os = 'purestorage')
AND (entPhysicalDescr REGEXP '^CT[0-9]\\.ETH' OR entPhysicalDescr LIKE 'vir%');

-- 3. Verify no storage entries
SELECT 
  COUNT(*) as storage_entries
FROM storage
WHERE device_id IN (SELECT device_id FROM devices WHERE os = 'purestorage')
AND storage_type = 'rest-api';

-- 4. Show what's in ports table
SELECT 
  COUNT(*) as port_count
FROM ports
WHERE device_id IN (SELECT device_id FROM devices WHERE os = 'purestorage');

SELECT 'Fix complete! Wait 5 minutes for poller to create ports.' AS final_status;
