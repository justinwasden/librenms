# 📋 Implementation Verification Checklist

Use this checklist to verify that all components of the REST API Metric Field Mapping system are properly installed and working.

---

## ✅ File Verification

### Database Migrations
- [ ] `database/migrations/2025_10_05_000001_create_metric_field_mappings_table.php` exists
- [ ] `database/migrations/2025_10_05_000002_add_matched_at_to_device_api_metrics.php` exists

### Models & Services
- [ ] `app/Models/MetricFieldMapping.php` exists
- [ ] `app/Services/DataMatcher.php` exists

### Controllers & Commands
- [ ] `app/Http/Controllers/Admin/MetricFieldMappingController.php` exists
- [ ] `app/Console/Commands/MatchMetrics.php` exists

### Module
- [ ] `LibreNMS/Modules/RestApi.php` updated with DataMatcher integration

### Routes
- [ ] `routes/metric_field_mapping_routes.php` exists

### Views
- [ ] `resources/views/admin/metric-field-mappings/index.blade.php` exists
- [ ] `resources/views/admin/metric-field-mappings/edit.blade.php` exists

### Documentation
- [ ] `IMPLEMENTATION_COMPLETE.md` exists
- [ ] `METRIC_FIELD_MAPPING_INDEX.md` exists
- [ ] `METRIC_FIELD_MAPPING_QUICK_SETUP.md` exists
- [ ] `METRIC_FIELD_MAPPING_SUMMARY.md` exists
- [ ] `METRIC_FIELD_MAPPING_DOCUMENTATION.md` exists

---

## ✅ Installation Steps

### 1. Database Setup
```bash
# Run migrations
php artisan migrate
```
- [ ] Migrations ran without errors
- [ ] Table `metric_field_mappings` created
- [ ] Columns `matched_at` and `mapping_id` added to `device_api_metrics`

**Verify:**
```bash
mysql -u librenms -p librenms -e "DESCRIBE metric_field_mappings;"
mysql -u librenms -p librenms -e "DESCRIBE device_api_metrics;" | grep matched_at
```

### 2. Routes Configuration
```bash
# Add to routes/web.php
echo "require __DIR__ . '/metric_field_mapping_routes.php';" >> routes/web.php
```
- [ ] Routes file included in `routes/web.php`
- [ ] No duplicate entries

**Verify:**
```bash
grep metric_field_mapping_routes.php routes/web.php
```

### 3. Cache Clearing
```bash
php artisan cache:clear
php artisan route:clear
php artisan config:clear
composer dump-autoload
```
- [ ] Cache cleared successfully
- [ ] Routes cleared successfully
- [ ] Autoloader regenerated

---

## ✅ Functional Testing

### 1. CLI Command
```bash
php artisan metrics:match --help
```
- [ ] Command exists and shows help text
- [ ] No errors displayed

### 2. Routes Accessible
```bash
php artisan route:list | grep metric-field-mappings
```
- [ ] At least 8 routes listed
- [ ] Routes include: index, create, store, edit, update, destroy

**Expected routes:**
- GET `/admin/metric-field-mappings`
- GET `/admin/metric-field-mappings/create`
- POST `/admin/metric-field-mappings`
- GET `/admin/metric-field-mappings/{mapping}/edit`
- PUT `/admin/metric-field-mappings/{mapping}`
- DELETE `/admin/metric-field-mappings/{mapping}`
- POST `/admin/metric-field-mappings/{mapping}/toggle`
- POST `/admin/metric-field-mappings/run-matching`

### 3. Web UI Access
Navigate to: `http://your-librenms/admin/metric-field-mappings`

- [ ] Page loads without errors
- [ ] Can see empty table or existing mappings
- [ ] "Create New Mapping" button visible
- [ ] Filters panel visible

### 4. Model Loading
```bash
php artisan tinker
>>> App\Models\MetricFieldMapping::count()
```
- [ ] Model loads without errors
- [ ] Returns a number (0 if no mappings exist)

### 5. Service Loading
```bash
php artisan tinker
>>> $matcher = new App\Services\DataMatcher();
>>> print_r($matcher);
```
- [ ] Service instantiates without errors
- [ ] Object created successfully

---

## ✅ Integration Testing

### 1. REST API Device Polling
```bash
# Poll a device with REST API enabled
./poller.php -h <device_id> -m rest-api -d
```
- [ ] Poller runs without errors
- [ ] Metrics collected and stored
- [ ] DataMatcher executes
- [ ] Statistics displayed (matched/unmatched/errors)

**Expected output should include:**
```
REST API Metrics: X matched, Y unmatched, Z errors
```

### 2. View Collected Metrics
```bash
mysql -u librenms -p librenms -e "
  SELECT metric_name, resource_type, value, matched_at 
  FROM device_api_metrics 
  WHERE device_id = <device_id> 
  LIMIT 10;
"
```
- [ ] Metrics exist in table
- [ ] Some metrics have `matched_at` timestamp
- [ ] Some metrics may be `NULL` (unmatched)

### 3. Check for Unmatched Metrics
```bash
php artisan metrics:match --show-unmatched
```
- [ ] Command executes successfully
- [ ] Shows list of unmatched metrics (if any)
- [ ] Table displays: metric name, resource type, vendor, OS

### 4. View Matching Statistics
```bash
php artisan metrics:match
```
- [ ] Command runs without errors
- [ ] Progress bar displays
- [ ] Summary shows: matched, unmatched, errors counts

---

## ✅ Admin UI Testing

### 1. View Mappings List
URL: `/admin/metric-field-mappings`

- [ ] Page loads successfully
- [ ] Table displays mappings (or empty state)
- [ ] Pagination works (if >25 mappings)
- [ ] Search box functional
- [ ] Filters work (vendor, OS, status)

### 2. Create New Mapping
1. Click "Create New Mapping"
2. Fill in form
3. Click "Save"

- [ ] Create form loads
- [ ] Can select table from dropdown
- [ ] Can enter field name
- [ ] Can select data type
- [ ] Save redirects to index
- [ ] Success message displayed
- [ ] New mapping appears in list

### 3. Edit Mapping
1. Click edit button on a mapping
2. Modify field
3. Save

- [ ] Edit form loads with existing data
- [ ] Can modify fields
- [ ] Save updates mapping
- [ ] Returns to index with success message

### 4. Toggle Enabled/Disabled
- [ ] Click toggle button
- [ ] Status changes
- [ ] Success message displayed
- [ ] Label updates (Enabled/Disabled)

### 5. Delete Mapping
- [ ] Click delete button
- [ ] Confirmation dialog appears
- [ ] Mapping removed after confirmation
- [ ] Success message displayed

### 6. Run Matching from UI
1. Click "Run Matching" button
2. Modal opens
3. Optionally select filters
4. Click "Run Matching"

- [ ] Modal opens successfully
- [ ] Can select vendor/OS
- [ ] Can check "reset" option
- [ ] Executes matching
- [ ] Shows success/output message

---

## ✅ Data Flow Testing

### 1. End-to-End Test

**Setup:**
1. Have a device with REST API configured
2. Ensure some metrics don't have mappings

**Test Steps:**
```bash
# 1. Poll device
./poller.php -h <device_id> -m rest-api -d

# 2. Check unmatched
php artisan metrics:match --show-unmatched

# 3. Create mapping via UI or CLI
# Via CLI example:
php artisan tinker
>>> App\Models\MetricFieldMapping::create([
...   'metric_name' => 'your_metric',
...   'librenms_table' => 'sensors',
...   'librenms_field' => 'sensor_current',
...   'data_type' => 'numeric',
...   'enabled' => true
... ]);

# 4. Run matching
php artisan metrics:match

# 5. Verify data in LibreNMS table
mysql -u librenms -p librenms -e "
  SELECT * FROM sensors 
  WHERE device_id = <device_id> 
  AND sensor_type = 'rest-api';
"
```

**Verify:**
- [ ] Metric was unmatched initially
- [ ] Mapping was created
- [ ] Matching command processed it
- [ ] Data appears in target LibreNMS table
- [ ] Metric marked as matched (`matched_at` set)

### 2. Static Mapping Test

**Test with common metric:**
```bash
# Create test metric
mysql -u librenms -p librenms -e "
  INSERT INTO device_api_metrics 
  (device_id, api_endpoint_id, resource_type, resource_id, resource_name, metric_name, value, collected_at, created_at, updated_at)
  VALUES 
  (<device_id>, 1, 'device', 'test', 'test', 'temperature', 45.5, NOW(), NOW(), NOW());
"

# Run matching
php artisan metrics:match

# Check if sensor created
mysql -u librenms -p librenms -e "
  SELECT * FROM sensors 
  WHERE device_id = <device_id> 
  AND sensor_type = 'rest-api' 
  AND sensor_class = 'temperature';
"
```

- [ ] Metric inserted successfully
- [ ] Matching processed it
- [ ] Sensor created with class 'temperature'
- [ ] sensor_current = 45.5

---

## ✅ Error Handling Testing

### 1. Invalid Table Name
Create mapping with non-existent table:
```bash
php artisan tinker
>>> App\Models\MetricFieldMapping::create([
...   'metric_name' => 'test',
...   'librenms_table' => 'nonexistent_table',
...   'librenms_field' => 'field',
...   'data_type' => 'numeric',
...   'enabled' => true
... ]);
```

Then run matching and check logs:
```bash
php artisan metrics:match
tail -f storage/logs/laravel.log | grep DataMatcher
```

- [ ] Error logged (not fatal)
- [ ] Other metrics still process
- [ ] Error count incremented

### 2. Null Values
- [ ] Null metric values are skipped
- [ ] No errors in logs
- [ ] Matching continues

### 3. Missing Device
```bash
php artisan metrics:match --device_id=99999
```
- [ ] Handles gracefully
- [ ] Shows appropriate message

---

## ✅ Performance Testing

### 1. Batch Processing
```bash
# Process all devices
time php artisan metrics:match
```
- [ ] Completes in reasonable time
- [ ] Progress bar updates smoothly
- [ ] No memory errors

### 2. Large Dataset
If you have many metrics:
```bash
# Check metric count
mysql -u librenms -p librenms -e "
  SELECT COUNT(*) FROM device_api_metrics WHERE matched_at IS NULL;
"

# Run matching
php artisan metrics:match
```
- [ ] Handles large datasets
- [ ] No timeouts
- [ ] Memory usage acceptable

---

## ✅ Logging Verification

### 1. Check Log Entries
```bash
tail -100 storage/logs/laravel.log | grep DataMatcher
```

**Should see entries like:**
- [ ] "Processing X unmatched metrics for device Y"
- [ ] "DataMatcher for device X: Y matched, Z unmatched, W errors"
- [ ] "Updated sensor..." or "Created new sensor..."
- [ ] Error messages (if any issues)

### 2. Log Levels
- [ ] Info messages for successful matches
- [ ] Debug messages for detailed operations
- [ ] Warning messages for minor issues
- [ ] Error messages for failures

---

## ✅ Security Testing

### 1. Admin Authorization
- [ ] Routes require authentication
- [ ] Routes require admin role
- [ ] Unauthorized users get 403/redirect

### 2. Input Validation
Try creating mapping with invalid data:
- [ ] Empty metric_name rejected
- [ ] Invalid data_type rejected
- [ ] SQL injection attempts sanitized
- [ ] XSS attempts sanitized

---

## ✅ Documentation Review

### 1. Documentation Completeness
- [ ] All 5 documentation files present
- [ ] Links between docs work
- [ ] Examples are accurate
- [ ] Code samples are correct

### 2. README Instructions
Follow each setup guide:
- [ ] Quick Setup Guide works
- [ ] Commands execute as documented
- [ ] Expected results match docs

---

## ✅ Final Verification

### System Health Check
```bash
# 1. Database tables exist
mysql -u librenms -p librenms -e "SHOW TABLES;" | grep -E "(metric_field_mappings|device_api_metrics)"

# 2. Routes loaded
php artisan route:list | grep metric-field-mappings | wc -l
# Should show 8+ routes

# 3. Models autoload
php -r "require 'vendor/autoload.php'; echo class_exists('App\Models\MetricFieldMapping') ? 'OK' : 'FAIL';"

# 4. Web UI accessible
curl -s -o /dev/null -w "%{http_code}" http://localhost/admin/metric-field-mappings
# Should return 200 or 302 (redirect to login)
```

All checks should pass ✅

---

## 🎯 Success Criteria

Your implementation is verified when:

✅ All files exist and are in correct locations  
✅ Migrations completed successfully  
✅ Routes are accessible  
✅ CLI commands work  
✅ Admin UI loads and functions  
✅ Metrics are being matched  
✅ Data flows to LibreNMS tables  
✅ Logs show no critical errors  
✅ Documentation is complete  

---

## 🐛 Common Issues & Solutions

| Issue | Cause | Solution |
|-------|-------|----------|
| Class not found | Autoload cache | `composer dump-autoload` |
| Route not found | Routes not loaded | Add to `routes/web.php` |
| Table doesn't exist | Migration not run | `php artisan migrate` |
| 403 Forbidden | Not admin user | Login as admin |
| Metrics not matching | No mapping exists | Create mapping |
| Wrong values | Incorrect multiplier | Edit mapping |

---

## 📝 Notes

**Record your verification results:**

**Installation Date:** _______________

**Verified By:** _______________

**Issues Found:** 

_______________________________________

_______________________________________

**Resolution:** 

_______________________________________

_______________________________________

**Production Ready:** ☐ Yes  ☐ No

---

## ✅ Final Sign-off

Once all items are checked:

- [ ] All components installed
- [ ] All tests passed
- [ ] Documentation reviewed
- [ ] Team trained
- [ ] Ready for production

**Approved by:** _______________

**Date:** _______________

---

**🎉 Congratulations! Your REST API Metric Field Mapping system is fully verified and operational!**
