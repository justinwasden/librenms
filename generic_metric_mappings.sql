-- Generic REST API Metric Mappings (Works for ALL vendors/devices)
-- Run this SQL to create universal mappings for common REST API metrics

INSERT INTO metric_field_mappings 
(metric_name, resource_type, vendor, os, librenms_table, librenms_field, data_type, unit, multiplier, enabled, auto_learned, description, created_at, updated_at)
VALUES
-- Volume capacity metrics mapped to sensors (generic - works for any storage)
('volume_provisioned', 'unknown', NULL, NULL, 'sensors', 'sensor_current', 'numeric', 'bytes', 1.0, 1, 0, 'Provisioned capacity of the volume', NOW(), NOW()),
('volume_data_reduction', 'unknown', NULL, NULL, 'sensors', 'sensor_current', 'numeric', 'ratio', 1.0, 1, 0, 'Data reduction ratio for the volume', NOW(), NOW()),
('volume_total_reduction', 'unknown', NULL, NULL, 'sensors', 'sensor_current', 'numeric', 'ratio', 1.0, 1, 0, 'Total reduction ratio for the volume', NOW(), NOW()),
('volume_snapshots', 'unknown', NULL, NULL, 'sensors', 'sensor_current', 'numeric', 'bytes', 1.0, 1, 0, 'Snapshot size for the volume', NOW(), NOW()),

-- Volume metadata - string values for informational purposes
('volume_name', 'unknown', NULL, NULL, 'sensors', 'sensor_descr', 'string', NULL, 1.0, 1, 0, 'Volume name', NOW(), NOW()),
('volume_serial', 'unknown', NULL, NULL, 'sensors', 'sensor_descr', 'string', NULL, 1.0, 1, 0, 'Volume serial number', NOW(), NOW()),
('volume_created', 'unknown', NULL, NULL, 'sensors', 'sensor_descr', 'string', NULL, 1.0, 1, 0, 'Volume creation timestamp', NOW(), NOW()),
('volume_group', 'unknown', NULL, NULL, 'sensors', 'sensor_descr', 'string', NULL, 1.0, 1, 0, 'Volume group', NOW(), NOW()),

-- Volume connection count
('volume_connections', 'unknown', NULL, NULL, 'sensors', 'sensor_current', 'numeric', 'count', 1.0, 1, 0, 'Number of connections to the volume', NOW(), NOW()),

-- Array-level metrics
('array_capacity', 'unknown', NULL, NULL, 'devices', 'storage_total', 'numeric', 'bytes', 1.0, 1, 0, 'Total array capacity', NOW(), NOW()),
('array_used', 'unknown', NULL, NULL, 'devices', 'storage_used', 'numeric', 'bytes', 1.0, 1, 0, 'Used array capacity', NOW(), NOW()),
('array_data_reduction', 'unknown', NULL, NULL, 'sensors', 'sensor_current', 'numeric', 'ratio', 1.0, 1, 0, 'Array-wide data reduction ratio', NOW(), NOW()),

-- Controller metrics
('controller_temperature', 'unknown', NULL, NULL, 'sensors', 'sensor_current', 'numeric', 'celsius', 1.0, 1, 0, 'Controller temperature', NOW(), NOW()),
('controller_status', 'unknown', NULL, NULL, 'sensors', 'sensor_current', 'string', NULL, 1.0, 1, 0, 'Controller status', NOW(), NOW()),

-- Generic interface/port metrics
('ifName', 'unknown', NULL, NULL, 'ports', 'ifName', 'string', NULL, 1.0, 1, 0, 'Interface name', NOW(), NOW()),
('ifSpeed', 'unknown', NULL, NULL, 'ports', 'ifSpeed', 'numeric', 'bps', 1.0, 1, 0, 'Port speed', NOW(), NOW()),
('ifOperStatus', 'unknown', NULL, NULL, 'ports', 'ifOperStatus', 'string', NULL, 1.0, 1, 0, 'Port operational status', NOW(), NOW()),
('ifAdminStatus', 'unknown', NULL, NULL, 'ports', 'ifAdminStatus', 'string', NULL, 1.0, 1, 0, 'Port admin status', NOW(), NOW()),
('ifMtu', 'unknown', NULL, NULL, 'ports', 'ifMtu', 'numeric', 'bytes', 1.0, 1, 0, 'Interface MTU', NOW(), NOW()),
('port_speed', 'unknown', NULL, NULL, 'ports', 'ifSpeed', 'numeric', 'bps', 1.0, 1, 0, 'Port speed', NOW(), NOW()),
('port_status', 'unknown', NULL, NULL, 'ports', 'ifOperStatus', 'string', NULL, 1.0, 1, 0, 'Port operational status', NOW(), NOW()),

-- Performance metrics
('iops', 'unknown', NULL, NULL, 'sensors', 'sensor_current', 'numeric', 'iops', 1.0, 1, 0, 'I/O operations per second', NOW(), NOW()),
('bandwidth', 'unknown', NULL, NULL, 'sensors', 'sensor_current', 'numeric', 'bps', 1.0, 1, 0, 'Bandwidth usage', NOW(), NOW()),
('latency', 'unknown', NULL, NULL, 'sensors', 'sensor_current', 'numeric', 'microseconds', 1.0, 1, 0, 'Average latency', NOW(), NOW()),

-- Generic temperature/power/fan metrics
('temperature', 'unknown', NULL, NULL, 'sensors', 'sensor_current', 'numeric', 'celsius', 1.0, 1, 0, 'Temperature', NOW(), NOW()),
('power', 'unknown', NULL, NULL, 'sensors', 'sensor_current', 'numeric', 'watts', 1.0, 1, 0, 'Power consumption', NOW(), NOW()),
('fan_speed', 'unknown', NULL, NULL, 'sensors', 'sensor_current', 'numeric', 'rpm', 1.0, 1, 0, 'Fan speed', NOW(), NOW()),
('voltage', 'unknown', NULL, NULL, 'sensors', 'sensor_current', 'numeric', 'volts', 1.0, 1, 0, 'Voltage', NOW(), NOW()),

-- Device-level metrics
('uptime', 'unknown', NULL, NULL, 'devices', 'uptime', 'numeric', 'seconds', 1.0, 1, 0, 'System uptime', NOW(), NOW()),
('serial', 'unknown', NULL, NULL, 'devices', 'serial', 'string', NULL, 1.0, 1, 0, 'Serial number', NOW(), NOW()),
('hardware', 'unknown', NULL, NULL, 'devices', 'hardware', 'string', NULL, 1.0, 1, 0, 'Hardware model', NOW(), NOW()),
('version', 'unknown', NULL, NULL, 'devices', 'version', 'string', NULL, 1.0, 1, 0, 'Firmware/OS version', NOW(), NOW()),
('status', 'unknown', NULL, NULL, 'devices', 'status', 'numeric', NULL, 1.0, 1, 0, 'Device status', NOW(), NOW())

ON DUPLICATE KEY UPDATE
    librenms_table = VALUES(librenms_table),
    librenms_field = VALUES(librenms_field),
    data_type = VALUES(data_type),
    unit = VALUES(unit),
    enabled = VALUES(enabled),
    description = VALUES(description),
    vendor = NULL,
    os = NULL,
    updated_at = NOW();
