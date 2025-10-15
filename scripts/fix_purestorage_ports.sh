#!/bin/bash
#
# Pure Storage Ports Cleanup and Rediscovery Script
# This script fixes the ports table by removing hardware components
# and rediscovering only actual network interfaces
#

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LIBRENMS_DIR="$(dirname "$SCRIPT_DIR")"

echo "============================================"
echo "Pure Storage Ports Cleanup Script"
echo "============================================"
echo ""

# Check if running as librenms user
if [ "$(whoami)" != "librenms" ]; then
    echo "WARNING: This script should be run as the 'librenms' user"
    echo "Run: sudo -u librenms $0"
    read -p "Continue anyway? (y/N) " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        exit 1
    fi
fi

cd "$LIBRENMS_DIR"

echo "Step 1: Backing up current ports data..."
mysql librenms -e "CREATE TABLE IF NOT EXISTS ports_backup_$(date +%Y%m%d) AS SELECT * FROM ports WHERE device_id IN (SELECT device_id FROM devices WHERE os = 'purestorage');"
echo "✓ Backup created: ports_backup_$(date +%Y%m%d)"
echo ""

echo "Step 2: Cleaning up invalid port entries..."
echo "  - Removing hardware components (BAY, NVB, PWR, TMP, FAN)"
echo "  - Removing chassis/controller entries (CH0, CT0, CT1)"
echo "  - Removing VM/ESXi host entries"
echo ""

mysql librenms << 'EOF'
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
EOF

echo "✓ Invalid entries removed"
echo ""

echo "Step 3: Verifying remaining ports..."
mysql librenms -e "
SELECT 
    d.hostname,
    COUNT(*) as port_count,
    GROUP_CONCAT(DISTINCT SUBSTRING_INDEX(p.ifName, '.', 1) ORDER BY p.ifName SEPARATOR ', ') as interface_prefixes
FROM ports p
JOIN devices d ON p.device_id = d.device_id
WHERE d.os = 'purestorage'
GROUP BY d.hostname;
"
echo ""

echo "Step 4: Showing sample of remaining interfaces..."
mysql librenms -e "
SELECT 
    d.hostname,
    p.ifName,
    p.ifDescr,
    p.ifSpeed,
    p.ifAdminStatus,
    p.ifOperStatus
FROM ports p
JOIN devices d ON p.device_id = d.device_id
WHERE d.os = 'purestorage'
ORDER BY d.hostname, p.ifName
LIMIT 20;
"
echo ""

echo "Step 5: Getting Pure Storage device hostnames for rediscovery..."
PURE_DEVICES=$(mysql librenms -N -e "SELECT hostname FROM devices WHERE os = 'purestorage';")

if [ -z "$PURE_DEVICES" ]; then
    echo "No Pure Storage devices found!"
    exit 1
fi

echo "Found Pure Storage devices:"
echo "$PURE_DEVICES"
echo ""

read -p "Do you want to rediscover these devices now? (y/N) " -n 1 -r
echo
if [[ $REPLY =~ ^[Yy]$ ]]; then
    for device in $PURE_DEVICES; do
        echo ""
        echo "============================================"
        echo "Rediscovering: $device"
        echo "============================================"
        
        echo ""
        echo "Running REST API discovery..."
        ./discovery.php -h "$device" -m restapi -d
        
        echo ""
        echo "Running standard discovery..."
        ./discovery.php -h "$device" -d
        
        echo ""
        echo "Running poller..."
        ./poller.php -h "$device" -d
        
        echo ""
        echo "✓ Completed: $device"
    done
    
    echo ""
    echo "============================================"
    echo "Final port counts:"
    echo "============================================"
    mysql librenms -e "
    SELECT 
        d.hostname,
        COUNT(*) as total_ports,
        SUM(CASE WHEN p.ifName REGEXP '^ct[0-9]\.eth[0-9]+$' THEN 1 ELSE 0 END) as physical_eth,
        SUM(CASE WHEN p.ifName REGEXP '^ct[0-9]\.eth[0-9]+\.[0-9]+$' THEN 1 ELSE 0 END) as vlan_subint,
        SUM(CASE WHEN p.ifName REGEXP '^vir[0-9]+' THEN 1 ELSE 0 END) as virtual,
        SUM(CASE WHEN p.ifName = 'replbond' THEN 1 ELSE 0 END) as bond
    FROM ports p
    JOIN devices d ON p.device_id = d.device_id
    WHERE d.os = 'purestorage'
    GROUP BY d.hostname;
    "
fi

echo ""
echo "============================================"
echo "Cleanup Complete!"
echo "============================================"
echo ""
echo "Expected port types:"
echo "  ✓ ct0.eth0, ct0.eth1, etc. (physical ethernet)"
echo "  ✓ ct0.eth18.313, ct0.eth18.314, etc. (VLAN subinterfaces)"
echo "  ✓ vir0, vir1, vir4, etc. (virtual interfaces)"
echo "  ✓ replbond (replication bond)"
echo ""
echo "Should NOT see:"
echo "  ✗ CH0.BAY*, CH0.NVB*, CH0.PWR* (hardware)"
echo "  ✗ CT0, CT1, CH0 (controllers/chassis)"
echo "  ✗ ESXi hosts or VMs"
echo ""
echo "To restore from backup if needed:"
echo "  mysql librenms"
echo "  DELETE FROM ports WHERE device_id IN (SELECT device_id FROM devices WHERE os = 'purestorage');"
echo "  INSERT INTO ports SELECT * FROM ports_backup_$(date +%Y%m%d);"
echo ""
