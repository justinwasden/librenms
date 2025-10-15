# Pure Storage Network Interface Discovery - Fixed

## Summary of Changes

Fixed the Pure Storage REST API discovery to properly handle network interfaces without prepending "rest-api" to names, filter out hardware sensors (fans, temps), and properly map all interface fields to LibreNMS ports table.

## Files Modified

### 1. `/app/RestApi/Data/DataRouter.php`
**Changes:**
- Added `isHardwareSensor()` method to filter out CT*.FAN* and CT*.TMP* entries
- Removed "rest-api" prefix from port names - now uses actual interface name
- Removed "rest-api-port-" fallback naming
- Improved field validation - only creates ports/storage/entities when name/id exists
- Fixed `$transformedValue` typo in storeInPortsTable()

### 2. `/app/RestApi/Mapping/MappingEngine.php`
**Changes:**
- Updated to use correct table name: `rest_api_metric_field_mappings`
- Updated field names to match migration: `api_field_name` instead of `metric_name`
- Added resource type normalization for `network-interface`
- Fixed prediction mappings for port fields (enabled, speed, mtu, type, etc.)
- Removed device-specific filtering since mappings are global

### 3. `/app/Models/RestApiMetricFieldMapping.php`
**Changes:**
- Added `boolean_to_updown` transformation for Pure Storage `enabled` field
- Maps `true` -> `"up"`, `false` -> `"down"` for ifAdminStatus

### 4. `/database/seeders/PureStorageMappingsSeeder.php`
**Changes:**
- Completely rewritten to use correct table: `rest_api_metric_field_mappings`
- Uses correct column names: `api_field_name` not `metric_name`
- Added all Pure Storage network interface field mappings:
  - Basic: name, enabled, speed, interface_type
  - Nested eth fields: eth_address, eth_mac_address, eth_mtu, eth_vlan, eth_subtype
  - Performance: eth_received_bytes_per_sec, eth_transmitted_bytes_per_sec, etc.
- Removed vendor/os/resource_type columns (not in actual table schema)
- Set confidence_score to 1.0 for manual mappings

### 5. `/database/migrations/2025_01_15_000001_add_transform_to_metric_field_mappings.php`
**New migration:**
- Adds `transform` column to `metric_field_mappings` table
- Allows transformation functions like `boolean_to_updown`

### 6. `/app/Discovery/RestApiDiscovery.php`
**Changes:**
- Added resource type normalization: converts "port" to "network-interface"
- Auto-detects network-interface endpoints from path

## Expected Behavior After Fix

### Network Interfaces (Ports)
✅ Physical interfaces: ct0.eth0, ct0.eth10, ct1.eth0, etc.
✅ VLAN subinterfaces: ct0.eth18.313, ct0.eth18.314, etc.
✅ Virtual interfaces: vir0, vir1, replbond
✅ Bond interfaces: replbond

❌ Hardware sensors: CT0.FAN0, CT1.TMP0, etc. (filtered out)

### Port Data Populated
- `ifName`: Actual interface name (e.g., "ct0.eth0")
- `ifDescr`: Interface name or subtype
- `ifSpeed`: Speed in bps
- `ifAdminStatus`: "up" or "down" (from boolean `enabled`)
- `ifPhysAddress`: MAC address
- `ifMtu`: MTU value
- `ifType`: Interface type ("eth", "fc", etc.)
- `ifAlias`: IP address
- `ifVlan`: VLAN ID (for VLAN subinterfaces)

## How to Apply Changes

1. Run the new migration:
```bash
cd /opt/librenms
php artisan migrate
```

2. Seed the Pure Storage mappings:
```bash
php artisan db:seed --class=PureStorageMappingsSeeder
```

3. Clean up old ports data:
```bash
mysql librenms
DELETE FROM ports WHERE device_id IN (SELECT device_id FROM devices WHERE os = 'purestorage');
```

4. Re-run discovery:
```bash
./discovery.php -h YOUR_PURESTORAGE_HOSTNAME -m restapi -d
```

5. Verify ports are created:
```bash
mysql librenms
SELECT ifName, ifDescr, ifSpeed, ifAdminStatus, ifPhysAddress, ifMtu, ifType 
FROM ports 
WHERE device_id = (SELECT device_id FROM devices WHERE hostname = 'YOUR_PURESTORAGE_HOSTNAME')
ORDER BY ifName;
```

## Testing Checklist

- [ ] Run migration successfully
- [ ] Seed mappings successfully  
- [ ] Clean old ports data
- [ ] Re-run discovery without errors
- [ ] Verify only network interfaces created (no fans/temps)
- [ ] Verify interface names are correct (no "rest-api" prefix)
- [ ] Verify all interface fields populated (speed, MAC, MTU, etc.)
- [ ] Verify VLAN subinterfaces included
- [ ] Verify virtual interfaces included
- [ ] Verify ports visible in LibreNMS UI under Ports tab

## Troubleshooting

### If ports still not showing in UI:
Check that ports have the minimum required fields:
- ifName
- ifDescr  
- ifIndex
- device_id

### If mappings not working:
Check the logs:
```bash
tail -f /opt/librenms/logs/librenms.log | grep -i "mapping\|router\|pure"
```

### If discovery fails:
Run with debug:
```bash
./discovery.php -h YOUR_PURESTORAGE_HOSTNAME -m restapi -d -v
```
