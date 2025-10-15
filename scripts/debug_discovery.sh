#!/bin/bash
#
# Pure Storage Discovery with Debug Logging
# Run this to see exactly how responses are mapped
#

cd /opt/librenms

DEVICE=${1:-}

if [ -z "$DEVICE" ]; then
    echo "Usage: $0 <device_hostname_or_ip>"
    echo ""
    echo "Available Pure Storage devices:"
    mysql -u root librenms -e "SELECT hostname FROM devices WHERE os = 'purestorage';"
    exit 1
fi

echo "═══════════════════════════════════════════════════════════"
echo "Pure Storage Debug Discovery"
echo "═══════════════════════════════════════════════════════════"
echo "Device: $DEVICE"
echo "Time: $(date)"
echo ""

# Create timestamped log file
LOG_FILE="/tmp/pure_discovery_debug_$(date +%Y%m%d_%H%M%S).log"

echo "Running discovery with full debug logging..."
echo "Log file: $LOG_FILE"
echo ""

./discovery.php -h "$DEVICE" -m restapi -d -v 2>&1 | tee "$LOG_FILE"

echo ""
echo "═══════════════════════════════════════════════════════════"
echo "Debug Summary"
echo "═══════════════════════════════════════════════════════════"
echo ""

echo "1. Items Discovered:"
grep "Processing item:" "$LOG_FILE" | wc -l
echo ""

echo "2. Items Filtered (should see hardware/VMs):"
grep "Filtering" "$LOG_FILE"
echo ""

echo "3. Metrics Staged:"
grep "METRICS STAGER - START" "$LOG_FILE" | wc -l
echo ""

echo "4. Mappings Found:"
grep "-> ports\\." "$LOG_FILE" | head -10
echo ""

echo "5. Port Updates:"
grep "ports\\.(ifSpeed|ifAdminStatus|ifPhysAddress|ifMtu|ifType)" "$LOG_FILE"
echo ""

echo "6. Errors:"
grep -i "error\|failed\|warning" "$LOG_FILE" | grep -v "No mapping found" | head -20
echo ""

echo "═══════════════════════════════════════════════════════════"
echo "Post-Discovery Database Check"
echo "═══════════════════════════════════════════════════════════"
echo ""

DEV_ID=$(mysql -u root librenms -N -e "SELECT device_id FROM devices WHERE hostname = '$DEVICE';")

if [ -z "$DEV_ID" ]; then
    echo "Device not found!"
    exit 1
fi

echo "Device ID: $DEV_ID"
echo ""

echo "Port Count:"
mysql librenms -e "
SELECT COUNT(*) as total,
  SUM(CASE WHEN ifSpeed IS NOT NULL THEN 1 ELSE 0 END) as with_speed,
  SUM(CASE WHEN ifAdminStatus IS NOT NULL THEN 1 ELSE 0 END) as with_status,
  SUM(CASE WHEN ifPhysAddress IS NOT NULL THEN 1 ELSE 0 END) as with_mac
FROM ports WHERE device_id = $DEV_ID;
"

echo ""
echo "Sample Ports:"
mysql librenms -e "
SELECT ifName, ifSpeed, ifAdminStatus, ifPhysAddress, ifMtu, ifType
FROM ports
WHERE device_id = $DEV_ID
ORDER BY ifName
LIMIT 10;
"

echo ""
echo "═══════════════════════════════════════════════════════════"
echo "Full debug log saved to: $LOG_FILE"
echo ""
echo "To analyze further:"
echo "  grep 'METRICS STAGER' $LOG_FILE"
echo "  grep 'DataRouter->route' $LOG_FILE"
echo "  grep 'ifSpeed\|ifAdminStatus' $LOG_FILE"
echo "  grep 'storeInPortsTable' $LOG_FILE"
echo "═══════════════════════════════════════════════════════════"
