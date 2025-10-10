# REST API Metric Field Mapping - Implementation Summary

## ✅ What Was Created

### 1. Database Migrations

- **`2025_10_05_000001_create_metric_field_mappings_table.php`**
  - Stores mapping configurations
  - Supports vendor/OS-specific mappings
  - Includes data transformation options (multiplier, data_type)

- **`2025_10_05_000002_add_matched_at_to_device_api_metrics.php`**
  - Adds `matched_at` timestamp to track processed metrics
  - Adds `mapping_id` foreign key to track which mapping was used

### 2. Models

- **`app/Models/MetricFieldMapping.php`**
  - Eloquent model with relationships
  - Scopes for filtering (forMetric, forDevice, unmatched)
  - Helper methods (isUnmatched, transformValue)
  - Attribute accessors (display_name, target)

### 3. Services

- **`app/Services/DataMatcher.php`**
  - Core matching logic with 3-step process:
    1. Static mapping (built-in common metrics)
    2. Dynamic mapping (database configurations)
    3. Placeholder creation (unmatched metrics)
  - Handles special cases (sensors, ports, devices)
  - Sensor class auto-detection
  - Value transformation (multiplier, type casting)
  - Statistics tracking

### 4. Module Integration

- **`LibreNMS/Modules/RestApi.php`** (updated)
  - Integrated DataMatcher into polling cycle
  - Automatic metric matching after API polling
  - Statistics output in poller logs

### 5. CLI Commands

- **`app/Console/Commands/MatchMetrics.php`**
  - Match metrics for all devices or filtered by vendor/OS/device_id
  - Reset and re-process option
  - Show unmatched metrics
  - Progress bar and statistics output
  - Helpful tips for admin configuration

### 6. Controllers

- **`app/Http/Controllers/Admin/MetricFieldMappingController.php`**
  - Full CRUD operations for mappings
  - Advanced filtering (vendor, OS, status, auto_learned)
  - Toggle enable/disable
  - Run matching from web UI
  - Bulk delete unmatched
  - AJAX endpoint for table fields

### 7. Routes

- **`routes/metric_field_mapping_routes.php`**
  - Resource routes for CRUD
  - Additional routes for toggle, run-matching, bulk operations
  - AJAX route for dynamic field loading

### 8. Views (Blade Templates)

- **`resources/views/admin/metric-field-mappings/index.blade.php`**
  - Comprehensive listing with filters
  - Search, sort, pagination
  - Action buttons (edit, toggle, delete)
  - Run matching modal
  - Bulk delete confirmation
  - Color-coded status indicators

- **`resources/views/admin/metric-field-mappings/edit.blade.php`**
  - Edit form for mappings
  - Read-only metric identification
  - Editable target fields and options
  - Metadata display panel
  - Form validation

### 9. Documentation

- **`METRIC_FIELD_MAPPING_DOCUMENTATION.md`**
  - Complete system overview
  - Architecture explanation
  - Usage instructions (CLI and UI)
  - Examples and best practices
  - Troubleshooting guide
  - API reference

---

## 🎯 How It Works

### Workflow

```
┌─────────────────┐
│  REST API Poll  │
└────────┬────────┘
         │
         ▼
┌─────────────────────────┐
│ device_api_metrics      │
│ (unmatched metrics)     │
└────────┬────────────────┘
         │
         ▼
┌─────────────────────────┐
│   DataMatcher Service   │
├─────────────────────────┤
│ 1. Static Mapping       │
│ 2. Dynamic Mapping      │
│ 3. Placeholder Creation │
└────────┬────────────────┘
         │
         ▼
┌─────────────────────────┐
│  LibreNMS Tables        │
│  - devices              │
│  - sensors              │
│  - ports                │
│  - etc.                 │
└─────────────────────────┘
```

### Matching Priority

1. **Static Map** - Built-in common metric names
2. **Database (Specific)** - Vendor + OS specific mappings
3. **Database (Generic)** - Generic mappings (no vendor/OS)
4. **Unmatched** - Create placeholder for admin review

### Example Scenarios

#### Scenario 1: Common Metric (Auto-Matched)
```
API returns: { "temperature": 45 }
↓
Static map finds: temperature → sensors.sensor_current
↓
Creates/updates sensor record
```

#### Scenario 2: Vendor-Specific Metric
```
API returns: { "status": "healthy" } (PureStorage)
↓
Database mapping: PureStorage/Purity: status → devices.status
↓
Updates devices.status = "healthy"
```

#### Scenario 3: Unmatched Metric
```
API returns: { "custom_metric": 123 }
↓
No static or dynamic mapping found
↓
Creates placeholder in metric_field_mappings:
  - metric_name: custom_metric
  - librenms_table: unmatched
  - librenms_field: unmatched
  - enabled: false
↓
Admin reviews and configures proper mapping
```

---

## 🚀 Getting Started

### 1. Run Migrations

```bash
php artisan migrate
```

### 2. Configure Routes

Add to your `routes/web.php`:

```php
// Include the metric field mapping routes
require __DIR__ . '/metric_field_mapping_routes.php';
```

### 3. Test Polling

Poll a device with REST API enabled:

```bash
./poller.php -h <device_id> -m rest-api -d
```

### 4. Check for Unmatched Metrics

```bash
php artisan metrics:match --show-unmatched
```

### 5. Configure Mappings

Visit: `http://your-librenms/admin/metric-field-mappings`

Review unmatched metrics and configure proper mappings.

### 6. Re-run Matching

```bash
php artisan metrics:match
```

---

## 📊 Static Mappings Reference

### Device Metrics (devices table)
- `status` → `status`
- `serial`, `serial_number` → `serial`
- `model`, `hardware_model` → `hardware`
- `firmware_version`, `firmware`, `os_version` → `version`
- `total_capacity` → `storage_total`
- `used_capacity` → `storage_used`
- `free_capacity` → `storage_free`
- `uptime` → `uptime`
- `hostname` → `hostname`

### Sensor Metrics (sensors table)
- `temperature`, `temp` → `sensor_current` (class: temperature)
- `power`, `power_consumption` → `sensor_current` (class: power)
- `voltage` → `sensor_current` (class: voltage)
- `current` → `sensor_current` (class: current)
- `fan_speed`, `fanspeed` → `sensor_current` (class: fanspeed)
- `humidity` → `sensor_current` (class: humidity)

### Port Metrics (ports table)
- `interface_speed`, `speed` → `ifSpeed`
- `interface_status`, `oper_status` → `ifOperStatus`
- `admin_status` → `ifAdminStatus`
- `interface_name` → `ifName`
- `interface_alias` → `ifAlias`
- `interface_description` → `ifDescr`
- `mtu` → `ifMtu`

---

## 🔧 Configuration Examples

### Example 1: PureStorage Array Capacity

```php
MetricFieldMapping::create([
    'metric_name' => 'capacity',
    'resource_type' => 'array',
    'vendor' => 'PureStorage',
    'os' => 'Purity',
    'librenms_table' => 'devices',
    'librenms_field' => 'storage_total',
    'data_type' => 'numeric',
    'unit' => 'bytes',
    'multiplier' => 1.0,
    'enabled' => true,
]);
```

### Example 2: Fortinet VPN Status

```php
MetricFieldMapping::create([
    'metric_name' => 'vpn_status',
    'resource_type' => 'vpn',
    'vendor' => 'Fortinet',
    'os' => 'FortiOS',
    'librenms_table' => 'sensors',
    'librenms_field' => 'sensor_current',
    'data_type' => 'string',
    'enabled' => true,
]);
```

### Example 3: Generic Temperature (All Vendors)

```php
MetricFieldMapping::create([
    'metric_name' => 'controller_temp',
    'resource_type' => 'controller',
    'vendor' => null, // Generic - applies to all vendors
    'os' => null,
    'librenms_table' => 'sensors',
    'librenms_field' => 'sensor_current',
    'data_type' => 'numeric',
    'unit' => 'celsius',
    'enabled' => true,
]);
```

---

## 📝 Admin UI Features

### Index Page (`/admin/metric-field-mappings`)

**Features:**
- ✅ List all mappings with pagination
- ✅ Search by metric name, table, field
- ✅ Filter by vendor, OS, status, type
- ✅ Sort by any column
- ✅ Quick actions (edit, toggle, delete)
- ✅ Run matching with filters
- ✅ Bulk delete unmatched
- ✅ Color-coded status (matched/unmatched)
- ✅ Auto-learned vs Manual indicators

**Action Buttons:**
- **Create New Mapping** - Add custom mapping
- **Run Matching** - Execute matching with optional filters
- **Delete All Unmatched** - Remove placeholder mappings

### Edit Page (`/admin/metric-field-mappings/{id}/edit`)

**Sections:**
1. **Read-only Metric Info** - Shows metric identification
2. **Editable Mapping** - Configure target table/field
3. **Options** - Data type, unit, multiplier
4. **Metadata Panel** - Creation date, last seen, device info

---

## 🔍 CLI Commands

### Basic Usage

```bash
# Match all unmatched metrics
php artisan metrics:match

# Match for specific device
php artisan metrics:match --device_id=123

# Match for specific vendor
php artisan metrics:match --vendor=PureStorage

# Match for specific OS
php artisan metrics:match --os=Purity

# Reset and re-match everything
php artisan metrics:match --reset

# Show unmatched metrics at the end
php artisan metrics:match --show-unmatched
```

### Combining Options

```bash
# Reset and match all PureStorage devices
php artisan metrics:match --vendor=PureStorage --reset

# Match FortiOS devices and show unmatched
php artisan metrics:match --os=FortiOS --show-unmatched
```

---

## 🐛 Troubleshooting

### Issue: Metrics Not Being Matched

**Check:**
1. Does mapping exist? `SELECT * FROM metric_field_mappings WHERE metric_name = 'your_metric';`
2. Is mapping enabled? `enabled = 1`
3. Does vendor/OS match? Check specificity
4. Run with reset: `php artisan metrics:match --reset`

### Issue: Sensor Not Created

**Possible causes:**
- Metric value is null
- Sensor class not recognized
- Missing resource_id in metric

**Debug:**
- Check logs: `tail -f storage/logs/laravel.log`
- Look for "DataMatcher" entries

### Issue: Value Not Updating

**Check:**
- Is data_type correct?
- Is multiplier appropriate?
- Does target field exist in table?
- Check database update permissions

---

## 🎨 Customization

### Add New Static Mappings

Edit `app/Services/DataMatcher.php`:

```php
protected array $staticMap = [
    'devices' => [
        // Add your mapping here
        'your_metric_name' => 'target_field',
    ],
    // ...
];
```

### Add New Sensor Classes

Edit sensor class map:

```php
protected array $sensorClassMap = [
    'your_keyword' => 'sensor_class_name',
    // ...
];
```

### Custom Table Support

Extend `getLibreNMSTables()` in controller:

```php
protected function getLibreNMSTables(): array
{
    return [
        'your_custom_table' => 'Your Custom Table',
        // ...
    ];
}
```

---

## 📈 Performance Considerations

### Optimization Tips

1. **Batch Processing** - Command processes devices in batches
2. **Index Usage** - Proper indexes on metric_name, device_id
3. **Selective Matching** - Use filters to process specific vendors
4. **Disable Unused** - Disable mappings you don't need
5. **Clean Unmatched** - Periodically delete old unmatched placeholders

### Database Indexes

Already included in migrations:
- `metric_field_mappings`: metric_name, resource_type, vendor, os
- `device_api_metrics`: matched_at, device_id

---

## ✨ Next Steps

### Immediate Actions

1. ✅ Run migrations
2. ✅ Include routes in web.php
3. ✅ Test polling a REST API device
4. ✅ Review unmatched metrics
5. ✅ Configure needed mappings
6. ✅ Re-run matching

### Future Enhancements

- [ ] Mapping templates for popular vendors
- [ ] Import/export mappings
- [ ] Mapping suggestions based on field similarity
- [ ] Graphing integration for matched metrics
- [ ] Alert rules for unmatched metrics
- [ ] Mapping validation rules
- [ ] Automated mapping learning from user feedback

---

## 📞 Support

### Documentation Files
- `METRIC_FIELD_MAPPING_DOCUMENTATION.md` - Full technical documentation
- This file - Implementation summary and quick reference

### Key Files to Review
- `app/Services/DataMatcher.php` - Core matching logic
- `app/Models/MetricFieldMapping.php` - Model with helper methods
- `LibreNMS/Modules/RestApi.php` - Integration point
- `app/Console/Commands/MatchMetrics.php` - CLI command

### Logging
All matching activity is logged to `storage/logs/laravel.log` with prefix "DataMatcher"

---

## 🎉 Summary

You now have a complete, production-ready metric field mapping system that:

✅ **Automatically matches** REST API metrics to LibreNMS fields  
✅ **Supports vendor-specific** mappings for flexibility  
✅ **Provides admin UI** for easy management  
✅ **Includes CLI tools** for automation  
✅ **Handles special cases** (sensors, ports, devices)  
✅ **Transforms values** with multipliers and type casting  
✅ **Tracks unmatched metrics** for review  
✅ **Integrates seamlessly** with existing REST API polling  

The system is designed to work out-of-the-box with common metrics while allowing full customization for specific vendor needs!
