-- Fix Pure Storage Metric Field Mappings
-- This script removes bad mappings and creates correct ones for native LibreNMS tables

-- =============================================================================
-- STEP 1: DELETE ALL EXISTING PURE STORAGE MAPPINGS
-- =============================================================================
DELETE FROM metric_field_mappings WHERE os = 'purestorage' OR vendor = 'Pure Storage';

-- =============================================================================
-- STEP 2: DEVICE-LEVEL MAPPINGS (Array Info)
-- =============================================================================

-- Array identification and version info
INSERT INTO metric_field_mappings (metric_name, resource_type, librenms_table, librenms_field, data_type, unit, vendor, os, enabled, description) VALUES
('array_name', 'array', 'devices', 'hostname', 'string', NULL, 'Pure Storage', 'purestorage', 1, 'Storage array hostname'),
('name', 'array', 'devices', 'sysName', 'string', NULL, 'Pure Storage', 'purestorage', 1, 'Array name'),
('version', 'array', 'devices', 'version', 'string', NULL, 'Pure Storage', 'purestorage', 1, 'Purity version'),
('os', 'array', 'devices', 'version', 'string', NULL, 'Pure Storage', 'purestorage', 1, 'OS version'),
('model', 'array', 'devices', 'hardware', 'string', NULL, 'Pure Storage', 'purestorage', 1, 'Array model'),
('hardware', 'array', 'devices', 'hardware', 'string', NULL, 'Pure Storage', 'purestorage', 1, 'Hardware model'),
('serial', 'array', 'devices', 'serial', 'string', NULL, 'Pure Storage', 'purestorage', 1, 'Array serial number'),

-- Array status
INSERT INTO metric_field_mappings (metric_name, resource_type, librenms_table, librenms_field, data_type, unit, vendor, os, enabled, description) VALUES
('status', 'array', 'devices', 'status', 'numeric', NULL, 'Pure Storage', 'purestorage', 1, 'Array status (1=up, 0=down)'),

-- Array uptime
INSERT INTO metric_field_mappings (metric_name, resource_type, librenms_table, librenms_field, data_type, unit, vendor, os, enabled, description) VALUES
('uptime', 'array', 'devices', 'uptime', 'numeric', 'seconds', 'Pure Storage', 'purestorage', 1, 'Array uptime in seconds');

-- =============================================================================
-- STEP 3: STORAGE MAPPINGS (Volumes/LUNs)
-- =============================================================================

-- Volume capacity metrics
INSERT INTO metric_field_mappings (metric_name, resource_type, librenms_table, librenms_field, data_type, unit, vendor, os, enabled, description) VALUES
('provisioned', 'volume', 'storage', 'storage_size', 'numeric', 'bytes', 'Pure Storage', 'purestorage', 1, 'Volume provisioned size'),
('size', 'volume', 'storage', 'storage_size', 'numeric', 'bytes', 'Pure Storage', 'purestorage', 1, 'Volume size'),
('space_total_provisioned', 'volume', 'storage', 'storage_size', 'numeric', 'bytes', 'Pure Storage', 'purestorage', 1, 'Total provisioned space'),
('space_total_physical', 'array', 'storage', 'storage_size', 'numeric', 'bytes', 'Pure Storage', 'purestorage', 1, 'Total physical capacity'),

-- Volume usage metrics
('used', 'volume', 'storage', 'storage_used', 'numeric', 'bytes', 'Pure Storage', 'purestorage', 1, 'Volume used space'),
('space_total_used', 'volume', 'storage', 'storage_used', 'numeric', 'bytes', 'Pure Storage', 'purestorage', 1, 'Total used space'),
('total_physical', 'volume', 'storage', 'storage_used', 'numeric', 'bytes', 'Pure Storage', 'purestorage', 1, 'Total physical used'),

-- Volume names/descriptions
('name', 'volume', 'storage', 'storage_descr', 'string', NULL, 'Pure Storage', 'purestorage', 1, 'Volume name'),
('serial', 'volume', 'storage', 'storage_descr', 'string', NULL, 'Pure Storage', 'purestorage', 1, 'Volume serial'),

-- Data reduction ratios (use storage_perc for percentage)
('data_reduction', 'volume', 'storage', 'storage_perc', 'numeric', 'ratio', 'Pure Storage', 'purestorage', 1, 'Volume data reduction ratio'),
('total_reduction', 'volume', 'storage', 'storage_perc', 'numeric', 'ratio', 'Pure Storage', 'purestorage', 1, 'Total reduction ratio');

-- =============================================================================
-- STEP 4: PORT/INTERFACE MAPPINGS
-- =============================================================================

INSERT INTO metric_field_mappings (metric_name, resource_type, librenms_table, librenms_field, data_type, unit, vendor, os, enabled, description) VALUES
-- Port identification
('name', 'port', 'ports', 'ifName', 'string', NULL, 'Pure Storage', 'purestorage', 1, 'Port name'),
('ifName', 'port', 'ports', 'ifName', 'string', NULL, 'Pure Storage', 'purestorage', 1, 'Interface name'),

-- Port status
('enabled', 'port', 'ports', 'ifAdminStatus', 'string', NULL, 'Pure Storage', 'purestorage', 1, 'Port admin status'),
('status', 'port', 'ports', 'ifOperStatus', 'string', NULL, 'Pure Storage', 'purestorage', 1, 'Port operational status'),
('ifAdminStatus', 'port', 'ports', 'ifAdminStatus', 'string', NULL, 'Pure Storage', 'purestorage', 1, 'Admin status'),
('ifOperStatus', 'port', 'ports', 'ifOperStatus', 'string', NULL, 'Pure Storage', 'purestorage', 1, 'Operational status'),

-- Port speed
('speed', 'port', 'ports', 'ifSpeed', 'numeric', 'bps', 'Pure Storage', 'purestorage', 1, 'Port speed in bps'),
('ifSpeed', 'port', 'ports', 'ifSpeed', 'numeric', 'bps', 'Pure Storage', 'purestorage', 1, 'Interface speed'),

-- Port details
('mtu', 'port', 'ports', 'ifMtu', 'numeric', 'bytes', 'Pure Storage', 'purestorage', 1, 'MTU size'),
('ifMtu', 'port', 'ports', 'ifMtu', 'numeric', 'bytes', 'Pure Storage', 'purestorage', 1, 'Interface MTU'),
('address', 'port', 'ports', 'ifPhysAddress', 'string', NULL, 'Pure Storage', 'purestorage', 1, 'MAC address'),
('wwn', 'port', 'ports', 'ifPhysAddress', 'string', NULL, 'Pure Storage', 'purestorage', 1, 'WWN address');

-- =============================================================================
-- STEP 5: SENSOR MAPPINGS (Performance Metrics)
-- =============================================================================

INSERT INTO metric_field_mappings (metric_name, resource_type, librenms_table, librenms_field, data_type, unit, vendor, os, enabled, description) VALUES
-- Temperature sensors
('temperature', 'sensor', 'sensors', 'sensor_current', 'numeric', 'celsius', 'Pure Storage', 'purestorage', 1, 'Temperature reading'),

-- Performance metrics - IOPS
('iops', 'sensor', 'sensors', 'sensor_current', 'numeric', 'iops', 'Pure Storage', 'purestorage', 1, 'Total IOPS'),
('reads_per_sec', 'sensor', 'sensors', 'sensor_current', 'numeric', 'iops', 'Pure Storage', 'purestorage', 1, 'Read IOPS'),
('writes_per_sec', 'sensor', 'sensors', 'sensor_current', 'numeric', 'iops', 'Pure Storage', 'purestorage', 1, 'Write IOPS'),

-- Performance metrics - Bandwidth
('bandwidth', 'sensor', 'sensors', 'sensor_current', 'numeric', 'bps', 'Pure Storage', 'purestorage', 1, 'Total bandwidth'),
('read_bandwidth', 'sensor', 'sensors', 'sensor_current', 'numeric', 'bps', 'Pure Storage', 'purestorage', 1, 'Read bandwidth'),
('write_bandwidth', 'sensor', 'sensors', 'sensor_current', 'numeric', 'bps', 'Pure Storage', 'purestorage', 1, 'Write bandwidth'),
('read_bytes_per_sec', 'sensor', 'sensors', 'sensor_current', 'numeric', 'bytes/sec', 'Pure Storage', 'purestorage', 1, 'Bytes read per second'),
('write_bytes_per_sec', 'sensor', 'sensors', 'sensor_current', 'numeric', 'bytes/sec', 'Pure Storage', 'purestorage', 1, 'Bytes written per second'),

-- Performance metrics - Latency
('latency', 'sensor', 'sensors', 'sensor_current', 'numeric', 'microseconds', 'Pure Storage', 'purestorage', 1, 'Average latency'),
('usec_per_read_op', 'sensor', 'sensors', 'sensor_current', 'numeric', 'microseconds', 'Pure Storage', 'purestorage', 1, 'Read latency'),
('usec_per_write_op', 'sensor', 'sensors', 'sensor_current', 'numeric', 'microseconds', 'Pure Storage', 'purestorage', 1, 'Write latency'),

-- Hardware sensors
('voltage', 'sensor', 'sensors', 'sensor_current', 'numeric', 'volts', 'Pure Storage', 'purestorage', 1, 'Voltage reading'),
('power', 'sensor', 'sensors', 'sensor_current', 'numeric', 'watts', 'Pure Storage', 'purestorage', 1, 'Power consumption'),
('fan_speed', 'sensor', 'sensors', 'sensor_current', 'numeric', 'rpm', 'Pure Storage', 'purestorage', 1, 'Fan speed');

-- =============================================================================
-- VERIFICATION QUERIES
-- =============================================================================

-- Count mappings by table
SELECT 
    librenms_table, 
    COUNT(*) as mapping_count,
    GROUP_CONCAT(DISTINCT resource_type) as resource_types
FROM metric_field_mappings 
WHERE os = 'purestorage' OR vendor = 'Pure Storage'
GROUP BY librenms_table;

-- Show all new mappings
SELECT 
    metric_name, 
    resource_type, 
    librenms_table, 
    librenms_field, 
    data_type, 
    unit 
FROM metric_field_mappings 
WHERE os = 'purestorage' OR vendor = 'Pure Storage'
ORDER BY librenms_table, resource_type, metric_name;
