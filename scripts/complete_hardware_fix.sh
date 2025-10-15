#!/bin/bash
# Complete fix for Pure Storage data placement issues

cd /opt/librenms

echo "========================================="
echo "Pure Storage Data Placement Fix"
echo "========================================="
echo ""

echo "Step 1: Fix entPhysicalIndex column size..."
mysql librenms < /tmp/fix_hardware_components_filtering.sql
echo ""

echo "Step 2: Clean up misplaced data..."

# Delete network interfaces that were wrongly added to entPhysical
mysql librenms << 'EOF'
DELETE FROM entPhysical
WHERE device_id IN (SELECT device_id FROM devices WHERE os = 'purestorage')
AND (
  entPhysicalDescr REGEXP '^CT[0-9]\\.ETH[0-9]+$' OR
  entPhysicalDescr REGEXP '^CT[0-9]\\.FC[0-9]+$' OR
  entPhysicalDescr LIKE 'vir%' OR
  entPhysicalDescr = 'replbond'
);
EOF

echo "Deleted network interfaces from entPhysical table"
echo ""

# Delete duplicate storage entries
mysql librenms << 'EOF'
DELETE FROM storage 
WHERE device_id IN (SELECT device_id FROM devices WHERE os = 'purestorage')
AND storage_type = 'rest-api';
EOF

echo "Deleted duplicate storage entries"
echo ""

echo "Step 3: Add filtering to Hardware Components endpoint..."
echo ""
echo "The Hardware Components endpoint should NOT return network interfaces."
echo "Network interfaces belong in the 'Network Interfaces' endpoint."
echo ""
echo "Creating endpoint configuration update..."

# Update the Hardware Components endpoint to filter out network interfaces
mysql librenms << 'EOF'
UPDATE rest_api_connection_endpoints 
SET resource_type = 'hardware'
WHERE name LIKE '%Hardware%Component%'
AND device_id IN (SELECT device_id FROM devices WHERE os = 'purestorage');
EOF

echo "Updated Hardware Components endpoint configuration"
echo ""

echo "Step 4: Verify cleanup..."
mysql librenms -e "
SELECT 
  'entPhysical (network interfaces)' as check_item,
  COUNT(*) as count,
  CASE WHEN COUNT(*) = 0 THEN '✓ PASS' ELSE '✗ FAIL' END as status
FROM entPhysical
WHERE device_id IN (SELECT device_id FROM devices WHERE os = 'purestorage')
AND (entPhysicalDescr REGEXP '^CT[0-9]\\.ETH' OR entPhysicalDescr LIKE 'vir%')

UNION ALL

SELECT 
  'storage (rest-api type)' as check_item,
  COUNT(*) as count,
  CASE WHEN COUNT(*) = 0 THEN '✓ PASS' ELSE '? WAIT' END as status  
FROM storage
WHERE device_id IN (SELECT device_id FROM devices WHERE os = 'purestorage')
AND storage_type = 'rest-api';
"

echo ""
echo "========================================="
echo "Fix Complete!"
echo "========================================="
echo ""
echo "Next steps:"
echo "1. Wait 5 minutes for the next poller cycle"
echo "2. Check if ports are being created:"
echo "   mysql librenms -e \"SELECT ifName FROM ports WHERE device_id IN (2,3) ORDER BY ifName;\""
echo ""
echo "3. Check the logs for any remaining errors:"
echo "   tail -50 /opt/librenms/logs/librenms.log | grep ERROR"
echo ""
echo "4. If network interfaces still appear in Hardware Components,"
echo "   you may need to update the Pure Storage API endpoint configuration"
echo "   to exclude network interfaces from the hardware endpoint response."
