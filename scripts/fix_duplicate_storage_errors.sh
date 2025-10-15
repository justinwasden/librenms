#!/bin/bash
# Fix duplicate storage entry errors for Pure Storage REST API

cd /opt/librenms

echo "=== Pure Storage Duplicate Entry Fix ==="
echo ""
echo "Step 1: Clean existing REST API storage entries..."
mysql librenms << 'EOF'
DELETE FROM storage 
WHERE device_id IN (SELECT device_id FROM devices WHERE os = 'purestorage')
AND storage_type = 'rest-api';
EOF

echo "Storage entries cleaned"
echo ""

echo "Step 2: Clean entPhysical entries that will cause errors..."
mysql librenms << 'EOF'
DELETE FROM entPhysical
WHERE device_id IN (SELECT device_id FROM devices WHERE os = 'purestorage');
EOF

echo "entPhysical entries cleaned"
echo ""

echo "Step 3: Verify cleanup..."
mysql librenms -e "
SELECT 
  'Storage entries' as table_name,
  COUNT(*) as count
FROM storage
WHERE device_id IN (SELECT device_id FROM devices WHERE os = 'purestorage')
UNION ALL
SELECT
  'entPhysical entries' as table_name,
  COUNT(*) as count  
FROM entPhysical
WHERE device_id IN (SELECT device_id FROM devices WHERE os = 'purestorage');
"

echo ""
echo "=== Cleanup complete! ==="
echo ""
echo "The DataRouter will now be able to INSERT new entries without conflicts."
echo "Wait 5 minutes for the next poller cycle, then check:"
echo "  sudo -u librenms mysql librenms -e \"SELECT COUNT(*) FROM storage WHERE device_id IN (2,3);\""
echo "  sudo -u librenms mysql librenms -e \"SELECT COUNT(*) FROM ports WHERE device_id IN (2,3);\""
