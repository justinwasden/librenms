# Pure Storage Ports Table Fix - Complete Guide

## Problem Summary

The Pure Storage REST API discovery was incorrectly adding hardware monitoring components, chassis components, and virtual machine inventory to the `ports` table. These are not network interfaces and should not appear as ports in LibreNMS.

### Issues Found:
1. **Hardware components added as ports:**
   - `CH0.BAY*` - Blade bay slots (internal hardware)
   - `CH0.NVB*` - NVMe backplane interfaces (internal chassis)
   - `CH0.PWR*` - Power supply metrics
   - `CH0.TMP*`, `CT*.FAN*` - Temperature/fan sensors
   - `CH0`, `CT0`, `CT1` - Chassis and controller entries

2. **VM/Host inventory added as ports:**
   - `ITS-RSA-ESXI-*` - ESXi hosts
   - `ALM-C220-ESXI-*` - ESXi hosts
   - `ALMH-*` - Hosts
   - `RSA-SW-*`, `SL-SW-*` - Software VMs
   - `RSA-IAAS-*` - IaaS VMs
   - `RSA-MH-*`, `RSA-PS-*` - Host systems

3. **All ports had NULL values for essential metrics:**
   - `ifSpeed`, `ifOperStatus`, `ifAdminStatus`
   - All traffic counters
   - Polling timestamps

## Root Cause

The `DataRouter.php` file had an incomplete `isHardwareSensor()` method that only filtered out fans and temperature sensors. It didn't filter out:
- Blade bays and NVMe backplane
- Power supplies
- Chassis/controller entries
- VM/host inventory items

## Solution

### Files Modified

#### 1. `/app/RestApi/Data/DataRouter.php`

**Added new method `isNonNetworkInterface()`:**
```php
protected function isNonNetworkInterface(array $itemContext): bool
{
    // Filters out all hardware components and VM inventory
    // Only allows actual network interfaces:
    //   - ct*.eth* (physical ethernet)
    //   - ct*.eth*.* (VLAN subinterfaces)
    //   - vir* (virtual interfaces)
    //   - replbond (replication bond)
}
```

**Updated `route()` method:**
```php
// Added check for network-interfaces resource type
if ($resourceType === 'network-interfaces' || $resourceType === 'network-interface') {
    if ($this->isNonNetworkInterface($itemContext)) {
        Log::debug("Skipping non-network interface: {$itemContext['name']}");
        return;
    }
}
```

### What Gets Filtered Out

The fix filters out anything that doesn't match these valid network interface patterns:

**Valid interfaces (will be discovered):**
- `ct0.eth0`, `ct0.eth1`, `ct1.eth0`, etc. - Physical ethernet ports
- `ct0.eth18.313`, `ct0.eth18.314`, etc. - VLAN-tagged subinterfaces
- `vir0`, `vir1`, `vir4` - Virtual interfaces
- `vir4.8` - Virtual interface VLANs
- `replbond` - Replication bond interface

**Invalid entries (will be filtered):**
- Hardware: `CH0.BAY*`, `CH0.NVB*`, `CH0.PWR*`, `CH0.TMP*`, `CT*.FAN*`
- Chassis/Controllers: `CH0`, `CT0`, `CT1`
- VMs/Hosts: Any entry matching ESXi, IAAS, SW patterns

## Installation Instructions

### Step 1: Backup Current Data

```bash
cd /opt/librenms
mysql librenms -e "CREATE TABLE ports_backup_$(date +%Y%m%d) AS SELECT * FROM ports WHERE device_id IN (SELECT device_id FROM devices WHERE os = 'purestorage');"
```

### Step 2: Update Code

Copy the fixed `DataRouter.php` to your LibreNMS installation:

```bash
# Backup original file
cp app/RestApi/Data/DataRouter.php app/RestApi/Data/DataRouter.php.backup

# Copy the fixed version
cp /path/to/fixed/DataRouter.php app/RestApi/Data/DataRouter.php

# Set proper ownership
chown librenms:librenms app/RestApi/Data/DataRouter.php
```

### Step 3: Clean Up Existing Invalid Entries

**Option A: Use the SQL script (fastest)**
```bash
mysql librenms < scripts/cleanup_purestorage_ports.sql
```

**Option B: Use the bash script (includes rediscovery)**
```bash
chmod +x scripts/fix_purestorage_ports.sh
sudo -u librenms ./scripts/fix_purestorage_ports.sh
```

**Option C: Manual SQL cleanup**
```sql
DELETE FROM ports 
WHERE device_id IN (SELECT device_id FROM devices WHERE os = 'purestorage')
AND (
    ifName REGEXP '^CH[0-9]\.BAY[0-9]+$' OR
    ifName REGEXP '^CH[0-9]\.NVB[0-9]+$' OR
    ifName REGEXP '^CH[0-9]\.PWR[0-9]+$' OR
    ifName REGEXP '^CH[0-9]\.TMP[0-9]+$' OR
    ifName REGEXP '^CT[0-9]\.FAN[0-9]+$' OR
    ifName REGEXP '^CH[0-9]$' OR
    ifName REGEXP '^CT[0-9]$' OR
    ifName LIKE 'ITS-RSA-ESXI-%' OR
    ifName LIKE 'ALM-C220-ESXI-%' OR
    ifName REGEXP '^ALMH-C[0-9]S[0-9]+$' OR
    ifName LIKE 'RSA-SW-%' OR
    ifName LIKE 'SL-SW-%' OR
    ifName LIKE 'RSA-IAAS-%' OR
    ifName LIKE 'RSA-MH-%' OR
    ifName LIKE 'RSA-PS-%'
);
```

### Step 4: Rediscover Devices

```bash
# Get list of Pure Storage devices
DEVICES=$(mysql librenms -N -e "SELECT hostname FROM devices WHERE os = 'purestorage';")

# Rediscover each device
for device in $DEVICES; do
    echo "Rediscovering $device..."
    ./discovery.php -h "$device" -m restapi -d
    ./poller.php -h "$device" -d
done
```

### Step 5: Verify Results

```sql
-- Check port counts by device
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

-- Show sample ports
SELECT ifName, ifDescr, ifSpeed, ifAdminStatus, ifOperStatus, ifPhysAddress, ifMtu
FROM ports 
WHERE device_id = (SELECT device_id FROM devices WHERE os = 'purestorage' LIMIT 1)
ORDER BY ifName
LIMIT 20;

-- Check for any unexpected entries (should be empty)
SELECT p.ifName, 'Unexpected entry' as warning
FROM ports p
JOIN devices d ON p.device_id = d.device_id
WHERE d.os = 'purestorage'
AND NOT (
    p.ifName REGEXP '^ct[0-9]\.eth[0-9]+$' OR
    p.ifName REGEXP '^ct[0-9]\.eth[0-9]+\.[0-9]+$' OR
    p.ifName REGEXP '^vir[0-9]+' OR
    p.ifName = 'replbond'
);
```

## Expected Results

After applying the fix, you should see:

### Valid Ports Table Entries
```
+-------------------+--------+---------------+
| ifName            | ifType | ifAdminStatus |
+-------------------+--------+---------------+
| ct0.eth0          | eth    | up            |
| ct0.eth1          | eth    | up            |
| ct0.eth18         | eth    | up            |
| ct0.eth18.313     | eth    | up            |
| ct0.eth18.314     | eth    | up            |
| ct0.eth19         | eth    | up            |
| ct1.eth0          | eth    | up            |
| vir0              | vir    | up            |
| vir4              | vir    | up            |
| vir4.8            | vir    | up            |
| replbond          | bond   | up            |
+-------------------+--------+---------------+
```

### Port Field Population
- ✅ `ifName` - Interface name
- ✅ `ifDescr` - Description
- ✅ `ifSpeed` - Speed in bps
- ✅ `ifAdminStatus` - Administrative status (up/down)
- ✅ `ifOperStatus` - Operational status (up/down)
- ✅ `ifPhysAddress` - MAC address
- ✅ `ifMtu` - MTU value
- ✅ `ifType` - Interface type
- ✅ `ifAlias` - IP address
- ✅ `ifVlan` - VLAN ID (for tagged interfaces)

### Typical Port Count Per Array
- **Physical interfaces**: ~20-40 (ct*.eth*)
- **VLAN subinterfaces**: Variable (ct*.eth*.*)
- **Virtual interfaces**: 2-10 (vir*)
- **Bonds**: 1 (replbond)
- **Total**: 25-60 valid network interfaces

## Troubleshooting

### Issue: Ports still showing hardware components

**Check if fix was applied:**
```bash
grep -A 20 "isNonNetworkInterface" app/RestApi/Data/DataRouter.php
```

If method doesn't exist, the file wasn't updated correctly.

### Issue: No ports showing after cleanup

**Verify devices exist:**
```sql
SELECT device_id, hostname FROM devices WHERE os = 'purestorage';
```

**Check discovery logs:**
```bash
tail -f logs/librenms.log | grep -i "pure\|router\|port"
```

**Manually rediscover:**
```bash
./discovery.php -h YOUR_HOSTNAME -m restapi -d -v
```

### Issue: Ports still have NULL values

This means the REST API mappings aren't working. Check:

1. **Mapping table has entries:**
```sql
SELECT COUNT(*) FROM rest_api_metric_field_mappings WHERE librenms_table = 'ports';
```

2. **Run the mapping seeder:**
```bash
php artisan db:seed --class=PureStorageMappingsSeeder
```

3. **Check API connectivity:**
```bash
# Test Pure Storage API
curl -k -H "api-token: YOUR_TOKEN" https://ARRAY_IP/api/2.XX/network-interfaces
```

## Rolling Back

If you need to restore the original state:

```bash
# Restore code
cp app/RestApi/Data/DataRouter.php.backup app/RestApi/Data/DataRouter.php

# Restore database
mysql librenms << EOF
DELETE FROM ports WHERE device_id IN (SELECT device_id FROM devices WHERE os = 'purestorage');
INSERT INTO ports SELECT * FROM ports_backup_YYYYMMDD;
EOF
```

## Testing Checklist

- [ ] Code updated with fixed `DataRouter.php`
- [ ] Database backup created
- [ ] Invalid port entries removed
- [ ] Devices rediscovered successfully
- [ ] Only valid network interfaces in ports table
- [ ] No hardware components in ports table
- [ ] No VM/host entries in ports table
- [ ] Port fields populated (speed, status, MAC, etc.)
- [ ] Ports visible in LibreNMS web UI
- [ ] Port graphs working

## Additional Notes

### Where Hardware Components Should Go

Hardware components like fans, temperatures, power supplies, and blade bays should be discovered via the hardware endpoints and stored in:
- **`entPhysical` table** - Physical inventory (chassis, controllers, blades, PSUs)
- **`sensors` table** - Environmental sensors (temperature, voltage, fan speed)

These are handled by separate REST API endpoints like `/hardware`, `/controllers`, etc., and should NOT appear in the ports table.

### Pure Storage Interface Naming Convention

**Controller interfaces:**
- `ct0.eth0` - Controller 0, Ethernet port 0
- `ct1.eth18` - Controller 1, Ethernet port 18

**VLAN subinterfaces:**
- `ct0.eth18.313` - Controller 0, port 18, VLAN 313
- Format: `ct{N}.eth{PORT}.{VLAN_ID}`

**Virtual interfaces:**
- `vir0`, `vir1`, etc. - Virtual interfaces for internal communication
- Can have VLANs: `vir4.8`

**Replication bond:**
- `replbond` - Aggregated link for array-to-array replication

### Performance Considerations

After the fix, you should see reduced:
- Database bloat (fewer bogus port entries)
- Discovery time (fewer items to process)
- Memory usage (less data to store)
- Query performance (smaller ports table)

### Future Improvements

Consider adding to `DataRouter.php`:
1. More granular resource type detection
2. Validation of interface metrics before storage
3. Better logging of filtered items
4. Configuration option to enable/disable filtering

## Support

If you encounter issues:

1. Check logs: `tail -f /opt/librenms/logs/librenms.log`
2. Run discovery with debug: `./discovery.php -h HOSTNAME -m restapi -d -v`
3. Verify API endpoints are returning data
4. Check database for orphaned entries
5. Review this documentation

## Summary

This fix ensures that only actual network interfaces (ethernet ports, VLANs, virtual interfaces, and bonds) are added to the ports table. Hardware monitoring components and VM inventory are properly filtered out and should be discovered through their respective endpoints.

**Expected outcome:**
- Clean ports table with only network interfaces
- Proper port statistics and status
- No hardware components mixed with network interfaces
- Better performance and usability in LibreNMS UI
