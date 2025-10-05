# REST API Metric Field Mapping System - Complete Implementation

## 📚 Documentation Index

1. **[Quick Setup Guide](METRIC_FIELD_MAPPING_QUICK_SETUP.md)** ⚡
   - 5-minute installation
   - Essential commands
   - Troubleshooting basics

2. **[Implementation Summary](METRIC_FIELD_MAPPING_SUMMARY.md)** 📋
   - What was created
   - How it works
   - Configuration examples
   - Quick reference

3. **[Full Documentation](METRIC_FIELD_MAPPING_DOCUMENTATION.md)** 📖
   - Complete architecture
   - Detailed usage guide
   - Best practices
   - API reference

---

## 🎯 What This System Does

**Automatically maps REST API metrics to LibreNMS database fields**, enabling seamless integration of API data into the standard LibreNMS data model.

### Key Features

✅ **Automatic Matching** - Static and dynamic mapping rules  
✅ **Vendor-Specific** - Support for platform-specific metrics  
✅ **Admin UI** - Web interface for easy management  
✅ **CLI Tools** - Automation via Artisan commands  
✅ **Smart Detection** - Auto-creates sensors, handles special cases  
✅ **Value Transformation** - Multipliers and type casting  
✅ **Placeholder System** - Tracks unmatched metrics for review  

---

## 📁 Files Created

### Database
- `database/migrations/2025_10_05_000001_create_metric_field_mappings_table.php`
- `database/migrations/2025_10_05_000002_add_matched_at_to_device_api_metrics.php`

### Models & Services
- `app/Models/MetricFieldMapping.php`
- `app/Services/DataMatcher.php`

### Controllers & Commands
- `app/Http/Controllers/Admin/MetricFieldMappingController.php`
- `app/Console/Commands/MatchMetrics.php`

### Module Integration
- `LibreNMS/Modules/RestApi.php` (updated)

### Routes
- `routes/metric_field_mapping_routes.php`

### Views
- `resources/views/admin/metric-field-mappings/index.blade.php`
- `resources/views/admin/metric-field-mappings/edit.blade.php`

### Documentation
- `METRIC_FIELD_MAPPING_QUICK_SETUP.md`
- `METRIC_FIELD_MAPPING_SUMMARY.md`
- `METRIC_FIELD_MAPPING_DOCUMENTATION.md`
- `METRIC_FIELD_MAPPING_INDEX.md` (this file)

---

## 🚀 Quick Start

### 1. Install (2 minutes)

```bash
# Run migrations
php artisan migrate

# Add routes to routes/web.php
echo "require __DIR__ . '/metric_field_mapping_routes.php';" >> routes/web.php

# Clear cache
php artisan cache:clear
php artisan route:clear
```

### 2. Test (3 minutes)

```bash
# Poll a device with REST API
./poller.php -h <device_id> -m rest-api -d

# Check unmatched metrics
php artisan metrics:match --show-unmatched

# View in browser
# Navigate to: http://your-librenms/admin/metric-field-mappings
```

### 3. Configure (ongoing)

1. Review unmatched metrics in admin UI
2. Create mappings for vendor-specific metrics
3. Run matching to process metrics
4. Verify data in device overview pages

---

## 🔄 How It Works

```
┌──────────────────┐
│   REST API Poll  │  ← Polls configured endpoints
└────────┬─────────┘
         │
         ▼
┌──────────────────────────┐
│  device_api_metrics      │  ← Stores raw metrics
│  (matched_at = NULL)     │
└────────┬─────────────────┘
         │
         ▼
┌──────────────────────────┐
│   DataMatcher Service    │
├──────────────────────────┤
│  1. Static Mapping       │  ← Common metric names
│  2. Dynamic Mapping      │  ← Database rules
│  3. Placeholder Creation │  ← Unmatched tracking
└────────┬─────────────────┘
         │
         ▼
┌──────────────────────────┐
│   LibreNMS Tables        │  ← Final storage
│   - devices              │
│   - sensors              │
│   - ports                │
└──────────────────────────┘
```

---

## 🎨 Usage Examples

### CLI - Match Metrics

```bash
# All unmatched metrics
php artisan metrics:match

# Specific vendor
php artisan metrics:match --vendor=PureStorage

# Reset and re-process
php artisan metrics:match --reset

# Show unmatched
php artisan metrics:match --show-unmatched
```

### Web UI - Create Mapping

1. Navigate to: `/admin/metric-field-mappings`
2. Click "Create New Mapping"
3. Configure:
   - **Metric Name**: `array_capacity`
   - **Resource Type**: `array`
   - **Vendor**: `PureStorage` (optional)
   - **LibreNMS Table**: `devices`
   - **LibreNMS Field**: `storage_total`
   - **Data Type**: `numeric`
   - **Unit**: `bytes`
   - **Enabled**: ✅
4. Save

### Programmatic - Process Device

```php
use App\Services\DataMatcher;
use App\Models\Device;

$device = Device::find(123);
$matcher = new DataMatcher();
$stats = $matcher->processDeviceMetrics($device);

// Returns: ['matched' => 10, 'unmatched' => 2, 'errors' => 0]
```

---

## 📊 Built-in Static Mappings

### Device Metrics
- `serial`, `serial_number` → `devices.serial`
- `model`, `hardware_model` → `devices.hardware`
- `firmware_version` → `devices.version`
- `total_capacity` → `devices.storage_total`

### Sensor Metrics
- `temperature`, `temp` → `sensors.sensor_current` (temperature)
- `power` → `sensors.sensor_current` (power)
- `fan_speed` → `sensors.sensor_current` (fanspeed)

### Port Metrics
- `interface_speed` → `ports.ifSpeed`
- `interface_status` → `ports.ifOperStatus`

[See full list in METRIC_FIELD_MAPPING_SUMMARY.md]

---

## 🛠️ Configuration

### Vendor-Specific Mapping

```php
MetricFieldMapping::create([
    'metric_name' => 'status',
    'vendor' => 'PureStorage',
    'os' => 'Purity',
    'librenms_table' => 'sensors',
    'librenms_field' => 'sensor_current',
    'data_type' => 'string',
    'enabled' => true,
]);
```

### Generic Mapping (All Vendors)

```php
MetricFieldMapping::create([
    'metric_name' => 'uptime',
    'vendor' => null,  // Applies to all
    'os' => null,
    'librenms_table' => 'devices',
    'librenms_field' => 'uptime',
    'data_type' => 'numeric',
    'enabled' => true,
]);
```

### With Value Transformation

```php
MetricFieldMapping::create([
    'metric_name' => 'capacity_kb',
    'librenms_table' => 'devices',
    'librenms_field' => 'storage_total',
    'data_type' => 'numeric',
    'unit' => 'kilobytes',
    'multiplier' => 1024,  // Convert KB to bytes
    'enabled' => true,
]);
```

---

## 🔍 Monitoring & Maintenance

### Check System Health

```bash
# View unmatched metrics count
php artisan metrics:match --show-unmatched | grep "Unmatched"

# Check recent activity
mysql -u librenms -p librenms -e "
  SELECT vendor, os, COUNT(*) as count 
  FROM metric_field_mappings 
  WHERE librenms_table = 'unmatched' 
  GROUP BY vendor, os;
"

# View matching statistics
tail -f storage/logs/laravel.log | grep DataMatcher
```

### Periodic Cleanup

```bash
# Delete old unmatched placeholders (30+ days old)
mysql -u librenms -p librenms -e "
  DELETE FROM metric_field_mappings 
  WHERE librenms_table = 'unmatched' 
  AND last_seen_at < DATE_SUB(NOW(), INTERVAL 30 DAY);
"
```

### Schedule Automatic Matching

Add to cron or LibreNMS scheduler:

```bash
# Run matching every hour
0 * * * * cd /opt/librenms && php artisan metrics:match >> /dev/null 2>&1
```

---

## 🐛 Troubleshooting

### Common Issues

| Issue | Cause | Solution |
|-------|-------|----------|
| Metrics not matching | Missing mapping | Create mapping or check vendor/OS |
| Values incorrect | Wrong multiplier | Adjust multiplier in mapping |
| Sensors not created | Sensor class not detected | Check metric name patterns |
| Permission errors | Database permissions | Check MySQL user grants |
| Routes not found | Routes not loaded | Add to routes/web.php |

### Debug Commands

```bash
# Check if migrations ran
mysql -u librenms -p librenms -e "SHOW TABLES LIKE 'metric_field_mappings';"

# Verify routes loaded
php artisan route:list | grep metric-field-mappings

# Test DataMatcher directly
php artisan tinker
>>> $device = App\Models\Device::find(1);
>>> $matcher = new App\Services\DataMatcher();
>>> $matcher->processDeviceMetrics($device);

# View detailed poller output
./poller.php -h <device_id> -m rest-api -d -v
```

---

## 📈 Performance Tips

1. **Index Usage** - Migrations include proper indexes
2. **Batch Processing** - Process devices in groups by vendor
3. **Disable Unused** - Disable mappings you don't need
4. **Clean Placeholders** - Remove old unmatched entries
5. **Selective Polling** - Use filters in matching command

---

## 🎓 Learning Path

### For Beginners
1. Start with **[Quick Setup Guide](METRIC_FIELD_MAPPING_QUICK_SETUP.md)**
2. Read **[Implementation Summary](METRIC_FIELD_MAPPING_SUMMARY.md)** examples
3. Practice creating mappings in UI

### For Advanced Users
1. Review **[Full Documentation](METRIC_FIELD_MAPPING_DOCUMENTATION.md)**
2. Customize `DataMatcher` static mappings
3. Create vendor-specific mapping templates
4. Integrate with existing workflows

---

## 🚦 Integration Checklist

Before going live, verify:

- [ ] Migrations completed successfully
- [ ] Routes accessible in browser
- [ ] CLI command executes without errors
- [ ] Admin UI loads properly
- [ ] At least one mapping created and tested
- [ ] Metrics being matched correctly
- [ ] Data appearing in correct LibreNMS tables
- [ ] Logs show no critical errors
- [ ] Unmatched metrics reviewed and handled
- [ ] Documentation reviewed by team

---

## 🌟 Best Practices

1. **Start Generic** - Use generic mappings for common metrics
2. **Add Specificity When Needed** - Only use vendor/OS for conflicts
3. **Document Custom Logic** - Add descriptions to mappings
4. **Test Before Enabling** - Create disabled, verify, then enable
5. **Regular Review** - Check unmatched metrics weekly
6. **Version Control** - Export/backup mapping configurations
7. **Monitor Logs** - Watch for matching errors
8. **Gradual Rollout** - Test with one vendor at a time

---

## 📞 Getting Help

### Self-Service Resources

1. **Check Documentation**
   - Quick Setup: Common tasks
   - Summary: Configuration examples
   - Full Docs: Deep technical details

2. **Review Logs**
   ```bash
   tail -f storage/logs/laravel.log | grep DataMatcher
   ```

3. **Database Inspection**
   ```bash
   # View mappings
   mysql -u librenms -p librenms -e "SELECT * FROM metric_field_mappings LIMIT 10;"
   
   # View unmatched metrics
   mysql -u librenms -p librenms -e "
     SELECT * FROM device_api_metrics 
     WHERE matched_at IS NULL 
     LIMIT 10;
   "
   ```

4. **Test in Isolation**
   ```bash
   # Single device, verbose
   ./poller.php -h <device_id> -m rest-api -d -v
   ```

---

## 🎉 Success Criteria

Your implementation is successful when:

✅ REST API devices are polling successfully  
✅ Metrics are automatically matched to LibreNMS fields  
✅ Sensors appear on device overview pages  
✅ Device data updates correctly  
✅ Unmatched metrics are reviewed and configured  
✅ Admin team can manage mappings via UI  
✅ CLI tools work for automation  
✅ No critical errors in logs  

---

## 🚀 What's Next?

### Immediate (Week 1)
- [ ] Complete setup for all REST API devices
- [ ] Configure vendor-specific mappings
- [ ] Train admin users on UI
- [ ] Document custom mappings

### Short-term (Month 1)
- [ ] Create mapping templates for common vendors
- [ ] Set up automated matching schedule
- [ ] Monitor for new unmatched metrics
- [ ] Optimize performance if needed

### Long-term (Quarter 1)
- [ ] Develop mapping import/export
- [ ] Add graphing for custom metrics
- [ ] Create alert rules for unmatched metrics
- [ ] Share mappings with LibreNMS community

---

## 📋 File Reference

All files are located in: `/opt/librenms/`

**Start here:** `METRIC_FIELD_MAPPING_QUICK_SETUP.md`

**Then read:** `METRIC_FIELD_MAPPING_SUMMARY.md`

**Deep dive:** `METRIC_FIELD_MAPPING_DOCUMENTATION.md`

**This index:** `METRIC_FIELD_MAPPING_INDEX.md`

---

## 💡 Tips & Tricks

### Quick Mapping Creation

```bash
# Via Tinker
php artisan tinker

>>> use App\Models\MetricFieldMapping;
>>> MetricFieldMapping::create([
...   'metric_name' => 'your_metric',
...   'librenms_table' => 'sensors',
...   'librenms_field' => 'sensor_current',
...   'data_type' => 'numeric',
...   'enabled' => true
... ]);
```

### Bulk Import from CSV

```php
use App\Models\MetricFieldMapping;
use Illuminate\Support\Facades\DB;

$csv = array_map('str_getcsv', file('mappings.csv'));
array_shift($csv); // Remove header

foreach ($csv as $row) {
    MetricFieldMapping::create([
        'metric_name' => $row[0],
        'librenms_table' => $row[1],
        'librenms_field' => $row[2],
        'data_type' => $row[3],
        'enabled' => true,
    ]);
}
```

### Find Unmapped Metrics by Pattern

```bash
mysql -u librenms -p librenms -e "
  SELECT DISTINCT metric_name, resource_type, COUNT(*) as occurrences
  FROM device_api_metrics
  WHERE matched_at IS NULL
  AND metric_name LIKE '%temp%'
  GROUP BY metric_name, resource_type;
"
```

---

## ✨ Summary

You now have a **complete, production-ready REST API metric field mapping system** that:

- ✅ Automatically processes REST API data
- ✅ Maps metrics to standard LibreNMS fields
- ✅ Supports vendor-specific configurations
- ✅ Provides admin UI and CLI tools
- ✅ Handles edge cases intelligently
- ✅ Scales with your infrastructure

**The system is ready to use!** 🎊

---

*Last updated: October 2025*
