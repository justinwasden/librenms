# REST API Overview - Quick Reference Card

## 📍 File Locations

```
/includes/html/pages/device/overview/
├── rest-api.inc.php                    # Router (checks if REST API enabled)
└── rest-api/
    ├── purestorage.inc.php            # PureStorage-specific layout
    ├── generic.inc.php                # Fallback for any device
    └── [yourvendor].inc.php           # Add your vendor here
```

## 🔑 Key Database Tables

```sql
-- Main metrics table
device_api_metrics (
    device_id,              -- Links to devices table
    resource_type,          -- 'array', 'volume', 'host', 'network-interface'
    resource_id,           -- UUID or identifier
    resource_name,         -- Human-readable name
    metric_name,           -- 'capacity', 'iops', 'speed', etc.
    value,                 -- Numeric values (DECIMAL 20,4)
    string_value,          -- String/JSON values (TEXT)
    collected_at           -- Timestamp
)

-- Connection status
rest_api_connections (
    device_id,
    enabled,               -- Must be 1 to show overview
    base_url,
    ...
)
```

## 🎯 Quick Queries

### Check if device has REST API enabled
```php
$has_api = DB::table('rest_api_connections')
    ->where('device_id', $device['device_id'])
    ->where('enabled', 1)
    ->exists();
```

### Get latest metrics for a resource type
```php
$metrics = DB::table('device_api_metrics')
    ->where('device_id', $device['device_id'])
    ->where('resource_type', 'volume')
    ->orderBy('collected_at', 'desc')
    ->get()
    ->groupBy('metric_name');
```

### Aggregate metrics across resources
```php
$volumes = DB::table('device_api_metrics')
    ->select([
        'resource_name',
        DB::raw('MAX(CASE WHEN metric_name = "size" THEN value END) as size'),
        DB::raw('MAX(CASE WHEN metric_name = "iops" THEN value END) as iops')
    ])
    ->where('device_id', $device['device_id'])
    ->where('resource_type', 'volume')
    ->groupBy('resource_name', 'resource_id')
    ->get();
```

## 🎨 Display Helpers

### Format Storage Values
```php
// Bytes to human-readable
echo Number::formatBi($bytes);          // "23.41 TB"
```

### Format Timestamps
```php
// Relative time
echo \Carbon\Carbon::parse($timestamp)->diffForHumans();  // "2 minutes ago"
```

### Format Numbers
```php
// With decimals
echo number_format($value, 2);          // "3.47"

// With thousands separator
echo number_format($iops);              // "45,450"
```

### Percentage Bars
```php
$background = \LibreNMS\Util\Color::percentage($percent, $warn_threshold);

echo print_percentage_bar(
    400,                    // Width
    20,                     // Height
    $percent,              // Percentage
    "$used / $total",      // Label
    'ffffff',              // Bar text color
    $background['left'],   // Left bar color
    $free,                 // Free value
    'ffffff',              // Free text color
    $background['right']   // Right bar color
);
```

## 🏗️ Panel Template

```php
<div class="row">
    <div class="col-md-12">
        <div class="panel panel-default panel-condensed">
            <div class="panel-heading">
                <i class="fa fa-database fa-lg icon-theme"></i> 
                <strong>Panel Title</strong>
                <span class="badge pull-right"><?php echo $count; ?></span>
            </div>
            <table class="table table-hover table-condensed table-striped">
                <thead>
                    <tr>
                        <th>Column 1</th>
                        <th>Column 2</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($item->name); ?></td>
                        <td><?php echo number_format($item->value); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
```

## 🔍 Common Patterns

### Check for empty data
```php
if ($metrics->isEmpty()) {
    echo '<div class="alert alert-info">No metrics available</div>';
    return;
}
```

### Safe value extraction
```php
$value = $metrics['metric_name']->first()->value ?? 0;
$string = $metrics['metric_name']->first()->string_value ?? 'N/A';
```

### Resource type display name
```php
$display = ucwords(str_replace(['-', '_'], ' ', $resource_type));
// 'network-interface' → 'Network Interface'
```

### Conditional rendering
```php
<?php if ($volumes->count() > 0): ?>
    <!-- Show volume table -->
<?php else: ?>
    <div class="alert alert-info">No volumes found</div>
<?php endif; ?>
```

## 🚀 Adding a New Vendor (3 Steps)

### 1. Create File
```bash
# Use lowercase OS name
touch includes/html/pages/device/overview/rest-api/netapp.inc.php
```

### 2. Add Vendor-Specific Logic
```php
<?php
use Illuminate\Support\Facades\DB;
use LibreNMS\Util\Number;

// Query your metrics
$aggregates = DB::table('device_api_metrics')
    ->where('device_id', $device['device_id'])
    ->where('resource_type', 'aggregate')
    ->get();

// Display panels
?>
<div class="row">
    <!-- Your panels here -->
</div>
```

### 3. Test
```bash
# Ensure device OS matches filename
# Navigate to device overview
# Verify panels display correctly
```

## 🐛 Debugging Quick Hits

### Check metrics exist
```bash
php artisan tinker --execute="
DB::table('device_api_metrics')
  ->where('device_id', 1)
  ->count()
"
```

### View resource types
```bash
php artisan tinker --execute="
DB::table('device_api_metrics')
  ->where('device_id', 1)
  ->distinct()
  ->pluck('resource_type')
"
```

### Test REST API polling
```bash
php lnms device:poll 1 -m rest-api -vv
```

### Check for errors
```bash
tail -f /opt/librenms/storage/logs/laravel.log | grep -i error
```

## 📊 Performance Tips

### Use indexes
```sql
-- These indexes should exist:
device_id, resource_type, resource_id
device_id, api_endpoint_id, collected_at
resource_type, metric_name, collected_at
```

### Limit result sets
```php
->limit(10)              // Only top 10 results
->orderBy('size', 'desc') // Show largest first
```

### Group efficiently
```php
// Pivot metrics in SQL, not PHP
DB::raw('MAX(CASE WHEN metric_name = "x" THEN value END) as x')
```

### Cache if needed
```php
$key = "rest_api_{$device['device_id']}_array";
$data = Cache::remember($key, 300, function() {
    return DB::table('device_api_metrics')->...->get();
});
```

## 🎨 Icon Reference

```php
<i class="fa fa-database fa-lg icon-theme"></i>     // Storage
<i class="fa fa-hdd-o fa-lg icon-theme"></i>        // Volumes
<i class="fa fa-server fa-lg icon-theme"></i>       // Hosts/Servers
<i class="fa fa-sitemap fa-lg icon-theme"></i>      // Network
<i class="fa fa-tachometer fa-lg icon-theme"></i>   // Performance
<i class="fa fa-info-circle"></i>                   // Info
<i class="fa fa-check-circle"></i>                  // Success
<i class="fa fa-exclamation-triangle"></i>          // Warning
```

## ⚡ One-Liners

```bash
# Count total metrics
mysql librenms -e "SELECT COUNT(*) FROM device_api_metrics WHERE device_id=1"

# List all resource types
mysql librenms -e "SELECT DISTINCT resource_type FROM device_api_metrics WHERE device_id=1"

# Check REST API enabled
mysql librenms -e "SELECT enabled FROM rest_api_connections WHERE device_id=1"

# Clear Laravel cache
php artisan optimize:clear

# View latest metrics
mysql librenms -e "SELECT resource_type, metric_name, value FROM device_api_metrics WHERE device_id=1 ORDER BY collected_at DESC LIMIT 10"
```

## 📋 Checklist for New Vendor

- [ ] Create vendor file in `/rest-api/` directory
- [ ] Use lowercase OS name for filename
- [ ] Import required classes (DB, Number, Carbon)
- [ ] Query device_api_metrics table
- [ ] Use panel template for consistency
- [ ] Format values appropriately
- [ ] Handle empty data gracefully
- [ ] Test with actual device
- [ ] Check for PHP errors in logs
- [ ] Verify performance (< 1 second load)

## 🔗 Related Documentation

- **Full Guide:** `REST_API_OVERVIEW_IMPLEMENTATION.md`
- **Checklist:** `REST_API_OVERVIEW_CHECKLIST.md`
- **REST API Setup:** `REST_API_SETUP.md`
- **PureStorage Setup:** `PURESTORAGE_SETUP.md`

---

**Quick Start:** Just copy `generic.inc.php` or `purestorage.inc.php` as a template and customize the queries for your vendor's metrics!
