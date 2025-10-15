# Pure Storage Ports - Final Fix Applied

## What Was Fixed

### Issue 1: Invalid Entries in Ports Table ✅ FIXED
- Hardware components, chassis elements, and VM inventory were being added as ports
- **Root Cause**: No filtering at discovery level
- **Solution**: Added `shouldFilterPureStorageItem()` method to `/app/Discovery/RestApiDiscovery.php`
- **Result**: Only valid network interfaces (ct*.eth*, vir*, replbond) are now discovered

### Issue 2: Port Fields Not Populating ⚠️ IN PROGRESS
- Mappings exist and are matching (confirmed by `last_seen_at` timestamps)
- Data is being collected (15 unique metrics per device)
- But port fields remain NULL
- **Likely Cause**: Timing issue or stager not calling DataRouter to update existing ports

## Files Modified

1. **`/app/Discovery/RestApiDiscovery.php`** ✅
   - Added `shouldFilterPureStorageItem()` method
   - Filters items BEFORE creating ports
   - Prevents hardware/VM entries from ever being discovered

2. **`/app/RestApi/Data/DataRouter.php`** ✅
   - Added `isNonNetworkInterface()` method
   - Filters during metric routing (secondary defense)

3. **`/database/seeders/PureStorageMappingsSeeder.php`** ✅
   - Defines all port field mappings
   - Maps API fields to LibreNMS port fields

## Current Status

### What's Working ✅
- Discovery filtering prevents invalid entries
- Mappings are defined and matching API fields
- API data is being collected (15 metrics per device)
- Mapping engine is finding matches (last_seen_at timestamps show recent matches)

### What's Not Working ⚠️
- Port fields still NULL after discovery
- 152 total ports but 0 have speed/status/MAC/MTU populated

## Next Steps

### Step 1: Clean and Rediscover
```bash
cd /opt/librenms
chmod +x scripts/complete_purestorage_fix.sh
sudo -u librenms ./scripts/complete_purestorage_fix.sh
```

This will:
1. Backup existing ports
2. Delete ALL Pure Storage ports
3. Clear old API metrics
4. Rediscover with new filtering
5. Show results

### Step 2: Check MetricsStager
The issue might be in how `MetricsStager` handles port creation and updates. It may be:
- Creating ports but not calling DataRouter to populate fields
- Not passing itemContext correctly
- Using a different code path for initial creation vs updates

### Step 3: Debug Discovery
```bash
# Run with full debug
./discovery.php -h 172.16.7.40 -m restapi -d -v 2>&1 | tee /tmp/debug.log

# Check for these in the log:
grep -i "stageMetrics\|DataRouter\|ifSpeed\|ifAdminStatus" /tmp/debug.log
```

Look for:
- Are ports being created?
- Is DataRouter being called?
- Are mappings matching?
- Are values being written?

## Expected Final Result

After the complete fix:

```sql
SELECT d.hostname, COUNT(*) as ports,
  SUM(CASE WHEN p.ifSpeed IS NOT NULL THEN 1 ELSE 0 END) as with_speed
FROM ports p
JOIN devices d ON p.device_id = d.device_id  
WHERE d.os = 'purestorage'
GROUP BY d.hostname;
```

Should show:
```
+-------------+-------+------------+
| hostname    | ports | with_speed |
+-------------+-------+------------+
| 172.16.7.40 |    36 |         36 |
| 172.16.7.5  |    31 |         31 |
+-------------+-------+------------+
```

And no invalid entries:
```sql
SELECT ifName FROM ports 
WHERE device_id IN (SELECT device_id FROM devices WHERE os = 'purestorage')
AND ifName NOT REGEXP '^(ct[0-9]\.eth[0-9]|vir[0-9]|replbond)';
```

Should return: **Empty set**

## Architecture Notes

### Discovery Flow
```
RestApiDiscovery
  ↓
  shouldFilterPureStorageItem() [NEW - filters before processing]
  ↓
  MetricsStager.stageMetrics()
  ↓
  DataRouter.route()
  ↓
  isNonNetworkInterface() [secondary filter]
  ↓
  storeUsingMapping()
  ↓
  storeInPortsTable()
```

### Why Two Filters?
1. **RestApiDiscovery filter**: Prevents invalid items from being discovered at all (primary)
2. **DataRouter filter**: Prevents metric updates on invalid ports (secondary/safety net)

## Troubleshooting

### If ports are still not populated after complete fix:

1. **Check if MetricsStager is the issue:**
```bash
grep -r "class MetricsStager" app/
```

The stager might need to be modified to actually call DataRouter for port updates.

2. **Check if ports are being created without going through DataRouter:**
```bash
grep -r "new Port()" app/
grep -r "Port::create" app/
```

3. **Enable more debug logging:**
```php
// In DataRouter.php storeInPortsTable()
Log::info("UPDATING PORT: {$portName} field {$field} = {$value}");
```

4. **Check database directly after one discovery:**
```sql
-- Check if ports were created
SELECT COUNT(*) FROM ports WHERE device_id = 2;

-- Check if metrics were collected  
SELECT COUNT(*) FROM rest_api_metrics WHERE device_id = 2;

-- Check if mappings matched
SELECT api_field_name, last_seen_at 
FROM rest_api_metric_field_mappings 
WHERE last_matched_device_id IN (2,3);
```

## Summary

**What's fixed:**
- ✅ Invalid entry filtering at discovery level
- ✅ Secondary filtering in DataRouter
- ✅ Mappings defined and matching
- ✅ API data collection working

**What needs investigation:**
- ⚠️ Why DataRouter isn't updating port fields even though mappings match
- ⚠️ Whether MetricsStager calls DataRouter for port updates
- ⚠️ Flow from discovery → stager → router → database

Run the complete fix script and if ports are still NULL, we need to check the MetricsStager code to see if it's actually routing the metrics to DataRouter for database updates.
