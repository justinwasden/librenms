-- ============================================================================
-- CLEANUP OLD REST API TABLES AND DATA
-- This removes all legacy custom tables and prepares for the new clean design
-- ============================================================================

-- Drop old custom storage tables
DROP TABLE IF EXISTS storage_array_hosts;
DROP TABLE IF EXISTS storage_array_volumes;
DROP TABLE IF EXISTS storage_controllers;
DROP TABLE IF EXISTS storage_arrays;

-- Clear the fallback metrics table (we'll rebuild with proper data)
TRUNCATE TABLE rest_api_metrics;

-- Remove all old/incorrect mappings
DELETE FROM metric_field_mappings WHERE os = 'purestorage' OR vendor = 'Pure Storage';

-- Clean up any REST API data in native tables (we'll repopulate correctly)
DELETE FROM storage WHERE device_id IN (SELECT device_id FROM devices WHERE os = 'purestorage') AND storage_type = 'rest-api';
DELETE FROM ports WHERE device_id IN (SELECT device_id FROM devices WHERE os = 'purestorage') AND port_descr_type = 'rest-api';
DELETE FROM sensors WHERE device_id IN (SELECT device_id FROM devices WHERE os = 'purestorage') AND sensor_type = 'rest-api';

-- Verification queries
SELECT 'Cleanup complete!' as status;
SELECT 'Remaining tables:' as info;
SHOW TABLES LIKE 'storage%';
SELECT 'Remaining metrics:' as info, COUNT(*) as count FROM rest_api_metrics;
