#!/bin/bash
#
# Pure Storage Ports Validation Script
# Checks if the ports table has only valid network interfaces
#

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LIBRENMS_DIR="$(dirname "$SCRIPT_DIR")"

cd "$LIBRENMS_DIR"

echo "============================================"
echo "Pure Storage Ports Validation"
echo "============================================"
echo ""

# Check if Pure Storage devices exist
DEVICE_COUNT=$(mysql librenms -N -e "SELECT COUNT(*) FROM devices WHERE os = 'purestorage';")

if [ "$DEVICE_COUNT" -eq 0 ]; then
    echo "❌ No Pure Storage devices found"
    exit 1
fi

echo "✓ Found $DEVICE_COUNT Pure Storage device(s)"
echo ""

# Check for invalid entries
echo "Checking for invalid port entries..."
INVALID_COUNT=$(mysql librenms -N -e "
SELECT COUNT(*) 
FROM ports p
JOIN devices d ON p.device_id = d.device_id
WHERE d.os = 'purestorage'
AND (
    p.ifName REGEXP '^CH[0-9]\.BAY[0-9]+$' OR
    p.ifName REGEXP '^CH[0-9]\.NVB[0-9]+$' OR
    p.ifName REGEXP '^CH[0-9]\.PWR[0-9]+$' OR
    p.ifName REGEXP '^CH[0-9]\.TMP[0-9]+$' OR
    p.ifName REGEXP '^CT[0-9]\.FAN[0-9]+$' OR
    p.ifName REGEXP '^CH[0-9]$' OR
    p.ifName REGEXP '^CT[0-9]$' OR
    p.ifName LIKE 'ITS-RSA-ESXI-%' OR
    p.ifName LIKE 'ALM-C220-ESXI-%' OR
    p.ifName REGEXP '^ALMH-C[0-9]S[0-9]+$' OR
    p.ifName LIKE 'RSA-SW-%' OR
    p.ifName LIKE 'SL-SW-%' OR
    p.ifName LIKE 'RSA-IAAS-%' OR
    p.ifName LIKE 'RSA-MH-%' OR
    p.ifName LIKE 'RSA-PS-%'
);
")

if [ "$INVALID_COUNT" -gt 0 ]; then
    echo "❌ Found $INVALID_COUNT invalid port entries!"
    echo ""
    echo "Invalid entries:"
    mysql librenms -e "
    SELECT 
        d.hostname,
        p.ifName,
        CASE 
            WHEN p.ifName REGEXP '^CH[0-9]\.BAY' THEN 'Hardware: Blade Bay'
            WHEN p.ifName REGEXP '^CH[0-9]\.NVB' THEN 'Hardware: NVMe Backplane'
            WHEN p.ifName REGEXP '^CH[0-9]\.PWR' THEN 'Hardware: Power Supply'
            WHEN p.ifName REGEXP '^CH[0-9]\.TMP' THEN 'Hardware: Temperature'
            WHEN p.ifName REGEXP '^CT[0-9]\.FAN' THEN 'Hardware: Fan'
            WHEN p.ifName REGEXP '^CH[0-9]$|^CT[0-9]$' THEN 'Hardware: Chassis/Controller'
            WHEN p.ifName LIKE '%-ESXI-%' OR p.ifName LIKE 'ALMH-%' THEN 'VM: ESXi/Host'
            WHEN p.ifName LIKE 'RSA-%' OR p.ifName LIKE 'SL-SW-%' THEN 'VM: Software/IaaS'
            ELSE 'Unknown'
        END as entry_type
    FROM ports p
    JOIN devices d ON p.device_id = d.device_id
    WHERE d.os = 'purestorage'
    AND (
        p.ifName REGEXP '^CH[0-9]\.BAY[0-9]+$' OR
        p.ifName REGEXP '^CH[0-9]\.NVB[0-9]+$' OR
        p.ifName REGEXP '^CH[0-9]\.PWR[0-9]+$' OR
        p.ifName REGEXP '^CH[0-9]\.TMP[0-9]+$' OR
        p.ifName REGEXP '^CT[0-9]\.FAN[0-9]+$' OR
        p.ifName REGEXP '^CH[0-9]$' OR
        p.ifName REGEXP '^CT[0-9]$' OR
        p.ifName LIKE 'ITS-RSA-ESXI-%' OR
        p.ifName LIKE 'ALM-C220-ESXI-%' OR
        p.ifName REGEXP '^ALMH-C[0-9]S[0-9]+$' OR
        p.ifName LIKE 'RSA-SW-%' OR
        p.ifName LIKE 'SL-SW-%' OR
        p.ifName LIKE 'RSA-IAAS-%' OR
        p.ifName LIKE 'RSA-MH-%' OR
        p.ifName LIKE 'RSA-PS-%'
    )
    ORDER BY d.hostname, entry_type, p.ifName
    LIMIT 20;
    "
    echo ""
    echo "Run cleanup script to fix: scripts/cleanup_purestorage_ports.sql"
    exit 1
else
    echo "✓ No invalid port entries found"
fi

echo ""

# Check for unexpected entries that don't match valid patterns
echo "Checking for unexpected interface patterns..."
UNEXPECTED_COUNT=$(mysql librenms -N -e "
SELECT COUNT(*)
FROM ports p
JOIN devices d ON p.device_id = d.device_id
WHERE d.os = 'purestorage'
AND NOT (
    p.ifName REGEXP '^ct[0-9]\.eth[0-9]+$' OR
    p.ifName REGEXP '^ct[0-9]\.eth[0-9]+\.[0-9]+$' OR
    p.ifName REGEXP '^vir[0-9]+' OR
    p.ifName = 'replbond'
);
")

if [ "$UNEXPECTED_COUNT" -gt 0 ]; then
    echo "⚠️  Found $UNEXPECTED_COUNT unexpected interface patterns"
    echo ""
    mysql librenms -e "
    SELECT d.hostname, p.ifName, 'Unexpected pattern' as warning
    FROM ports p
    JOIN devices d ON p.device_id = d.device_id
    WHERE d.os = 'purestorage'
    AND NOT (
        p.ifName REGEXP '^ct[0-9]\.eth[0-9]+$' OR
        p.ifName REGEXP '^ct[0-9]\.eth[0-9]+\.[0-9]+$' OR
        p.ifName REGEXP '^vir[0-9]+' OR
        p.ifName = 'replbond'
    )
    ORDER BY d.hostname, p.ifName;
    "
    echo ""
else
    echo "✓ All ports match expected patterns"
fi

echo ""

# Show port breakdown by device
echo "Port breakdown by device:"
echo "============================================"
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

# Check for NULL values in critical fields
echo "Checking for NULL values in critical fields..."
NULL_SPEED=$(mysql librenms -N -e "
SELECT COUNT(*) FROM ports p
JOIN devices d ON p.device_id = d.device_id
WHERE d.os = 'purestorage' AND p.ifSpeed IS NULL;
")

NULL_STATUS=$(mysql librenms -N -e "
SELECT COUNT(*) FROM ports p
JOIN devices d ON p.device_id = d.device_id
WHERE d.os = 'purestorage' AND (p.ifOperStatus IS NULL OR p.ifAdminStatus IS NULL);
")

if [ "$NULL_SPEED" -gt 0 ]; then
    echo "⚠️  $NULL_SPEED ports have NULL ifSpeed"
fi

if [ "$NULL_STATUS" -gt 0 ]; then
    echo "⚠️  $NULL_STATUS ports have NULL status fields"
fi

if [ "$NULL_SPEED" -eq 0 ] && [ "$NULL_STATUS" -eq 0 ]; then
    echo "✓ All ports have populated fields"
fi

echo ""

# Final summary
echo "============================================"
echo "Validation Summary"
echo "============================================"

if [ "$INVALID_COUNT" -eq 0 ] && [ "$UNEXPECTED_COUNT" -eq 0 ]; then
    echo "✅ PASS - Ports table is clean!"
    echo ""
    echo "Valid interface types present:"
    echo "  • ct*.eth* (physical ethernet)"
    echo "  • ct*.eth*.* (VLAN subinterfaces)"
    echo "  • vir* (virtual interfaces)"
    echo "  • replbond (replication bond)"
    exit 0
else
    echo "❌ FAIL - Issues found in ports table"
    echo ""
    echo "Run these scripts to fix:"
    echo "  1. scripts/cleanup_purestorage_ports.sql"
    echo "  2. Re-run discovery on Pure Storage devices"
    exit 1
fi
