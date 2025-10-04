# REST API Overview - Implementation Checklist

## ✅ Completed

### Core Files Created
- [x] `/includes/html/pages/device/overview/rest-api.inc.php` - Main router
- [x] `/includes/html/pages/device/overview/rest-api/purestorage.inc.php` - PureStorage layout
- [x] `/includes/html/pages/device/overview/rest-api/generic.inc.php` - Generic fallback

### Integration
- [x] Modified `/includes/html/pages/device/overview.inc.php` to include REST API overview

### Documentation
- [x] Created `REST_API_OVERVIEW_IMPLEMENTATION.md` - Full implementation guide
- [x] Created visual mockup showing expected appearance

## 📋 Next Steps (Optional Enhancements)

### 1. Test the Implementation
```bash
# Navigate to your LibreNMS device overview
# URL: http://your-librenms/device/device=<device_id>/tab=overview/

# Should see REST API panels if:
# - Device has REST API connection enabled
# - Metrics have been collected
```

### 2. Verify Database Has Metrics
```bash
php artisan tinker
```

```php
// Check metrics exist
DB::table('device_api_metrics')->where('device_id', 1)->count();

// Check what resource types are available
DB::table('device_api_metrics')
    ->where('device_id', 1)
    ->distinct()
    ->pluck('resource_type');
```

### 3. Add Graphing Support (Future Enhancement)
- [ ] Create custom graph types for REST API metrics
- [ ] Add mini-graphs to overview panels
- [ ] Create `/includes/html/graphs/device/rest_api_*.inc.php` files

### 4. Create Additional Vendor Overviews
- [ ] NetApp (`/overview/rest-api/netapp.inc.php`)
- [ ] HPE 3PAR (`/overview/rest-api/hpe3par.inc.php`)
- [ ] Dell EMC (`/overview/rest-api/dellemc.inc.php`)
- [ ] Nimble (`/overview/rest-api/nimble.inc.php`)

### 5. Add Alert Integration
- [ ] Show active alerts for REST API metrics
- [ ] Highlight resources with warnings/errors
- [ ] Link to alert details

## 🔍 Testing Checklist

### For PureStorage Devices
- [ ] Array capacity displays correctly
- [ ] Capacity bar shows proper percentage
- [ ] Data reduction ratio is accurate
- [ ] Volume table shows top 10 volumes
- [ ] IOPS metrics display (Read/Write/Total)
- [ ] Host connections table populates
- [ ] Network interfaces show with speeds
- [ ] All timestamps show "X minutes/hours ago"

### For Other Devices (Generic Overview)
- [ ] Automatically detects resource types
- [ ] Groups metrics by resource type
- [ ] Shows up to 6 metrics per type
- [ ] Formats large numbers properly (bytes → GB/TB)
- [ ] Truncates long strings to 30 chars
- [ ] Shows "No metrics" message when empty

### Edge Cases
- [ ] Device with REST API disabled → No panels shown
- [ ] Device with no metrics collected → Info message shown
- [ ] Unknown OS type → Falls back to generic overview
- [ ] Mixed numeric/string values → Both display correctly

## 🐛 Common Issues & Solutions

### Issue: Panels Not Showing

**Diagnosis:**
```bash
# Check if REST API connection exists and is enabled
mysql -u librenms -p librenms -e \
  "SELECT * FROM rest_api_connections WHERE device_id=1;"
```

**Solution:**
- Enable REST API connection in device settings
- Ensure `enabled = 1` in rest_api_connections table

### Issue: "No metrics collected" Message

**Diagnosis:**
```bash
# Run polling manually
php lnms device:poll <device_id> -m rest-api -vv

# Check logs
tail -f /opt/librenms/logs/librenms.log | grep -i "rest api"
```

**Solution:**
- Verify REST API endpoints are configured
- Check API credentials are correct
- Ensure device is reachable

### Issue: Wrong Values Displayed

**Diagnosis:**
```bash
php artisan tinker
```

```php
// Check raw metric data
DB::table('device_api_metrics')
    ->where('device_id', 1)
    ->orderBy('collected_at', 'desc')
    ->limit(10)
    ->get(['resource_type', 'metric_name', 'value', 'string_value']);
```

**Solution:**
- Verify REST API endpoint metric mappings
- Check if values need unit conversion
- Update metric_name mapping in endpoint configuration

### Issue: PHP Errors in Logs

**Check:**
```bash
tail -f /opt/librenms/storage/logs/laravel.log
```

**Common fixes:**
- Ensure all `use` statements are present
- Check for typos in variable names
- Verify database column names match schema

## 📊 Performance Monitoring

### Monitor Query Performance
```sql
-- Check query execution time
EXPLAIN SELECT * FROM device_api_metrics 
WHERE device_id = 1 
  AND resource_type = 'volume'
ORDER BY collected_at DESC;

-- Verify indexes are being used
SHOW INDEX FROM device_api_metrics;
```

### Optimize if Needed
```sql
-- Add composite index if missing
CREATE INDEX idx_device_resource_collected 
ON device_api_metrics(device_id, resource_type, collected_at);

-- Consider partitioning for large datasets
-- Partition by collected_at for time-series data
```

## 🎨 Customization Guide

### Change Panel Colors

Edit the CSS in your vendor-specific file:

```php
<style>
.panel-default > .panel-heading {
    background-color: #your-color;
    color: white;
}
</style>
```

### Adjust Table Columns

Modify the SQL query to show different metrics:

```php
DB::raw('MAX(CASE WHEN metric_name = "your_metric" THEN value END) as your_column')
```

### Change Row Limits

Update LIMIT clause in queries:

```php
->limit(20) // Show top 20 instead of 10
```

### Add Custom Formatting

Use LibreNMS utilities:

```php
// For percentages
echo number_format($value, 2) . '%';

// For bytes
echo Number::formatBi($bytes);

// For timestamps
echo \Carbon\Carbon::parse($timestamp)->diffForHumans();

// For rates
echo number_format($iops) . ' IOPS';
```

## 📁 File Structure Reference

```
/includes/html/pages/device/
├── overview.inc.php                    (modified - includes rest-api)
└── overview/
    ├── rest-api.inc.php               (new - router)
    └── rest-api/
        ├── purestorage.inc.php        (new - PureStorage specific)
        ├── generic.inc.php            (new - generic fallback)
        └── [vendor].inc.php           (future - add more vendors)
```

## 🔗 Related Files

### Database Schema
- `/database/migrations/2025_10_01_161039_create_rest_api_metrics_table.php`

### Polling Logic
- `/app/Pollers/Api.php`

### Models
- `/app/Models/RestApiConnection.php`
- `/app/Models/RestApiEndpoint.php`

### Configuration
- `/resources/definitions/config_definitions.json`

## 📝 Adding a New Vendor

### Step-by-Step Process

1. **Create vendor file:**
```bash
touch /includes/html/pages/device/overview/rest-api/yourvendor.inc.php
```

2. **Copy template from generic.inc.php or purestorage.inc.php**

3. **Customize queries for your vendor:**
```php
<?php
use Illuminate\Support\Facades\DB;
use LibreNMS\Util\Number;

// Example: Get storage pool metrics
$pools = DB::table('device_api_metrics')
    ->where('device_id', $device['device_id'])
    ->where('resource_type', 'storage_pool')
    ->select([
        'resource_name',
        DB::raw('MAX(CASE WHEN metric_name = "total_capacity" THEN value END) as total'),
        DB::raw('MAX(CASE WHEN metric_name = "used_capacity" THEN value END) as used'),
    ])
    ->groupBy('resource_name')
    ->get();
?>

<!-- Your HTML panels here -->
<div class="row">...</div>
```

4. **Test with your device:**
- Ensure device OS matches filename (lowercase)
- Verify metrics are collected
- Check panel display

## ✨ Features Summary

### What's Included
✅ Automatic vendor detection  
✅ PureStorage optimized layout  
✅ Generic fallback for any device  
✅ Smart value formatting  
✅ Responsive design  
✅ Performance optimized queries  
✅ Consistent LibreNMS styling  

### What It Displays

**PureStorage:**
- Array capacity with visual bar
- Data reduction ratio & savings
- Top 10 volumes by size
- Real-time IOPS metrics
- Host connections
- Network interface details

**Generic:**
- Auto-discovered resource types
- Up to 6 key metrics per type
- All resources of each type
- Smart formatting for any data

## 🚀 Deployment

### Quick Deploy
```bash
# Navigate to LibreNMS directory
cd /Users/justinwasden/Documents/GitHub/librenms

# Verify files are in place
ls -la includes/html/pages/device/overview/rest-api/

# Clear caches (if needed)
php artisan config:clear
php artisan view:clear
php artisan cache:clear

# Test on a device
# Navigate to: http://your-librenms/device/device=1/tab=overview/
```

### Rollback (if needed)
```bash
# Remove the include line from overview.inc.php
sed -i.bak '/rest-api.inc.php/d' \
  includes/html/pages/device/overview.inc.php

# Or restore from backup
git checkout includes/html/pages/device/overview.inc.php
```

## 📞 Support

### Getting Help
1. Check `REST_API_OVERVIEW_IMPLEMENTATION.md` for detailed docs
2. Review `/opt/librenms/logs/librenms.log` for errors
3. Use `php artisan tinker` to debug database queries
4. Verify REST API polling is working: `php lnms device:poll X -m rest-api -vv`

### Useful Commands
```bash
# View collected metrics
php artisan tinker --execute="DB::table('device_api_metrics')->where('device_id', 1)->count()"

# Test REST API polling
php lnms device:poll 1 -m rest-api -vv

# Clear all caches
php artisan optimize:clear

# Check for PHP errors
tail -f /opt/librenms/storage/logs/laravel.log
```

## ✅ Final Checklist

Before considering implementation complete:

- [ ] All files created and in correct locations
- [ ] overview.inc.php modified to include rest-api.inc.php
- [ ] Tested with PureStorage device (if applicable)
- [ ] Tested generic fallback with another device (if applicable)
- [ ] No PHP errors in logs
- [ ] Metrics display correctly formatted
- [ ] Performance is acceptable (queries < 1 second)
- [ ] Documentation reviewed and understood
- [ ] Team members informed of new feature

## 🎉 Success Criteria

Implementation is successful when:
1. ✅ REST API panels appear on device overview page
2. ✅ Metrics display in correct format (GB/TB, IOPS, etc.)
3. ✅ No PHP/JavaScript errors in browser console
4. ✅ Page load time remains acceptable
5. ✅ Data updates on each poll cycle
6. ✅ Generic fallback works for unknown vendors

---

**Implementation Date:** October 3, 2025  
**Status:** ✅ Complete and Ready for Testing  
**Next Review:** After initial testing feedback
