# REST API Overview Pages - Implementation Guide

## Overview

Custom overview pages have been created to display REST API metrics for devices with REST API connections enabled. The implementation supports vendor-specific layouts with a generic fallback for any REST API-enabled device.

## Files Created

### 1. Main Router File
**Location:** `/includes/html/pages/device/overview/rest-api.inc.php`

This is the main entry point that:
- Checks if the device has an enabled REST API connection
- Determines the device OS/vendor
- Routes to vendor-specific overview or generic fallback
- Silently skips if no REST API connection exists

### 2. PureStorage-Specific Overview
**Location:** `/includes/html/pages/device/overview/rest-api/purestorage.inc.php`

Displays comprehensive PureStorage FlashArray metrics:

#### Array Storage Metrics Panel
- Array name and identification
- Total capacity and usage statistics
- Data reduction ratio
- Visual capacity utilization bar with color coding

#### Volume Performance Table
- Top 10 volumes by provisioned size
- Shows: Provisioned size, Physical used, Reduction ratio
- Real-time IOPS metrics (Read, Write, Total)
- Sortable and filterable data

#### Host Connections Panel
- Lists all connected hosts
- Shows metric count per host
- Displays last update timestamp
- Badge showing total host count

#### Network Interfaces Panel
- Interface names and IP addresses
- Link speeds (converted to Gbps)
- Service types (Management, Replication, etc.)
- Only shows enabled interfaces

### 3. Generic REST API Overview
**Location:** `/includes/html/pages/device/overview/rest-api/generic.inc.php`

Provides automatic metric display for any REST API-enabled device:

#### Features
- Automatically discovers all resource types
- Groups metrics by resource type (array, volume, interface, host, etc.)
- Displays up to 6 most relevant metrics per resource type
- Smart value formatting:
  - Large numbers (size/capacity) formatted with Number::formatBi()
  - Numeric values with 2 decimal precision
  - String values truncated to 30 characters
- Shows last update timestamp for each resource
- Info footer when metrics are truncated

## Integration

The REST API overview is integrated into the main device overview page:

**File Modified:** `/includes/html/pages/device/overview.inc.php`

**Change:** Added after transceivers overview:
```php
require 'overview/transceivers.inc.php';
require 'overview/rest-api.inc.php';  // NEW
```

## How It Works

### 1. Device Overview Page Load
```
User visits device overview
    ↓
Main overview.inc.php loads
    ↓
Includes rest-api.inc.php
    ↓
Check: Device has REST API enabled?
    ↓
    No → Skip silently, show nothing
    ↓
    Yes → Determine vendor (PureStorage, etc.)
    ↓
Load vendor-specific OR generic overview
    ↓
Query device_api_metrics table
    ↓
Render panels with metrics
```

### 2. Data Flow for PureStorage

```sql
-- Array Metrics Query
SELECT * FROM device_api_metrics
WHERE device_id = ? 
  AND resource_type = 'array'
ORDER BY collected_at DESC;

-- Volume Metrics Query
SELECT 
  resource_name,
  MAX(CASE WHEN metric_name = 'size' THEN value END) as size,
  MAX(CASE WHEN metric_name = 'provisioned' THEN value END) as provisioned,
  ...
FROM device_api_metrics
WHERE device_id = ? 
  AND resource_type = 'volume'
GROUP BY resource_name, resource_id
ORDER BY provisioned DESC
LIMIT 10;

-- Host Connections Query
SELECT 
  resource_name,
  COUNT(DISTINCT metric_name) as metric_count
FROM device_api_metrics
WHERE device_id = ? 
  AND resource_type = 'host'
GROUP BY resource_name, resource_id;
```

### 3. Data Flow for Generic Devices

```sql
-- Get all resource types
SELECT DISTINCT resource_type 
FROM device_api_metrics
WHERE device_id = ?;

-- For each resource type, get resources and metrics
SELECT resource_name, resource_id, metric_name, value, string_value
FROM device_api_metrics
WHERE device_id = ? 
  AND resource_type = ?
ORDER BY collected_at DESC;
```

## Styling

All overview panels use consistent LibreNMS styling:

- **Panel Headers:** Icon + Title with badges for counts
- **Tables:** Striped, hoverable rows with responsive columns
- **Colors:** Using LibreNMS color utility for percentage bars
- **Badges:** Blue (#5bc0de) for counts
- **Footers:** Light gray for info messages

Custom CSS applied:
```css
.panel-condensed .panel-heading { padding: 10px 15px; }
.panel-condensed .table { margin-bottom: 0; }
.badge { background-color: #5bc0de; }
```

## Adding Support for New Vendors

To add a vendor-specific overview:

1. **Create vendor file:** `/includes/html/pages/device/overview/rest-api/<vendor_os>.inc.php`
   - Use lowercase OS name (e.g., 'netapp', 'nimble', 'hpe3par')

2. **Query metrics for your vendor:**
   ```php
   $metrics = DB::table('device_api_metrics')
       ->where('device_id', $device['device_id'])
       ->where('resource_type', 'your_resource_type')
       ->get();
   ```

3. **Design panels specific to your vendor's data model**

4. **Use LibreNMS utilities:**
   - `Number::formatBi()` for storage values
   - `\LibreNMS\Util\Color::percentage()` for capacity bars
   - `\Carbon\Carbon::parse()->diffForHumans()` for timestamps

### Example: NetApp Overview Template

```php
<?php
// /includes/html/pages/device/overview/rest-api/netapp.inc.php

use Illuminate\Support\Facades\DB;
use LibreNMS\Util\Number;

// Get cluster metrics
$cluster_metrics = DB::table('device_api_metrics')
    ->where('device_id', $device['device_id'])
    ->where('resource_type', 'cluster')
    ->orderBy('collected_at', 'desc')
    ->get()
    ->groupBy('metric_name');

// Get volume metrics
$volumes = DB::table('device_api_metrics')
    ->where('device_id', $device['device_id'])
    ->where('resource_type', 'volume')
    ->select('resource_name', /* ... */)
    ->groupBy('resource_name')
    ->get();

// Display panels...
?>
```

## Database Schema Reference

The overview pages query the `device_api_metrics` table:

```sql
CREATE TABLE device_api_metrics (
    id BIGINT PRIMARY KEY,
    device_id INT,
    api_endpoint_id BIGINT,
    resource_type VARCHAR(50),      -- 'array', 'volume', 'host', etc.
    resource_id VARCHAR(255),       -- UUID or identifier
    resource_name VARCHAR(255),     -- Display name
    metric_name VARCHAR(255),       -- 'capacity', 'iops', 'speed'
    metric_type VARCHAR(20),        -- 'gauge', 'counter'
    value DECIMAL(20,4),           -- Numeric values
    string_value TEXT,             -- String values
    collected_at TIMESTAMP,
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

## PureStorage Metric Mapping

The PureStorage overview expects these metrics from the REST API poller:

### Array Resource Type
| Metric Name | Type | Description |
|------------|------|-------------|
| `name` | string | Array name |
| `capacity` | numeric | Total capacity in bytes |
| `total` | numeric | Total space in bytes |
| `space.available` | numeric | Available space in bytes |
| `space.data_reduction` | numeric | Data reduction ratio |

### Volume Resource Type
| Metric Name | Type | Description |
|------------|------|-------------|
| `size` | numeric | Physical size in bytes |
| `provisioned` | numeric | Provisioned size in bytes |
| `space.data_reduction` | numeric | Volume reduction ratio |
| `reads_per_sec` | numeric | Read IOPS |
| `writes_per_sec` | numeric | Write IOPS |

### Host Resource Type
| Metric Name | Type | Description |
|------------|------|-------------|
| (any) | mixed | All metrics counted |

### Network Interface Resource Type
| Metric Name | Type | Description |
|------------|------|-------------|
| `speed` | numeric | Speed in bits/sec |
| `address` | string | IP address |
| `services` | string | Service types |

## Performance Considerations

### Query Optimization
- Overview uses indexed columns (device_id, resource_type, collected_at)
- Limits queries to top 10 volumes to prevent slowdown
- Groups by resource to minimize rows returned
- Orders by collected_at DESC to get latest data first

### Caching Recommendations
For high-traffic installations, consider caching:

```php
// Cache array metrics for 5 minutes
$cache_key = "rest_api_overview_{$device['device_id']}_array";
$array_metrics = Cache::remember($cache_key, 300, function() use ($device) {
    return DB::table('device_api_metrics')
        ->where('device_id', $device['device_id'])
        ->where('resource_type', 'array')
        ->orderBy('collected_at', 'desc')
        ->get();
});
```

## Testing

### 1. Verify Files Are In Place
```bash
ls -la /includes/html/pages/device/overview/rest-api/
# Should show:
# - purestorage.inc.php
# - generic.inc.php
```

### 2. Check Database Has Metrics
```bash
php artisan tinker
```

```php
// Check if metrics exist
DB::table('device_api_metrics')->where('device_id', 1)->count();

// Check resource types
DB::table('device_api_metrics')
    ->where('device_id', 1)
    ->distinct()
    ->pluck('resource_type');
```

### 3. View Device Overview
1. Navigate to device overview page
2. Scroll down to see REST API panels
3. Verify metrics display correctly
4. Check for any PHP errors in logs

### 4. Test Generic Fallback
To test the generic overview:
1. Temporarily rename `purestorage.inc.php`
2. Reload device overview
3. Should see generic metric tables
4. Restore filename when done

## Troubleshooting

### Issue: No REST API Panels Showing

**Check:**
```php
// In tinker
$conn = DB::table('rest_api_connections')
    ->where('device_id', 1)
    ->where('enabled', 1)
    ->first();
    
print_r($conn);
```

**Solution:** Enable REST API connection for device

### Issue: "No metrics collected yet" Message

**Check:**
```bash
# Run REST API polling
php lnms device:poll 1 -m rest-api -vv

# Check for errors
tail -f /opt/librenms/logs/librenms.log | grep -i "rest api"
```

**Solution:** Ensure REST API endpoints are configured and polling successfully

### Issue: Wrong Vendor Overview Loading

**Check:**
```php
// Device OS should match filename
echo $device['os']; // Should output 'purestorage'
```

**Solution:** Ensure device OS is correctly set or filename matches OS

### Issue: Metrics Show But Values Are Wrong

**Check the raw data:**
```php
DB::table('device_api_metrics')
    ->where('device_id', 1)
    ->where('resource_type', 'array')
    ->get(['metric_name', 'value', 'string_value']);
```

**Solution:** Verify REST API endpoint mappings are correct

## Future Enhancements

### Potential Additions
1. **Graphing Integration**
   - Add mini-graphs like storage overview
   - Click to view full metric history

2. **Alert Integration**
   - Show active alerts related to REST API metrics
   - Highlight resources with warnings

3. **Comparison Views**
   - Compare multiple volumes side-by-side
   - Show delta from previous collection

4. **Export Functionality**
   - Export metrics to CSV
   - Generate PDF reports

5. **Real-time Updates**
   - AJAX refresh for live metrics
   - WebSocket support for instant updates

### Code Structure for Graphs

```php
// Add to PureStorage overview
$graph_array = [
    'height' => '100',
    'width' => '210',
    'to' => \App\Facades\LibrenmsConfig::get('time.now'),
    'id' => $volume['resource_id'],
    'type' => 'rest_api_iops', // Custom graph type
    'from' => \App\Facades\LibrenmsConfig::get('time.day'),
    'legend' => 'no'
];

$minigraph = \LibreNMS\Util\Url::lazyGraphTag($graph_array);
```

## Summary

The REST API overview implementation provides:

✅ **Automatic Detection** - Shows only when REST API is enabled  
✅ **Vendor-Specific Views** - PureStorage optimized layout  
✅ **Generic Fallback** - Works with any REST API device  
✅ **Smart Formatting** - Proper units and value display  
✅ **Performance Optimized** - Efficient queries with limits  
✅ **Extensible Design** - Easy to add new vendors  

### Quick Reference

| File | Purpose |
|------|---------|
| `overview/rest-api.inc.php` | Router/entry point |
| `overview/rest-api/purestorage.inc.php` | PureStorage specific |
| `overview/rest-api/generic.inc.php` | Generic fallback |

### Key Database Tables
- `rest_api_connections` - Device API config
- `device_api_metrics` - Collected metrics
- `rest_api_endpoints` - Endpoint definitions

The implementation is complete and ready for use!
