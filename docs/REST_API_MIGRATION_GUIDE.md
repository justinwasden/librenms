# Quick Migration Guide - REST API Change Detection

## Prerequisites
- LibreNMS installation with REST API polling module
- Database access for running migrations
- PHP CLI access for artisan commands

## Step-by-Step Migration

### 1. Backup Your Database (Recommended)
```bash
# Backup the device_api_metrics table
mysqldump -u [user] -p [database] device_api_metrics > device_api_metrics_backup.sql
```

### 2. Run the Migration
```bash
cd /path/to/librenms
php artisan migrate
```

Expected output:
```
Migrating: 2025_10_03_172136_create_device_api_metrics_history_table
Migrated:  2025_10_03_172136_create_device_api_metrics_history_table (XX.XXms)
```

### 3. Verify the Changes
```bash
# Check if history table was created
mysql -u [user] -p -e "DESCRIBE device_api_metrics_history" [database]

# Test the poller with debug output
./poller.php -h [device-id] -m rest-api -d
```

### 4. What You'll See in the Logs

#### First Poll After Migration
```
Processing 1 items for endpoint Array Info
New metric capacity = 25734521290752 for resource RSA-PS-X50
Inserted 14 new metrics for unknown 'RSA-PS-X50'
```

#### Subsequent Polls (No Changes)
```
Processing 1 items for endpoint Array Info
Metric capacity unchanged for resource RSA-PS-X50
Metric parity unchanged for resource RSA-PS-X50
...
```

#### When Metrics Change
```
Metric space.total_used changed from 10283894774613 to 10285000000000 for resource RSA-PS-X50
Updated 1 changed metrics for unknown 'RSA-PS-X50'
```

#### When Resources Are Removed
```
Removed 6 metrics for 1 stale resources from endpoint Controllers Status: CT2
```

### 5. Set Up History Cleanup (Optional but Recommended)

Add to your crontab or Laravel scheduler:

**Option A: Crontab**
```bash
# Run cleanup daily at 3 AM, keep 30 days of history
0 3 * * * cd /opt/librenms && php artisan api-metrics:cleanup-history --days=30
```

**Option B: Laravel Scheduler (app/Console/Kernel.php)**
```php
protected function schedule(Schedule $schedule)
{
    // ... existing schedules ...
    
    // Clean up API metrics history older than 30 days, daily at 3 AM
    $schedule->command('api-metrics:cleanup-history --days=30')
             ->dailyAt('03:00');
}
```

### 6. Monitor Performance

#### Check Database Size
```sql
-- Check main metrics table size
SELECT 
    COUNT(*) as total_metrics,
    COUNT(DISTINCT device_id) as total_devices,
    COUNT(DISTINCT resource_id) as total_resources
FROM device_api_metrics;

-- Check history table growth
SELECT 
    COUNT(*) as total_history_records,
    DATE(collected_at) as date,
    COUNT(*) as records_per_day
FROM device_api_metrics_history
GROUP BY DATE(collected_at)
ORDER BY date DESC
LIMIT 7;
```

#### Monitor Database Operations
Compare before and after:
```bash
# Before migration (watch the SQL operations in debug mode)
./poller.php -h [device-id] -m rest-api -d 2>&1 | grep -c "^SQL\["

# After migration (should see fewer operations)
./poller.php -h [device-id] -m rest-api -d 2>&1 | grep -c "^SQL\["
```

## Troubleshooting

### Issue: Migration Fails
**Error**: "Table 'device_api_metrics_history' already exists"
```bash
# Check if table exists
mysql -u [user] -p -e "SHOW TABLES LIKE 'device_api_metrics_history'" [database]

# If it exists but migration didn't record it, manually mark it as migrated
php artisan migrate:status
```

### Issue: Poller Shows Errors
**Error**: "SQLSTATE[42S02]: Base table or view not found: 'device_api_metrics_history'"
```bash
# Verify migration ran successfully
php artisan migrate:status | grep device_api_metrics_history

# Re-run migration if needed
php artisan migrate
```

### Issue: Too Many "Changed" Notifications
**Symptom**: Every metric shows as changed on every poll

**Causes & Solutions**:
1. **Floating point precision**: Metrics like data reduction ratios may vary slightly
   - Already handled with 0.0001 tolerance in code
   
2. **API returns different precision**: Check your API response
   - Adjust tolerance in `Api.php` line ~232 if needed

3. **String value changes**: Check if API returns different formats
   - Review debug logs to see actual values

### Issue: History Table Growing Too Fast
```bash
# Check growth rate
mysql -u [user] -p [database] -e "
SELECT 
    DATE(collected_at) as date,
    COUNT(*) as changes_per_day,
    COUNT(*) * 100.0 / (SELECT COUNT(*) FROM device_api_metrics) as percent_of_total
FROM device_api_metrics_history 
GROUP BY DATE(collected_at) 
ORDER BY date DESC 
LIMIT 7;"

# Reduce retention if needed
php artisan api-metrics:cleanup-history --days=7
```

## Rollback (If Needed)

If you need to revert to the old behavior:

### 1. Keep Using Old Code
```bash
# Revert the Api.php file
git checkout HEAD~1 app/Pollers/Api.php
```

### 2. Optional: Remove History Table
```bash
# Drop the history table (only if not needed)
mysql -u [user] -p [database] -e "DROP TABLE IF EXISTS device_api_metrics_history"

# Mark migration as rolled back
php artisan migrate:rollback --step=1
```

### 3. Restore from Backup (If Needed)
```bash
mysql -u [user] -p [database] < device_api_metrics_backup.sql
```

## Success Criteria

You'll know the migration was successful when:

✅ History table exists and is being populated
✅ Logs show "unchanged" messages for static metrics
✅ Logs show "changed from X to Y" for changing metrics
✅ Database operations reduced (check with `-d` debug flag)
✅ Stale resources are automatically cleaned up
✅ Trending data available in history table

## Performance Metrics to Track

### Before Migration
- SQL operations per poll: ~52 (for 26 metrics)
- All metrics deleted and reinserted every poll
- No historical data retention

### After Migration
- SQL operations per poll: ~26 (first poll) → ~4-10 (typical)
- Only changed metrics updated
- Historical trends preserved in separate table
- Automatic stale resource cleanup

## Getting Help

If you encounter issues:

1. **Check debug logs**: `./poller.php -h [device-id] -m rest-api -d`
2. **Review documentation**: `REST_API_CHANGE_DETECTION.md`
3. **Test with dry-run**: `php artisan api-metrics:cleanup-history --dry-run`
4. **Verify database schema**: Compare with migration file
5. **Check file modifications**: Ensure Api.php has all changes

## Next Steps After Migration

1. ✅ Verify poller is working correctly
2. ✅ Monitor log output for accuracy  
3. ✅ Set up history cleanup schedule
4. ✅ Create dashboards using history data
5. ✅ Adjust retention policies as needed
6. ✅ Document any custom configurations

---

**Migration Date**: _________________
**Migrated By**: _________________
**Notes**: _________________
