-- Pure Storage Ports Table Cleanup Script
-- This SQL script removes invalid hardware components and non-network entries
-- from the ports table for Pure Storage devices

-- Step 1: Backup existing data
CREATE TABLE IF NOT EXISTS ports_backup_purestorage AS 
SELECT * FROM ports 
WHERE device_id IN (SELECT device_id FROM devices WHERE os = 'purestorage');

-- Step 2: Show what will be deleted
SELECT 'Entries to be deleted:' as status;
SELECT 
    d.hostname,
    p.ifName,
    p.ifDescr,
    CASE 
        WHEN p.ifName REGEXP '^CH[0-9]\.BAY[0-9]+$' THEN 'Hardware: Blade Bay'
        WHEN p.ifName REGEXP '^CH[0-9]\.NVB[0-9]+$' THEN 'Hardware: NVMe Backplane'
        WHEN p.ifName REGEXP '^CH[0-9]\.PWR[0-9]+$' THEN 'Hardware: Power Supply'
        WHEN p.ifName REGEXP '^CH[0-9]\.TMP[0-9]+$' THEN 'Hardware: Temperature Sensor'
        WHEN p.ifName REGEXP '^CT[0-9]\.FAN[0-9]+$' THEN 'Hardware: Fan'
        WHEN p.ifName REGEXP '^CH[0-9]$' THEN 'Hardware: Chassis'
        WHEN p.ifName REGEXP '^CT[0-9]$' THEN 'Hardware: Controller'
        WHEN p.ifName LIKE 'ITS-RSA-ESXI-%' THEN 'VM: ESXi Host'
        WHEN p.ifName LIKE 'ALM-C220-ESXI-%' THEN 'VM: ESXi Host'
        WHEN p.ifName REGEXP '^ALMH-C[0-9]S[0-9]+$' THEN 'VM: Host'
        WHEN p.ifName LIKE 'RSA-SW-%' THEN 'VM: Software VM'
        WHEN p.ifName LIKE 'SL-SW-%' THEN 'VM: Software VM'
        WHEN p.ifName LIKE 'RSA-IAAS-%' THEN 'VM: IaaS VM'
        WHEN p.ifName LIKE 'RSA-MH-%' THEN 'VM: Host'
        WHEN p.ifName LIKE 'RSA-PS-%' THEN 'VM: Host'
        ELSE 'Unknown'
    END as entry_type
FROM ports p
JOIN devices d ON p.device_id = d.device_id
WHERE d.os = 'purestorage'
AND (
    -- Hardware backplane/chassis components
    p.ifName REGEXP '^CH[0-9]\.BAY[0-9]+$' OR
    p.ifName REGEXP '^CH[0-9]\.NVB[0-9]+$' OR
    p.ifName REGEXP '^CH[0-9]\.PWR[0-9]+$' OR
    p.ifName REGEXP '^CH[0-9]\.TMP[0-9]+$' OR
    p.ifName REGEXP '^CT[0-9]\.FAN[0-9]+$' OR
    p.ifName REGEXP '^CH[0-9]$' OR
    p.ifName REGEXP '^CT[0-9]$' OR
    
    -- Virtual machines and ESXi hosts
    p.ifName LIKE 'ITS-RSA-ESXI-%' OR
    p.ifName LIKE 'ALM-C220-ESXI-%' OR
    p.ifName REGEXP '^ALMH-C[0-9]S[0-9]+$' OR
    p.ifName LIKE 'RSA-SW-%' OR
    p.ifName LIKE 'SL-SW-%' OR
    p.ifName LIKE 'RSA-IAAS-%' OR
    p.ifName LIKE 'RSA-MH-%' OR
    p.ifName LIKE 'RSA-PS-%'
)
ORDER BY d.hostname, entry_type, p.ifName;

-- Step 3: Count by type before deletion
SELECT 'Count by type before deletion:' as status;
SELECT 
    CASE 
        WHEN ifName REGEXP '^CH[0-9]\.BAY' THEN 'Blade Bays'
        WHEN ifName REGEXP '^CH[0-9]\.NVB' THEN 'NVMe Backplane'
        WHEN ifName REGEXP '^CH[0-9]\.PWR' THEN 'Power Supplies'
        WHEN ifName REGEXP '^CH[0-9]\.TMP' THEN 'Temperature Sensors'
        WHEN ifName REGEXP '^CT[0-9]\.FAN' THEN 'Fans'
        WHEN ifName REGEXP '^CH[0-9]$|^CT[0-9]$' THEN 'Chassis/Controllers'
        WHEN ifName LIKE '%-ESXI-%' OR ifName LIKE 'ALMH-%' THEN 'ESXi/Hosts'
        WHEN ifName LIKE 'RSA-%' OR ifName LIKE 'SL-SW-%' THEN 'VMs/Software'
        ELSE 'Other'
    END as category,
    COUNT(*) as count
FROM ports
WHERE device_id IN (SELECT device_id FROM devices WHERE os = 'purestorage')
AND (
    ifName REGEXP '^CH[0-9]\.BAY[0-9]+$' OR
    ifName REGEXP '^CH[0-9]\.NVB[0-9]+$' OR
    ifName REGEXP '^CH[0-9]\.PWR[0-9]+$' OR
    ifName REGEXP '^CH[0-9]\.TMP[0-9]+$' OR
    ifName REGEXP '^CT[0-9]\.FAN[0-9]+$' OR
    ifName REGEXP '^CH[0-9]$' OR
    ifName REGEXP '^CT[0-9]$' OR
    ifName LIKE 'ITS-RSA-ESXI-%' OR
    ifName LIKE 'ALM-C220-ESXI-%' OR
    ifName REGEXP '^ALMH-C[0-9]S[0-9]+$' OR
    ifName LIKE 'RSA-SW-%' OR
    ifName LIKE 'SL-SW-%' OR
    ifName LIKE 'RSA-IAAS-%' OR
    ifName LIKE 'RSA-MH-%' OR
    ifName LIKE 'RSA-PS-%'
)
GROUP BY category
ORDER BY count DESC;

-- Step 4: Delete invalid entries
DELETE FROM ports 
WHERE device_id IN (SELECT device_id FROM devices WHERE os = 'purestorage')
AND (
    -- Hardware backplane/chassis components
    ifName REGEXP '^CH[0-9]\.BAY[0-9]+$' OR
    ifName REGEXP '^CH[0-9]\.NVB[0-9]+$' OR
    ifName REGEXP '^CH[0-9]\.PWR[0-9]+$' OR
    ifName REGEXP '^CH[0-9]\.TMP[0-9]+$' OR
    ifName REGEXP '^CT[0-9]\.FAN[0-9]+$' OR
    ifName REGEXP '^CH[0-9]$' OR
    ifName REGEXP '^CT[0-9]$' OR
    
    -- Virtual machines and ESXi hosts
    ifName LIKE 'ITS-RSA-ESXI-%' OR
    ifName LIKE 'ALM-C220-ESXI-%' OR
    ifName REGEXP '^ALMH-C[0-9]S[0-9]+$' OR
    ifName LIKE 'RSA-SW-%' OR
    ifName LIKE 'SL-SW-%' OR
    ifName LIKE 'RSA-IAAS-%' OR
    ifName LIKE 'RSA-MH-%' OR
    ifName LIKE 'RSA-PS-%'
);

-- Step 5: Show remaining ports by device
SELECT 'Remaining ports after cleanup:' as status;
SELECT 
    d.hostname,
    d.device_id,
    COUNT(*) as total_ports,
    SUM(CASE WHEN p.ifName REGEXP '^ct[0-9]\.eth[0-9]+$' THEN 1 ELSE 0 END) as physical_eth,
    SUM(CASE WHEN p.ifName REGEXP '^ct[0-9]\.eth[0-9]+\.[0-9]+$' THEN 1 ELSE 0 END) as vlan_subinterfaces,
    SUM(CASE WHEN p.ifName REGEXP '^vir[0-9]+' THEN 1 ELSE 0 END) as virtual_interfaces,
    SUM(CASE WHEN p.ifName = 'replbond' THEN 1 ELSE 0 END) as replication_bond
FROM devices d
LEFT JOIN ports p ON d.device_id = p.device_id
WHERE d.os = 'purestorage'
GROUP BY d.hostname, d.device_id
ORDER BY d.hostname;

-- Step 6: Show sample of remaining interfaces
SELECT 'Sample of valid interfaces:' as status;
SELECT 
    d.hostname,
    p.ifName,
    p.ifDescr,
    p.ifSpeed,
    p.ifAdminStatus,
    p.ifOperStatus,
    p.ifPhysAddress,
    p.ifMtu
FROM ports p
JOIN devices d ON p.device_id = d.device_id
WHERE d.os = 'purestorage'
ORDER BY d.hostname, p.ifName
LIMIT 20;

-- Step 7: Identify any unexpected entries that remain
SELECT 'Unexpected entries (should be empty):' as status;
SELECT 
    d.hostname,
    p.ifName,
    'Does not match expected patterns' as warning
FROM ports p
JOIN devices d ON p.device_id = d.device_id
WHERE d.os = 'purestorage'
AND NOT (
    p.ifName REGEXP '^ct[0-9]\.eth[0-9]+$' OR           -- Physical: ct0.eth0
    p.ifName REGEXP '^ct[0-9]\.eth[0-9]+\.[0-9]+$' OR  -- VLAN: ct0.eth18.313
    p.ifName REGEXP '^vir[0-9]+' OR                      -- Virtual: vir0, vir4.8
    p.ifName = 'replbond'                                -- Replication bond
)
ORDER BY d.hostname, p.ifName;

-- Optional: To restore from backup if needed
-- DELETE FROM ports WHERE device_id IN (SELECT device_id FROM devices WHERE os = 'purestorage');
-- INSERT INTO ports SELECT * FROM ports_backup_purestorage;
