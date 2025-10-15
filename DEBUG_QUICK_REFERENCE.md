# Pure Storage Debug - Quick Reference Card

## One Command to Debug Everything
```bash
cd /opt/librenms && chmod +x scripts/debug_discovery.sh && ./scripts/debug_discovery.sh 172.16.7.40
```

## What You'll See (Expected Output)

### ✅ SUCCESS Indicators
```
Items Discovered: ~70
Items Filtered: ~40        ← Hardware/VMs removed
Metrics Staged: ~30        ← One per valid interface
Mappings Found: ~270       ← 9 mappings × 30 interfaces
Port Updates: Many         ← Actual database writes
Errors: None

Port Count:
  total: 36
  with_speed: 36          ← Fields populated!
  with_status: 36
  with_mac: 36
```

### ❌ PROBLEM Indicators
```
Items Filtered: 0          ← Filtering not working
Mappings Found: 0          ← No mappings in database
Port Updates: None         ← DataRouter not updating
Errors: Multiple           ← Check log for details
```

## Quick Checks

### Are ports clean?
```sql
SELECT ifName FROM ports WHERE device_id = 2 AND ifName LIKE '%ESXI%';
```
**Expected**: Empty set

### Are fields populated?
```sql
SELECT COUNT(*) as null_speed FROM ports WHERE device_id = 2 AND ifSpeed IS NULL;
```
**Expected**: 0

### Are mappings working?
```sql
SELECT api_field_name, last_seen_at FROM rest_api_metric_field_mappings 
WHERE librenms_table = 'ports' ORDER BY last_seen_at DESC;
```
**Expected**: Recent timestamps

## Log File Locations
- Discovery debug: `/tmp/pure_discovery_debug_YYYYMMDD_HHMMSS.log`
- LibreNMS log: `/opt/librenms/logs/librenms.log`

## Grep Patterns for Log Analysis
```bash
LOG="/tmp/pure_discovery_debug_*.log"

# Check filtering
grep "Filtering\|Skipping" $LOG

# Check mappings
grep "✓.*-> ports\\." $LOG

# Check updates
grep "ports\\.(ifSpeed|ifAdminStatus)" $LOG

# Check errors
grep -i "error\|failed" $LOG
```

## Common Issues & Fixes

| Issue | Check | Fix |
|-------|-------|-----|
| Invalid entries in ports | `SELECT ifName FROM ports WHERE ifName LIKE '%ESXI%'` | Run `complete_purestorage_fix.sh` |
| Fields NULL | `grep "-> ports\\." $LOG` | Seed mappings: `php artisan db:seed --class=PureStorageMappingsSeeder` |
| No debug output | `grep METRICS\ STAGER $LOG` | Set log_level='debug' in config.php |
| Mappings not found | `SELECT COUNT(*) FROM rest_api_metric_field_mappings` | Run seeder |

## Files You Need

### To Fix
- `scripts/complete_purestorage_fix.sh` - Complete fix
- `scripts/debug_discovery.sh` - Debug run

### To Check
- `scripts/diagnose_purestorage_ports.sql` - DB diagnostics
- `scripts/validate_purestorage_ports.sh` - Validation

### To Read
- `DEBUG_DISCOVERY_GUIDE.md` - How to read debug output
- `COMPLETE_SOLUTION_WITH_DEBUG.md` - Full solution

## Decision Tree

```
Run debug_discovery.sh
    ↓
Are invalid items filtered?
    NO → Check RestApiDiscovery.php filtering
    YES → ↓
Are mappings found (see "✓")?
    NO → Run PureStorageMappingsSeeder
    YES → ↓
Are updates happening?
    NO → Check DataRouter storeInPortsTable()
    YES → ↓
Are fields populated in DB?
    NO → Check for errors in log
    YES → SUCCESS!
```

## Contact/Support
- Full docs: `/docs/PURESTORAGE_PORTS_FIX_GUIDE.md`
- Debug guide: `/DEBUG_DISCOVERY_GUIDE.md`
- Complete solution: `/COMPLETE_SOLUTION_WITH_DEBUG.md`

## Quick Commands
```bash
# Full fix
./scripts/complete_purestorage_fix.sh

# Debug discovery
./scripts/debug_discovery.sh 172.16.7.40

# Validate
./scripts/validate_purestorage_ports.sh

# Diagnose
mysql librenms < scripts/diagnose_purestorage_ports.sql
```
