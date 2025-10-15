#!/bin/bash
#
# Complete Pure Storage Ports Fix
# Cleans invalid entries and rediscovers with proper filtering
#

set -e

cd /opt/librenms

echo "============================================"
echo "Pure Storage Complete Port Fix"
echo "============================================"
echo ""

# Backup
echo "Step 1: Creating backup..."
mysql librenms -e "DROP TABLE IF EXISTS ports_backup_before_final_fix;"
mysql librenms -e "CREATE TABLE ports_backup_before_final_fix AS SELECT * FROM ports WHERE device_id IN (SELECT device_id FROM devices WHERE os = 'purestorage');"
echo "✓ Backup created"
echo ""

# Show current state
echo "Current port count:"
mysql librenms -e "
SELECT d.hostname, COUNT(*) as total_ports
FROM ports p
JOIN devices d ON p.device_id = d.device_id
WHERE d.os = 'purestorage'
GROUP BY d.hostname;
"
echo ""

# Clean ALL ports for Pure Storage
echo "Step 2: Removing ALL existing ports for Pure Storage devices..."
mysql librenms -e "DELETE FROM ports WHERE device_id IN (SELECT device_id FROM devices WHERE os = 'purestorage');"
echo "✓ All ports removed"
echo ""

# Clean rest_api_metrics
echo "Step 3: Cleaning old API metrics..."
mysql librenms -e "DELETE FROM rest_api_metrics WHERE device_id IN (SELECT device_id FROM devices WHERE os = 'purestorage');"
echo "✓ Old API metrics cleaned"
echo ""

# Get devices
DEVICES=$(mysql librenms -N -e "SELECT hostname FROM devices WHERE os = 'purestorage';")

if [ -z "$DEVICES" ]; then
    echo "No Pure Storage devices found!"
    exit 1
fi

echo "Pure Storage devices to rediscover:"
for dev in $DEVICES; do
    echo "  - $dev"
done
echo ""

read -p "Proceed with rediscovery? (y/N) " -n 1 -r
echo
if [[ ! $REPLY =~ ^[Yy]$ ]]; then
    echo "Aborted. To restore: INSERT INTO ports SELECT * FROM ports_backup_before_final_fix;"
    exit 1
fi

# Rediscover each device
for device in $DEVICES; do
    echo ""
    echo "============================================"
    echo "Rediscovering: $device"
    echo "============================================"
    
    echo "Running REST API discovery..."
    ./discovery.php -h "$device" -m restapi -d
    
    echo ""
    echo "Running poller..."
    ./poller.php -h "$device" -d
    
    echo "✓ Completed: $device"
done

echo ""
echo "============================================"
echo "Final Results"
echo "============================================"
echo ""

echo "Port counts by device:"
mysql librenms -e "
SELECT 
    d.hostname,
    COUNT(*) as total,
    SUM(CASE WHEN p.ifName REGEXP '^ct[0-9]\.eth[0-9]+$' THEN 1 ELSE 0 END) as physical,
    SUM(CASE WHEN p.ifName REGEXP '^ct[0-9]\.eth[0-9]+\.[0-9]+$' THEN 1 ELSE 0 END) as vlan,
    SUM(CASE WHEN p.ifName REGEXP '^vir[0-9]+' THEN 1 ELSE 0 END) as virtual,
    SUM(CASE WHEN p.ifName = 'replbond' THEN 1 ELSE 0 END) as bond
FROM ports p
JOIN devices d ON p.device_id = d.device_id
WHERE d.os = 'purestorage'
GROUP BY d.hostname;
"

echo ""
echo "Port field population:"
mysql librenms -e "
SELECT 
    d.hostname,
    COUNT(*) as total_ports,
    SUM(CASE WHEN p.ifSpeed IS NOT NULL THEN 1 ELSE 0 END) as with_speed,
    SUM(CASE WHEN p.ifAdminStatus IS NOT NULL THEN 1 ELSE 0 END) as with_status,
    SUM(CASE WHEN p.ifPhysAddress IS NOT NULL THEN 1 ELSE 0 END) as with_mac,
    SUM(CASE WHEN p.ifMtu IS NOT NULL THEN 1 ELSE 0 END) as with_mtu
FROM ports p
JOIN devices d ON p.device_id = d.device_id
WHERE d.os = 'purestorage'
GROUP BY d.hostname;
"

echo ""
echo "Sample ports:"
mysql librenms -e "
SELECT d.hostname, p.ifName, p.ifSpeed, p.ifAdminStatus, p.ifPhysAddress, p.ifMtu, p.ifType
FROM ports p
JOIN devices d ON p.device_id = d.device_id
WHERE d.os = 'purestorage'
ORDER BY d.hostname, p.ifName
LIMIT 10;
"

echo ""
echo "Checking for invalid entries (should be empty):"
mysql librenms -e "
SELECT p.ifName, 'INVALID' as status
FROM ports p
JOIN devices d ON p.device_id = d.device_id
WHERE d.os = 'purestorage'
AND (
    p.ifName LIKE 'CH0.%' OR
    p.ifName LIKE 'CT0%' OR
    p.ifName LIKE 'CT1%' OR
    p.ifName LIKE '%-ESXI-%' OR
    p.ifName LIKE 'ALMH-%' OR
    p.ifName LIKE 'RSA-%' OR
    p.ifName LIKE 'SL-SW-%'
);
"

echo ""
echo "============================================"
echo "Fix Complete!"
echo "============================================"
echo ""
echo "Expected results:"
echo "  ✓ Only ct*.eth*, vir*, and replbond interfaces"
echo "  ✓ No hardware components or VMs"
echo "  ✓ Port fields populated (speed, MAC, MTU, status)"
echo ""
