-- Pure Storage Port Field Population Diagnostic
-- Run this to understand why port fields are NULL

-- Step 1: Check if mappings exist
SELECT 'Step 1: Checking for port mappings' as step;
SELECT 
    api_field_name,
    librenms_table,
    librenms_field,
    unit,
    transform,
    enabled,
    confidence_score,
    last_seen_at
FROM rest_api_metric_field_mappings
WHERE librenms_table = 'ports'
ORDER BY api_field_name;

-- Step 2: Check if API data is being collected
SELECT 'Step 2: Checking for network interface API data' as step;
SELECT 
    device_id,
    COUNT(DISTINCT metric_key) as unique_metrics,
    COUNT(*) as total_records,
    MAX(last_updated) as last_updated
FROM rest_api_metrics
WHERE device_id IN (SELECT device_id FROM devices WHERE os = 'purestorage')
AND (resource_type LIKE '%network%' OR resource_type LIKE '%interface%' OR resource_type LIKE '%port%')
GROUP BY device_id;

-- Step 3: Show sample API field names being collected
SELECT 'Step 3: Sample API fields from network interfaces' as step;
SELECT DISTINCT 
    metric_key,
    resource_type
FROM rest_api_metrics
WHERE device_id IN (SELECT device_id FROM devices WHERE os = 'purestorage')
AND (resource_type LIKE '%network%' OR resource_type LIKE '%interface%' OR resource_type LIKE '%port%')
ORDER BY metric_key
LIMIT 30;

-- Step 4: Check for mapping mismatches
SELECT 'Step 4: API fields WITHOUT mappings' as step;
SELECT DISTINCT 
    m.metric_key,
    m.resource_type,
    'No mapping found' as issue
FROM rest_api_metrics m
WHERE m.device_id IN (SELECT device_id FROM devices WHERE os = 'purestorage')
AND (m.resource_type LIKE '%network%' OR m.resource_type LIKE '%interface%' OR m.resource_type LIKE '%port%')
AND NOT EXISTS (
    SELECT 1 FROM rest_api_metric_field_mappings map
    WHERE map.api_field_name = m.metric_key
    AND map.librenms_table = 'ports'
)
ORDER BY m.metric_key
LIMIT 20;

-- Step 5: Check ports table for Pure Storage devices
SELECT 'Step 5: Current port status' as step;
SELECT 
    d.hostname,
    COUNT(*) as total_ports,
    SUM(CASE WHEN p.ifSpeed IS NOT NULL THEN 1 ELSE 0 END) as ports_with_speed,
    SUM(CASE WHEN p.ifAdminStatus IS NOT NULL THEN 1 ELSE 0 END) as ports_with_admin_status,
    SUM(CASE WHEN p.ifPhysAddress IS NOT NULL THEN 1 ELSE 0 END) as ports_with_mac,
    SUM(CASE WHEN p.ifMtu IS NOT NULL THEN 1 ELSE 0 END) as ports_with_mtu,
    SUM(CASE WHEN p.ifType IS NOT NULL THEN 1 ELSE 0 END) as ports_with_type
FROM ports p
JOIN devices d ON p.device_id = d.device_id
WHERE d.os = 'purestorage'
GROUP BY d.hostname;

-- Step 6: Check specific port details
SELECT 'Step 6: Sample port details (first 5 ports)' as step;
SELECT 
    d.hostname,
    p.ifName,
    p.ifDescr,
    p.ifSpeed,
    p.ifAdminStatus,
    p.ifOperStatus,
    p.ifPhysAddress,
    p.ifMtu,
    p.ifType,
    p.ifAlias,
    p.port_descr_type,
    p.ifIndex
FROM ports p
JOIN devices d ON p.device_id = d.device_id
WHERE d.os = 'purestorage'
ORDER BY d.hostname, p.ifName
LIMIT 5;

-- Step 7: Check if discovery logs show mapping activity
SELECT 'Step 7: Recent mapping usage' as step;
SELECT 
    api_field_name,
    librenms_table,
    librenms_field,
    last_matched_device_id,
    last_seen_at,
    TIMESTAMPDIFF(MINUTE, last_seen_at, NOW()) as minutes_ago
FROM rest_api_metric_field_mappings
WHERE last_seen_at IS NOT NULL
AND librenms_table = 'ports'
ORDER BY last_seen_at DESC
LIMIT 10;

-- Step 8: Diagnostic summary
SELECT 'Step 8: Diagnostic Summary' as step;
SELECT 
    'Total Mappings for ports' as metric,
    COUNT(*) as value
FROM rest_api_metric_field_mappings
WHERE librenms_table = 'ports'
UNION ALL
SELECT 
    'Enabled Mappings for ports' as metric,
    COUNT(*) as value
FROM rest_api_metric_field_mappings
WHERE librenms_table = 'ports' AND enabled = 1
UNION ALL
SELECT 
    'Pure Storage Devices' as metric,
    COUNT(*) as value
FROM devices
WHERE os = 'purestorage'
UNION ALL
SELECT 
    'Pure Storage Ports' as metric,
    COUNT(*) as value
FROM ports p
JOIN devices d ON p.device_id = d.device_id
WHERE d.os = 'purestorage'
UNION ALL
SELECT 
    'Ports with NULL ifSpeed' as metric,
    COUNT(*) as value
FROM ports p
JOIN devices d ON p.device_id = d.device_id
WHERE d.os = 'purestorage' AND p.ifSpeed IS NULL
UNION ALL
SELECT 
    'Network Interface API Metrics' as metric,
    COUNT(*) as value
FROM rest_api_metrics
WHERE device_id IN (SELECT device_id FROM devices WHERE os = 'purestorage')
AND (resource_type LIKE '%network%' OR resource_type LIKE '%interface%' OR resource_type LIKE '%port%');

-- Recommendations based on results
SELECT 'RECOMMENDATIONS' as step;
SELECT 
    CASE 
        WHEN (SELECT COUNT(*) FROM rest_api_metric_field_mappings WHERE librenms_table = 'ports') = 0 
        THEN '1. Run seeder: php artisan db:seed --class=PureStorageMappingsSeeder'
        WHEN (SELECT COUNT(*) FROM rest_api_metrics WHERE device_id IN (SELECT device_id FROM devices WHERE os = 'purestorage') AND (resource_type LIKE '%network%' OR resource_type LIKE '%interface%')) = 0
        THEN '2. No API data collected - check API connectivity and endpoints'
        WHEN EXISTS (SELECT 1 FROM rest_api_metric_field_mappings WHERE last_seen_at IS NULL AND librenms_table = 'ports')
        THEN '3. Mappings exist but never matched - field names may not match API'
        ELSE '4. Re-run discovery: ./discovery.php -h HOSTNAME -m restapi -d -v'
    END as recommendation;
