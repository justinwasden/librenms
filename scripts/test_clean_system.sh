#!/bin/bash
# Test the new clean REST API system

echo "=========================================="
echo "Testing Clean REST API System"
echo "=========================================="
echo ""

# Get Pure Storage device
DEVICE_ID=$(mysql -u librenms -p librenms -sN -e "SELECT device_id FROM devices WHERE os='purestorage' LIMIT 1")

if [ -z "$DEVICE_ID" ]; then
    echo "❌ No Pure Storage device found!"
    exit 1
fi

DEVICE_NAME=$(mysql -u librenms -p librenms -sN -e "SELECT hostname FROM devices WHERE device_id=$DEVICE_ID")
echo "✓ Found device: $DEVICE_NAME (ID: $DEVICE_ID)"
echo ""

# Clear old data
echo "Clearing old test data..."
mysql -u librenms -p librenms -e "
DELETE FROM rest_api_metrics WHERE device_id = $DEVICE_ID;
DELETE FROM storage WHERE device_id = $DEVICE_ID AND storage_type = 'rest-api';
DELETE FROM ports WHERE device_id = $DEVICE_ID AND port_descr_type = 'rest-api';
DELETE FROM sensors WHERE device_id = $DEVICE_ID AND sensor_type = 'rest-api';
DELETE FROM storage_array_metrics WHERE device_id = $DEVICE_ID;
" 2>/dev/null

echo "✓ Old data cleared"
echo ""

# Run the poller
echo "Running poller with new clean code..."
echo "=========================================="
./poller.php -h $DEVICE_NAME -d -m restapi 2>&1 | tee /tmp/clean_poll_test.log
echo ""
echo "=========================================="

# Check results
echo ""
echo "Results Summary:"
echo "=========================================="

mysql -u librenms -p librenms -e "
SELECT 
    '✓ Storage (Volumes)' as category, 
    COUNT(*) as count,
    GROUP_CONCAT(DISTINCT storage_descr ORDER BY storage_descr SEPARATOR ', ') as items
FROM storage 
WHERE device_id = $DEVICE_ID AND storage_type = 'rest-api'
UNION ALL
SELECT 
    '✓ Ports (Interfaces)', 
    COUNT(*),
    GROUP_CONCAT(DISTINCT ifName ORDER BY ifName SEPARATOR ', ')
FROM ports 
WHERE device_id = $DEVICE_ID AND port_descr_type = 'rest-api'
UNION ALL
SELECT 
    '✓ Sensors (Performance)', 
    COUNT(*),
    CONCAT(COUNT(DISTINCT sensor_class), ' classes')
FROM sensors 
WHERE device_id = $DEVICE_ID AND sensor_type = 'rest-api'
UNION ALL
SELECT 
    '✓ Complex Metrics (JSON)', 
    COUNT(*),
    GROUP_CONCAT(DISTINCT metric_type ORDER BY metric_type SEPARATOR ', ')
FROM storage_array_metrics 
WHERE device_id = $DEVICE_ID
UNION ALL
SELECT 
    '⚠ Fallback Table', 
    COUNT(*),
    'Items that could not be mapped'
FROM rest_api_metrics 
WHERE device_id = $DEVICE_ID;
"

echo ""
echo "Sensor Classes:"
mysql -u librenms -p librenms -e "
SELECT sensor_class, COUNT(*) as count 
FROM sensors 
WHERE device_id = $DEVICE_ID AND sensor_type = 'rest-api'
GROUP BY sensor_class
ORDER BY count DESC;
"

echo ""
echo "Check detailed logs at: /tmp/clean_poll_test.log"
echo ""
echo "To see specific mappings that worked, run:"
echo "  grep '✓' /tmp/clean_poll_test.log | head -20"
