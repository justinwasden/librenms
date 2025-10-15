#!/bin/bash
#
# Debug Pure Storage API Fields
# This script helps identify what fields the Pure Storage API is actually returning
#

cd /opt/librenms

echo "========================================"
echo "Pure Storage API Field Debug"
echo "========================================"
echo ""

# Get Pure Storage devices
echo "Pure Storage devices:"
mysql librenms -e "SELECT device_id, hostname FROM devices WHERE os = 'purestorage';"
echo ""

read -p "Enter device_id to debug: " DEVICE_ID

if [ -z "$DEVICE_ID" ]; then
    echo "No device_id provided"
    exit 1
fi

echo ""
echo "Checking rest_api_metrics table for available fields..."
echo "========================================"

# Show unique metric keys for network-interfaces
echo ""
echo "Network Interface Metrics:"
mysql librenms -e "
SELECT DISTINCT metric_key
FROM rest_api_metrics
WHERE device_id = $DEVICE_ID
AND resource_type LIKE '%network%'
ORDER BY metric_key;
"

echo ""
echo "Sample metric values (first 10):"
mysql librenms -e "
SELECT metric_key, metric_value, resource_type
FROM rest_api_metrics
WHERE device_id = $DEVICE_ID
AND resource_type LIKE '%network%'
LIMIT 10;
"

echo ""
echo "========================================"
echo "Current Mappings:"
echo "========================================"
mysql librenms -e "
SELECT 
    api_field_name,
    librenms_table,
    librenms_field,
    unit,
    transform,
    enabled,
    last_seen_at
FROM rest_api_metric_field_mappings
WHERE librenms_table = 'ports'
ORDER BY api_field_name;
"

echo ""
echo "========================================"
echo "Mapping Match Check:"
echo "========================================"
echo "Checking which API fields from rest_api_metrics have mappings..."

mysql librenms -e "
SELECT 
    m.metric_key,
    CASE 
        WHEN map.api_field_name IS NOT NULL THEN '✓ HAS MAPPING'
        ELSE '✗ NO MAPPING'
    END as mapping_status,
    map.librenms_field as maps_to
FROM (
    SELECT DISTINCT metric_key
    FROM rest_api_metrics
    WHERE device_id = $DEVICE_ID
    AND resource_type LIKE '%network%'
) m
LEFT JOIN rest_api_metric_field_mappings map ON m.metric_key = map.api_field_name
ORDER BY mapping_status, m.metric_key;
"

echo ""
echo "========================================"
echo "Recommendations:"
echo "========================================"
echo "1. Check if API fields match mapping names exactly"
echo "2. Run seeder if mappings are missing:"
echo "   php artisan db:seed --class=PureStorageMappingsSeeder"
echo "3. Check if fields are nested (eth_, fc_, etc.)"
echo "4. Enable debug logging and re-run discovery:"
echo "   ./discovery.php -h HOSTNAME -m restapi -d -v"
