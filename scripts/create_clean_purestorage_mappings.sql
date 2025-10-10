-- ============================================================================
-- PURE STORAGE CLEAN METRIC MAPPINGS
-- Only essential mappings for native LibreNMS tables
-- ============================================================================

-- =============================================================================
-- DEVICE TABLE (Array-level Info)
-- =============================================================================
INSERT INTO metric_field_mappings (metric_name, resource_type, librenms_table, librenms_field, data_type, unit, vendor, os, enabled, description) VALUES
-- Array identification
('name', 'array', 'devices', 'sysName', 'string', NULL, 'Pure Storage', 'purestorage', 1, 'Array name'),
('version', 'array', 'devices', 'version', 'string', NULL, 'Pure Storage', 'purestorage', 1, 'Purity version'),
('os', 'array', 'devices', 'version', 'string', NULL, 'Pure Storage', 'purestorage', 1, 'OS version'),

-- =============================================================================
-- STORAGE TABLE (Volumes/LUNs)
-- =============================================================================
-- Volume capacity
('provisioned', 'volume', 'storage', 'storage_size', 'numeric', 'bytes', 'Pure Storage', 'purestorage', 1, 'Volume provisioned size'),
('size', 'volume', 'storage', 'storage_size', 'numeric', 'bytes', 'Pure Storage', 'purestorage', 1, 'Volume size'),
('total_physical', 'volume', 'storage', 'storage_used', 'numeric', 'bytes', 'Pure Storage', 'purestorage', 1, 'Physical space used'),
('total_used', 'volume', 'storage', 'storage_used', 'numeric', 'bytes', 'Pure Storage', 'purestorage', 1, 'Total used space'),

-- =============================================================================
-- PORTS TABLE (Network Interfaces)
-- =============================================================================
-- Port identification and status
('name', 'port', 'ports', 'ifName', 'string', NULL, 'Pure Storage', 'purestorage', 1, 'Port name'),
('enabled', 'port', 'ports', 'ifAdminStatus', 'string', NULL, 'Pure Storage', 'purestorage', 1, 'Port admin status'),
('speed', 'port', 'ports', 'ifSpeed', 'numeric', 'bps', 'Pure Storage', 'purestorage', 1, 'Port speed'),

-- Port details
('address', 'port', 'ports', 'ifPhysAddress', 'string', NULL, 'Pure Storage', 'purestorage', 1, 'MAC address'),
('mac_address', 'port', 'ports', 'ifPhysAddress', 'string', NULL, 'Pure Storage', 'purestorage', 1, 'MAC address'),
('mtu', 'port', 'ports', 'ifMtu', 'numeric', 'bytes', 'Pure Storage', 'purestorage', 1, 'MTU size'),

-- =============================================================================
-- SENSORS TABLE (Performance & Hardware)
-- =============================================================================
-- Array-level performance
('read_bytes_per_sec', 'sensor', 'sensors', 'sensor_current', 'numeric', 'bytes/sec', 'Pure Storage', 'purestorage', 1, 'Read bandwidth'),
('write_bytes_per_sec', 'sensor', 'sensors', 'sensor_current', 'numeric', 'bytes/sec', 'Pure Storage', 'purestorage', 1, 'Write bandwidth'),
('reads_per_sec', 'sensor', 'sensors', 'sensor_current', 'numeric', 'iops', 'Pure Storage', 'purestorage', 1, 'Read IOPS'),
('writes_per_sec', 'sensor', 'sensors', 'sensor_current', 'numeric', 'iops', 'Pure Storage', 'purestorage', 1, 'Write IOPS'),
('usec_per_read_op', 'sensor', 'sensors', 'sensor_current', 'numeric', 'microseconds', 'Pure Storage', 'purestorage', 1, 'Read latency'),
('usec_per_write_op', 'sensor', 'sensors', 'sensor_current', 'numeric', 'microseconds', 'Pure Storage', 'purestorage', 1, 'Write latency'),

-- Hardware sensors
('temperature', 'sensor', 'sensors', 'sensor_current', 'numeric', 'celsius', 'Pure Storage', 'purestorage', 1, 'Temperature'),
('voltage', 'sensor', 'sensors', 'sensor_current', 'numeric', 'volts', 'Pure Storage', 'purestorage', 1, 'Voltage'),

-- Network performance
('received_bytes_per_sec', 'port', 'sensors', 'sensor_current', 'numeric', 'bytes/sec', 'Pure Storage', 'purestorage', 1, 'RX bandwidth'),
('transmitted_bytes_per_sec', 'port', 'sensors', 'sensor_current', 'numeric', 'bytes/sec', 'Pure Storage', 'purestorage', 1, 'TX bandwidth');

-- Verification
SELECT 
    librenms_table,
    COUNT(*) as mapping_count,
    GROUP_CONCAT(DISTINCT resource_type) as resource_types
FROM metric_field_mappings
WHERE os = 'purestorage'
GROUP BY librenms_table
ORDER BY librenms_table;

SELECT 'Total mappings created:' as info, COUNT(*) as count 
FROM metric_field_mappings 
WHERE os = 'purestorage';
