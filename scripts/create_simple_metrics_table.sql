-- ============================================================================
-- CREATE NEW SIMPLE STORAGE ARRAY METRICS TABLE
-- This single table stores Pure Storage-specific metrics that don't fit native tables
-- ============================================================================

CREATE TABLE IF NOT EXISTS storage_array_metrics (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    device_id INT(10) UNSIGNED NOT NULL,
    metric_type ENUM('space_accounting', 'data_reduction', 'host_connectivity', 'replication') NOT NULL,
    metric_name VARCHAR(100) NOT NULL,
    metric_value JSON NOT NULL,
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (device_id) REFERENCES devices(device_id) ON DELETE CASCADE,
    UNIQUE KEY idx_device_metric (device_id, metric_type, metric_name),
    KEY idx_device_type (device_id, metric_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Verification
SELECT 'New table created!' as status;
DESCRIBE storage_array_metrics;
