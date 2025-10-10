# Quick Setup Guide - REST API Metric Field Mapping

## 5-Minute Setup

### Step 1: Run Migrations (1 min)

```bash
cd /opt/librenms
php artisan migrate
```

This creates:
- `metric_field_mappings` table
- Adds `matched_at` and `mapping_id` columns to `device_api_metrics`

### Step 2: Add Routes (1 min)

Edit `/opt/librenms/routes/web.php` and add at the end:

```php
// Metric Field Mapping Routes
require __DIR__ . '/metric_field_mapping_routes.php';
```

### Step 3: Test the System (3 min)

```bash
# Poll a device with REST API
./poller.php -h <device_id> -m rest-api -d

# Check for unmatched metrics
php artisan metrics:match --show-unmatched

# View in web UI
# Navigate to: http://your-librenms/admin/metric-field-mappings
```

---

## Common Tasks

### View Unmatched Metrics

```bash
php artisan metrics:match --show-unmatched
```

### Create a Mapping (CLI Example)

```bash
# Via Laravel Tinker
php artisan tinker

>>> use App\Models\MetricFieldMapping;
>>> MetricFieldMapping::create([
...   'metric_name' => 'temperature',
...   'resource_type' => 'controller',
...   'vendor' => 'PureStorage',
...   'librenms_table' => 'sensors',
...   'librenms_field' => 'sensor_current',
...   'data_type' => 'numeric',
...   'enabled' => true,
... ]);
```

### Create a Mapping (Web UI)

1. Go to: `/admin/metric-field-mappings`
2. Click "Create New Mapping"
3. Fill in the form:
   - Metric Name: `temperature`
   - Resource Type: `controller`
   - Vendor: `PureStorage` (optional)
   - LibreNMS Table: `sensors`
   - LibreNMS Field: `sensor_current`
   - Data Type: `numeric`
   - Enabled: ✅
4. Save

### Run Matching

```bash
# All devices
php artisan metrics:match

# Specific vendor
php artisan metrics:match --vendor=PureStorage

# Reset and re-process
php artisan metrics:match --reset
```

---

## File Checklist

Ensure these files exist:

- ✅ `/database/migrations/2025_10_05_000001_create_metric_field_mappings_table.php`
- ✅ `/database/migrations/2025_10_05_000002_add_matched_at_to_device_api_metrics.php`
- ✅ `/app/Models/MetricFieldMapping.php`
- ✅ `/app/Services/DataMatcher.php`
- ✅ `/LibreNMS/Modules/RestApi.php`
- ✅ `/app/Console/Commands/MatchMetrics.php`
- ✅ `/app/Http/Controllers/Admin/MetricFieldMappingController.php`
- ✅ `/routes/metric_field_mapping_routes.php`
- ✅ `/resources/views/admin/metric-field-mappings/index.blade.php`
- ✅ `/resources/views/admin/metric-field-mappings/edit.blade.php`

---

## Verify Installation

### 1. Check Database Tables

```bash
mysql -u librenms -p librenms -e "SHOW TABLES LIKE 'metric_field_mappings';"
```

Expected output: `metric_field_mappings`

### 2. Test Command

```bash
php artisan metrics:match --help
```

Should show command help text.

### 3. Check Web UI

Navigate to: `http://your-librenms/admin/metric-field-mappings`

Should see the mappings index page.

---

## Troubleshooting

### Error: "Class 'DataMatcher' not found"

**Fix:**
```bash
composer dump-autoload
```

### Error: "Table 'metric_field_mappings' doesn't exist"

**Fix:**
```bash
php artisan migrate
```

### Error: "Route not found"

**Fix:** Ensure you added the routes file to `routes/web.php`:
```php
require __DIR__ . '/metric_field_mapping_routes.php';
```

### Metrics Not Matching

**Debug:**
```bash
# Check if metrics exist
mysql -u librenms -p librenms -e "SELECT * FROM device_api_metrics WHERE matched_at IS NULL LIMIT 10;"

# Check logs
tail -f /opt/librenms/storage/logs/laravel.log | grep DataMatcher

# Run with verbose output
./poller.php -h <device_id> -m rest-api -d -v
```

---

## Quick Reference

### CLI Commands

| Command | Description |
|---------|-------------|
| `php artisan metrics:match` | Match all unmatched metrics |
| `php artisan metrics:match --device_id=X` | Match specific device |
| `php artisan metrics:match --vendor=X` | Match specific vendor |
| `php artisan metrics:match --reset` | Reset and re-match all |
| `php artisan metrics:match --show-unmatched` | Display unmatched metrics |

### Admin URLs

| URL | Purpose |
|-----|---------|
| `/admin/metric-field-mappings` | List all mappings |
| `/admin/metric-field-mappings/create` | Create new mapping |
| `/admin/metric-field-mappings/{id}/edit` | Edit mapping |

### Database Tables

| Table | Purpose |
|-------|---------|
| `metric_field_mappings` | Mapping configurations |
| `device_api_metrics` | Collected API metrics |
| `rest_api_connections` | API connection configs |
| `rest_api_endpoints` | API endpoint definitions |

---

## Example Workflow

### Scenario: Adding Support for a New Vendor API

1. **Add REST API Connection**
   - Device → REST API Connections → Add Connection
   - Configure endpoint(s)

2. **Poll the Device**
   ```bash
   ./poller.php -h <device_id> -m rest-api -d
   ```

3. **Check Unmatched Metrics**
   ```bash
   php artisan metrics:match --show-unmatched
   ```

4. **Create Mappings** (Web UI or CLI)
   - Go to `/admin/metric-field-mappings`
   - Create mappings for each unmatched metric

5. **Run Matching**
   ```bash
   php artisan metrics:match
   ```

6. **Verify Data**
   - Check device overview page
   - Verify sensors/metrics appear correctly

---

## Success Indicators

✅ Migrations ran successfully  
✅ Routes are accessible  
✅ Admin UI loads without errors  
✅ CLI command executes  
✅ Metrics are being matched  
✅ Data appears in LibreNMS tables  

---

## Next Steps

1. 📖 Read full docs: `METRIC_FIELD_MAPPING_DOCUMENTATION.md`
2. 🎯 Review summary: `METRIC_FIELD_MAPPING_SUMMARY.md`
3. 🔧 Configure vendor-specific mappings
4. 📊 Set up monitoring for unmatched metrics
5. 🚀 Enable for production use

---

## Support

For issues or questions:
1. Check logs: `storage/logs/laravel.log`
2. Review documentation files
3. Inspect database tables
4. Test with verbose polling: `./poller.php -h X -m rest-api -d -v`

Happy mapping! 🎉
