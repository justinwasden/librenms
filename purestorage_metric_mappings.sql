-- PureStorage Volume Metrics Mappings
-- Run this SQL to create mappings for PureStorage volume metrics

INSERT INTO metric_field_mappings 
(metric_name, resource_type, vendor, os, librenms_table, librenms_field, data_type, unit, multiplier, enabled, auto_learned, description, created_at, updated_at)
VALUES
-- Volume capacity metrics mapped to sensors
('volume_provisioned', 'volume', 'PureStorage', 'Purity', 'sensors', 'sensor_current', 'numeric', 'bytes', 1.0, 1, 0, 'Provisioned capacity of the volume', NOW(), NOW()),
('volume_data_reduction', 'volume', 'PureStorage', 'Purity', 'sensors', 'sensor_current', 'numeric', 'ratio', 1.0, 1, 0, 'Data reduction ratio for the volume', NOW(), NOW()),
('volume_total_reduction', 'volume', 'PureStorage', 'Purity', 'sensors', 'sensor_current', 'numeric', 'ratio', 1.0, 1, 0, 'Total reduction ratio for the volume', NOW(), NOW()),
('volume_snapshots', 'volume', 'PureStorage', 'Purity', 'sensors', 'sensor_current', 'numeric', 'bytes', 1.0, 1, 0, 'Snapshot size for the volume', NOW(), NOW()),

-- Volume metadata - string values for informational purposes
('volume_name', 'volume', 'PureStorage', 'Purity', 'sensors', 'sensor_descr', 'string', NULL, 1.0, 1, 0, 'Volume name', NOW(), NOW()),
('volume_serial', 'volume', 'PureStorage', 'Purity', 'sensors', 'sensor_descr', 'string', NULL, 1.0, 1, 0, 'Volume serial number', NOW(), NOW()),
('volume_created', 'volume', 'PureStorage', 'Purity', 'sensors', 'sensor_descr', 'string', NULL, 1.0, 1, 0, 'Volume creation timestamp', NOW(), NOW()),

-- Volume connection count
('volume_connections', 'volume', 'PureStorage', 'Purity', 'sensors', 'sensor_current', 'numeric', 'count', 1.0, 1, 0, 'Number of connections to the volume', NOW(), NOW()),

-- Array-level metrics
('array_capacity', 'array', 'PureStorage', 'Purity', 'devices', 'storage_total', 'numeric', 'bytes', 1.0, 1, 0, 'Total array capacity', NOW(), NOW()),
('array_used', 'array', 'PureStorage', 'Purity', 'devices', 'storage_used', 'numeric', 'bytes', 1.0, 1, 0, 'Used array capacity', NOW(), NOW()),
('array_data_reduction', 'array', 'PureStorage', 'Purity', 'sensors', 'sensor_current', 'numeric', 'ratio', 1.0, 1, 0, 'Array-wide data reduction ratio', NOW(), NOW()),

-- Controller metrics
('controller_temperature', 'controller', 'PureStorage', 'Purity', 'sensors', 'sensor_current', 'numeric', 'celsius', 1.0, 1, 0, 'Controller temperature', NOW(), NOW()),
('controller_status', 'controller', 'PureStorage', 'Purity', 'sensors', 'sensor_current', 'string', NULL, 1.0, 1, 0, 'Controller status', NOW(), NOW()),

-- Port/Interface metrics
('port_speed', 'port', 'PureStorage', 'Purity', 'ports', 'ifSpeed', 'numeric', 'bps', 1.0, 1, 0, 'Port speed', NOW(), NOW()),
('port_status', 'port', 'PureStorage', 'Purity', 'ports', 'ifOperStatus', 'string', NULL, 1.0, 1, 0, 'Port operational status', NOW(), NOW()),

-- Performance metrics
('iops', 'volume', 'PureStorage', 'Purity', 'sensors', 'sensor_current', 'numeric', 'iops', 1.0, 1, 0, 'I/O operations per second', NOW(), NOW()),
('bandwidth', 'volume', 'PureStorage', 'Purity', 'sensors', 'sensor_current', 'numeric', 'bps', 1.0, 1, 0, 'Bandwidth usage', NOW(), NOW()),
('latency', 'volume', 'PureStorage', 'Purity', 'sensors', 'sensor_current', 'numeric', 'microseconds', 1.0, 1, 0, 'Average latency', NOW(), NOW())

ON DUPLICATE KEY UPDATE
    librenms_table = VALUES(librenms_table),
    librenms_field = VALUES(librenms_field),
    data_type = VALUES(data_type),
    unit = VALUES(unit),
    enabled = VALUES(enabled),
    description = VALUES(description),
    updated_at = NOW();
