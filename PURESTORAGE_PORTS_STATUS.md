# Pure Storage Ports - Complete Fix Summary

## Current Status ✅

**Filtering is Working!**
- Hardware components successfully filtered out
- Only valid network interfaces remain in ports table
- Device 172.16.7.40: 36 ports (16 physical, 16 VLAN, 3 virtual, 1 bond)
- Device 172.16.7.5: 31 ports (28 physical, 0 VLAN, 2 virtual, 1 bond)

## Remaining Issue ⚠️

**Port Fields are NULL**
All port entries have NULL values for:
- `ifSpeed` - Interface speed
- `ifAdminStatus` / `ifOperStatus` - Status
- `ifPhysAddress` - MAC address  
- `ifMtu` - MTU
- `ifType` - Interface type

## Root Cause

The REST API discovery is working and creating ports, but the field mappings aren't populating the data. This is because:

1. **API field names don't match mapping names** - Pure Storage API uses nested fields like `eth_speed`, not just `speed`
2. **Mappings may not be seeded** - The database might not have the mapping definitions
3. **Resource type mismatch** - API might be returning `network-interfaces` but mappings expect `network-interface` or `port`

## Diagnosis Steps

### 1. Run the diagnostic SQL script
```bash
mysql librenms < scripts/diagnose_purestorage_ports.sql
```

This will show you:
- Which mappings exist
- Which API fields are being collected
- Which API fields have NO mappings
- Why ports have NULL values

### 2. Check what the API is actually returning
```bash
# Enable debug mode and check logs
./discovery.php -h 172.16.7.40 -m restapi -d -v 2>&1 | tee /tmp/pure_discovery_debug.log

# Look for field names in the log
grep -i "metric_key\|api_field\|ifName\|eth_\|network" /tmp/pure_discovery_debug.log
```

### 3. Check current mappings
```sql
SELECT api_field_name, librenms_table, librenms_field, enabled
FROM rest_api_metric_field_mappings
WHERE librenms_table = 'ports'
ORDER BY api_field_name;
```

### 4. Check what API data was collected
```sql
SELECT DISTINCT metric_key, resource_type
FROM rest_api_metrics
WHERE device_id IN (SELECT device_id FROM devices WHERE os = 'purestorage')
AND (resource_type LIKE '%network%' OR resource_type LIKE '%interface%')
ORDER BY metric_key
LIMIT 50;
```

## Likely Solutions

### Solution A: Seed the Mappings (if missing)

```bash
cd /opt/librenms
php artisan db:seed --class=PureStorageMappingsSeeder
```

Then rediscover:
```bash
./discovery.php -h 172.16.7.40 -m restapi -d
./poller.php -h 172.16.7.40 -d
```

### Solution B: Add Missing Mappings

If the API uses different field names than expected, you need to add mappings for the actual field names.

**Check what fields the API returns:**
```sql
-- See actual API field names
SELECT DISTINCT metric_key
FROM rest_api_metrics
WHERE device_id = 2
AND (resource_type LIKE '%network%' OR metric_key LIKE '%eth%')
ORDER BY metric_key;
```

**Add mappings for those fields:**
```sql
-- Example: if API returns 'interface_speed' instead of 'speed'
INSERT INTO rest_api_metric_field_mappings 
(api_field_name, librenms_table, librenms_field, unit, transform, enabled, user_created, confidence_score, created_at, updated_at)
VALUES 
('interface_speed', 'ports', 'ifSpeed', 'bps', NULL, 1, 0, 1.0, NOW(), NOW());
```

### Solution C: Fix Resource Type Normalization

The API might return `network-interfaces` (plural) but the code expects `network-interface` (singular).

Check `/app/RestApi/Discovery/RestApiDiscovery.php` for resource type normalization.

## Quick Fix Commands

```bash
# 1. Backup current state
mysql librenms -e "CREATE TABLE ports_current_state AS SELECT * FROM ports WHERE device_id IN (2,3);"

# 2. Run diagnostics
mysql librenms < scripts/diagnose_purestorage_ports.sql > /tmp/diagnosis.txt
cat /tmp/diagnosis.txt

# 3. Seed mappings
php artisan db:seed --class=PureStorageMappingsSeeder

# 4. Rediscover with debug
./discovery.php -h 172.16.7.40 -m restapi -d -v > /tmp/discovery_debug.log 2>&1

# 5. Check if fields populated
mysql librenms -e "
SELECT ifName, ifSpeed, ifAdminStatus, ifPhysAddress, ifMtu, ifType
FROM ports 
WHERE device_id = 2 
ORDER BY ifName 
LIMIT 10;
"
```

## Expected Result After Fix

```
+---------------+-----------+---------------+-------------------+-------+---------+
| ifName        | ifSpeed   | ifAdminStatus | ifPhysAddress     | ifMtu | ifType  |
+---------------+-----------+---------------+-------------------+-------+---------+
| ct0.eth0      | 10000000000| up           | 52:54:30:00:00:00 | 1500  | eth     |
| ct0.eth1      | 10000000000| up           | 52:54:30:00:00:01 | 1500  | eth     |
| ct0.eth18     | 40000000000| up           | 52:54:30:00:00:12 | 9000  | eth     |
| ct0.eth18.313 | 40000000000| up           | 52:54:30:00:00:12 | 9000  | eth     |
| ct1.eth0      | 10000000000| up           | 52:54:30:01:00:00 | 1500  | eth     |
| vir0          | NULL       | up           | NULL              | 1500  | virtual |
| replbond      | 20000000000| up           | 52:54:30:ff:ff:ff | 1500  | bond    |
+---------------+-----------+---------------+-------------------+-------+---------+
```

## Files Modified

- ✅ `/app/RestApi/Data/DataRouter.php` - Added `isNonNetworkInterface()` filtering
- ✅ `/database/seeders/PureStorageMappingsSeeder.php` - Defines port field mappings
- ✅ `/scripts/cleanup_purestorage_ports.sql` - Removes invalid entries
- ✅ `/scripts/diagnose_purestorage_ports.sql` - Diagnostic queries  
- ✅ `/scripts/fix_purestorage_ports.sh` - Automated fix script
- ✅ `/scripts/validate_purestorage_ports.sh` - Validation script

## Next Steps

1. **Run diagnostics** to understand which mappings are missing
2. **Seed mappings** if they don't exist
3. **Check API field names** match mapping definitions
4. **Rediscover devices** to populate port fields
5. **Validate results** using the validation script

## Support

If fields are still NULL after seeding and rediscovery:

1. Check LibreNMS logs: `tail -f /opt/librenms/logs/librenms.log`
2. Run discovery with full debug: `./discovery.php -h HOSTNAME -m restapi -d -v`
3. Verify API connectivity: Check Pure Storage array API settings
4. Check mapping match: Compare API field names with mapping `api_field_name` column

The diagnostic script will tell you exactly which step is failing.
