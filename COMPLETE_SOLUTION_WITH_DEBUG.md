# Pure Storage Ports - Complete Solution with Debug Tools

## Summary

I've fixed the Pure Storage ports discovery issues and added comprehensive debugging tools to track the complete flow from API response to database.

## What Was Fixed

### 1. Invalid Entries Filtering ✅
- **Added**: `shouldFilterPureStorageItem()` in `RestApiDiscovery.php`
- **Added**: `isNonNetworkInterface()` in `DataRouter.php`
- **Result**: Hardware components and VMs are now filtered BEFORE port creation

### 2. Comprehensive Debug Logging ✅
- **Enhanced**: `MetricsStager.php` with detailed flow logging
- **Shows**: Complete data flow from API → Stager → Router → Database
- **Tracks**: Every metric, mapping, transformation, and update

## Files Modified

### Core Fixes
1. **`/app/Discovery/RestApiDiscovery.php`**
   - Added early filtering to prevent invalid discoveries
   
2. **`/app/RestApi/Data/DataRouter.php`**
   - Added secondary filtering for safety
   - Enhanced with debug logging

3. **`/app/RestApi/Metrics/MetricsStager.php`**
   - Added comprehensive debug output
   - Shows all metrics and transformations

### Scripts Created
1. **`/scripts/debug_discovery.sh`** ⭐ **USE THIS**
   - Run discovery with full debug logging
   - Automatic analysis and summary
   - Usage: `./scripts/debug_discovery.sh 172.16.7.40`

2. **`/scripts/complete_purestorage_fix.sh`**
   - Complete cleanup and rediscovery
   - Creates backups automatically

3. **`/scripts/diagnose_purestorage_ports.sql`**
   - Comprehensive database diagnostics

4. **`/scripts/validate_purestorage_ports.sh`**
   - Validation after fixes

### Documentation
1. **`/DEBUG_DISCOVERY_GUIDE.md`** ⭐ **READ THIS**
   - Complete guide to understanding debug output
   - Troubleshooting guide
   - What to look for in logs

2. **`/QUICK_FIX_GUIDE.md`**
   - Fast 3-minute fix

3. **`/FINAL_STATUS_AND_NEXT_STEPS.md`**
   - Current status
   - Architecture notes

## How to Use

### Step 1: Run Debug Discovery

```bash
cd /opt/librenms
chmod +x scripts/debug_discovery.sh
./scripts/debug_discovery.sh 172.16.7.40
```

This will:
- Run discovery with full debug logging
- Save complete log to `/tmp/pure_discovery_debug_*.log`
- Show automatic analysis
- Display current port status

### Step 2: Analyze the Output

Look for these sections in the output:

**A. Items Filtered**
```
Filtering VM/host from discovery: ALM-C220-ESXI-01
Filtering hardware component from discovery: CH0.BAY0
```
✅ Should see ~40 items filtered

**B. Metrics Staged**
```
METRICS STAGER - START
[network-interfaces] Device: 172.16.7.40
[network-interfaces] Item Context:
[network-interfaces]   name: ct0.eth0
[network-interfaces] Sample Metrics:
[network-interfaces]   speed = 10000000000
[network-interfaces]   enabled = true
```
✅ Should see ~30-36 items (one per interface)

**C. Mappings Found**
```
✓ [network-interfaces] speed -> ports.ifSpeed
✓ [network-interfaces] enabled -> ports.ifAdminStatus
✓ [network-interfaces] eth_mac_address -> ports.ifPhysAddress
```
✅ Should see "✓" for 7-9 mappings per interface

**D. Database Updates**
```
[network-interfaces] speed -> ports.ifSpeed (port: ct0.eth0) = 10000000000
[network-interfaces] enabled -> ports.ifAdminStatus (port: ct0.eth0) = up
```
✅ Should see actual values being written

### Step 3: Check Results

```bash
mysql librenms -e "
SELECT d.hostname, COUNT(*) as ports,
  SUM(CASE WHEN p.ifSpeed IS NOT NULL THEN 1 ELSE 0 END) as populated
FROM ports p JOIN devices d ON p.device_id = d.device_id  
WHERE d.os = 'purestorage' GROUP BY d.hostname;
"
```

Expected:
```
+-------------+-------+-----------+
| hostname    | ports | populated |
+-------------+-------+-----------+
| 172.16.7.40 |    36 |        36 |
| 172.16.7.5  |    31 |        31 |
+-------------+-------+-----------+
```

## What the Debug Output Shows

### 1. Discovery Level (RestApiDiscovery)
- API endpoint called
- Raw JSON response
- Items before filtering
- Items filtered out
- Items passed to stager

### 2. Stager Level (MetricsStager)
- Complete metric list received
- Item context (name, id, index)
- Resource type
- Endpoint name
- Call to DataRouter

### 3. Router Level (DataRouter)
- Each metric processed
- Mapping lookup result
- Transformation applied
- Database table targeted
- Success/failure of update

### 4. Result Verification
- Port counts
- Field population statistics
- Sample port data
- Invalid entries check

## Troubleshooting Guide

### Issue: Fields Still NULL After Discovery

**Check in debug log:**
1. Are items being discovered? Look for "METRICS STAGER - START"
2. Are mappings found? Look for "✓" symbols
3. Are updates happening? Look for "ports.ifSpeed ="
4. Are there errors? Look for "ERROR" or "Failed"

**Common causes:**
- Mappings don't exist: Run `PureStorageMappingsSeeder`
- Field names don't match: API returns `interface_speed` but mapping expects `speed`
- Transformation failing: Check `transformValue()` in mapping model
- Database permissions: Check Laravel can write to ports table

### Issue: Invalid Items Still Being Discovered

**Check in debug log:**
1. Look for "Filtering" messages - should see hardware/VMs being filtered
2. Check if items like "ALM-C220-ESXI" appear in "Processing item:"
3. Verify device OS: `SELECT os FROM devices WHERE hostname = '172.16.7.40';`

**If still appearing:**
- RestApiDiscovery filtering may not be running
- Check `shouldFilterPureStorageItem()` is being called
- Verify `$resourceType` is 'network-interface' or 'port'

### Issue: No Debug Output

**Problem**: Debug logging not working

**Solution:**
```bash
# Check log level
grep log_level config.php

# Should be 'debug' or 'info'
# If not, add to config.php:
$config['log_level'] = 'debug';
```

## Complete Flow Diagram

```
API Response
    ↓
RestApiDiscovery::processMultiItemResponse()
    ↓
shouldFilterPureStorageItem() ← [Filters hardware/VMs]
    ↓ (if valid)
MetricsStager::stageMetrics()
    ↓ [Shows all metrics received]
DataRouter::route()
    ↓
MappingEngine::findMapping() ← [Looks up mapping]
    ↓ (if found)
DataRouter::storeUsingMapping()
    ↓
mapping->transformValue() ← [Transforms value]
    ↓
DataRouter::storeInPortsTable()
    ↓
Port::update(['ifSpeed' => value]) ← [Updates database]
    ↓
SUCCESS
```

## Next Steps After Debug Discovery

### If Everything Works:
```bash
# Clean up and do full rediscovery
./scripts/complete_purestorage_fix.sh
```

### If Fields Are NULL:
1. Check debug log for mapping matches
2. Verify mappings exist in database
3. Check if storeInPortsTable() is being called
4. Look for error messages

### If Invalid Items Appear:
1. Check filtering messages in debug log
2. Verify device OS detection
3. Check resource type being used
4. Review shouldFilterPureStorageItem() patterns

## All Available Tools

1. **`debug_discovery.sh`** - Main debug tool with full analysis
2. **`complete_purestorage_fix.sh`** - Complete fix with backup
3. **`diagnose_purestorage_ports.sql`** - Database diagnostics
4. **`validate_purestorage_ports.sh`** - Post-fix validation
5. **`cleanup_purestorage_ports.sql`** - SQL-only cleanup

## Support Files

- **`DEBUG_DISCOVERY_GUIDE.md`** - Detailed guide to debug output
- **`QUICK_FIX_GUIDE.md`** - Fast fix instructions  
- **`FINAL_STATUS_AND_NEXT_STEPS.md`** - Architecture and status
- **`PURESTORAGE_PORTS_FIX_GUIDE.md`** - Complete documentation

## Summary

**Run this one command to see everything:**
```bash
./scripts/debug_discovery.sh 172.16.7.40
```

The debug output will tell you EXACTLY:
- What items are being discovered
- What items are being filtered
- What metrics are being collected
- What mappings are being found
- What values are being written
- What errors are occurring

You'll have complete visibility into the entire process!
