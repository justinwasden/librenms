# Pure Storage Ports - Quick Fix Guide

## TL;DR

**Problem:** Pure Storage ports table has hardware components, VMs, and all fields are NULL.

**Solution:** Run the complete fix script.

## Quick Fix (3 minutes)

```bash
cd /opt/librenms

# 1. Run the complete fix
chmod +x scripts/complete_purestorage_fix.sh
sudo -u librenms ./scripts/complete_purestorage_fix.sh

# 2. Verify results
mysql librenms -e "
SELECT d.hostname, COUNT(*) as ports,
  SUM(CASE WHEN p.ifSpeed IS NOT NULL THEN 1 ELSE 0 END) as populated
FROM ports p JOIN devices d ON p.device_id = d.device_id  
WHERE d.os = 'purestorage' GROUP BY d.hostname;
"
```

## What It Does

1. Backs up existing ports
2. Deletes all Pure Storage ports
3. Rediscovers with proper filtering
4. Shows results

## Expected Result

**Before:**
- 152 ports (including hardware, VMs)
- All fields NULL

**After:**
- ~35 ports per device (only network interfaces)
- Fields populated: ifSpeed, ifAdminStatus, ifPhysAddress, ifMtu, ifType

## If Fields Are Still NULL

The discovery filtering is working, but port fields aren't populating. This means we need to check the MetricsStager:

```bash
# Find the stager
find app/ -name "*MetricsStager*" -type f

# Check if it calls DataRouter
grep -A 10 "function stageMetrics" app/RestApi/Metrics/MetricsStager.php
```

Look for a call to `DataRouter::route()` - if it's missing, that's why fields are NULL.

## Files Modified

- `/app/Discovery/RestApiDiscovery.php` - Filters at discovery level
- `/app/RestApi/Data/DataRouter.php` - Filters at routing level
- `/database/seeders/PureStorageMappingsSeeder.php` - Field mappings
- `/scripts/complete_purestorage_fix.sh` - Automated fix

## Full Documentation

See `/FINAL_STATUS_AND_NEXT_STEPS.md` for complete details.

## Rollback

```bash
mysql librenms << EOF
DELETE FROM ports WHERE device_id IN (SELECT device_id FROM devices WHERE os = 'purestorage');
INSERT INTO ports SELECT * FROM ports_backup_before_final_fix;
EOF
```

## Next Steps After Fix

1. ✅ Verify only valid interfaces exist
2. ✅ Check if fields are populated
3. ⚠️ If fields still NULL, investigate MetricsStager
4. ⚠️ Check if stager calls DataRouter for updates

## Support

Run diagnostics:
```bash
mysql librenms < scripts/diagnose_purestorage_ports.sql
```

Check logs:
```bash
tail -f logs/librenms.log | grep -i "pure\|port\|mapping"
```
