# Pure Storage Ports Fix - Quick Reference

## What Was Fixed

**Problem:** Hardware components (blade bays, NVMe backplane, power supplies, chassis, VMs) were being added to the `ports` table as if they were network interfaces.

**Solution:** Added filtering in `DataRouter.php` to only allow actual network interfaces.

## Quick Fix Steps

### 1. Update Code
```bash
cd /opt/librenms
# The fixed DataRouter.php is already in your repo at:
# app/RestApi/Data/DataRouter.php
```

### 2. Clean Database
```bash
# Run the cleanup script
mysql librenms < scripts/cleanup_purestorage_ports.sql
```

### 3. Rediscover
```bash
# For each Pure Storage device:
./discovery.php -h YOUR_ARRAY_IP -m restapi -d
./poller.php -h YOUR_ARRAY_IP -d
```

## What You Should See

### ✅ Valid Ports (KEEP)
- `ct0.eth0`, `ct0.eth1`, `ct1.eth0` - Physical ethernet
- `ct0.eth18.313`, `ct0.eth18.314` - VLAN subinterfaces
- `vir0`, `vir1`, `vir4` - Virtual interfaces
- `replbond` - Replication bond

### ❌ Invalid Entries (REMOVE)
- `CH0.BAY*` - Blade bays
- `CH0.NVB*` - NVMe backplane
- `CH0.PWR*` - Power supplies
- `CH0.TMP*`, `CT*.FAN*` - Sensors
- `CH0`, `CT0`, `CT1` - Chassis/controllers
- `*-ESXI-*`, `RSA-*`, etc. - VMs/hosts

## Verification Query

```sql
SELECT 
    d.hostname,
    COUNT(*) as total_ports,
    GROUP_CONCAT(DISTINCT SUBSTRING_INDEX(p.ifName, '.', 1) ORDER BY p.ifName) as interface_types
FROM ports p
JOIN devices d ON p.device_id = d.device_id
WHERE d.os = 'purestorage'
GROUP BY d.hostname;
```

Expected result: Only see `ct0`, `ct1`, `vir`, `replbond` in interface_types.

## Files Changed

- ✅ `/app/RestApi/Data/DataRouter.php` - Added `isNonNetworkInterface()` method
- ✅ `/scripts/cleanup_purestorage_ports.sql` - Database cleanup script
- ✅ `/scripts/fix_purestorage_ports.sh` - Automated fix script
- ✅ `/docs/PURESTORAGE_PORTS_FIX_GUIDE.md` - Complete documentation

## One-Liner Fix

```bash
cd /opt/librenms && \
mysql librenms < scripts/cleanup_purestorage_ports.sql && \
for dev in $(mysql librenms -N -e "SELECT hostname FROM devices WHERE os='purestorage'"); do \
  ./discovery.php -h $dev -m restapi && ./poller.php -h $dev; \
done
```

## Rollback

If something goes wrong:
```bash
mysql librenms -e "DELETE FROM ports WHERE device_id IN (SELECT device_id FROM devices WHERE os = 'purestorage');"
mysql librenms -e "INSERT INTO ports SELECT * FROM ports_backup_YYYYMMDD;"
```

## Support

Full documentation: `/docs/PURESTORAGE_PORTS_FIX_GUIDE.md`
