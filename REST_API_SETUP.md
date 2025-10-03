# REST API Polling for LibreNMS - Setup Guide

## Overview

This implementation adds comprehensive REST API polling capabilities to LibreNMS, specifically designed for storage arrays like PureStorage but extensible to any REST API.

## What Was Fixed

### 1. Database Table Structure
- **Old**: Code was trying to use `rest_api_metrics` table
- **New**: Correctly uses `device_api_metrics` table with proper structure for resource-based metrics

### 2. Data Storage Logic
- **Old**: Attempted to store all metrics in a flat structure
- **New**: Properly stores metrics with resource context (resource_type, resource_id, resource_name)

### 3. API Response Handling
- **Old**: Basic metric mapping without resource awareness
- **New**: Handles PureStorage API format (`{items: [...]}`) and other common patterns

### 4. Metric Value Types
- **Old**: Single value field
- **New**: Separate fields for numeric (`value`) and string (`string_value`) data

## Files Modified/Created

### Core Files
1. **`/app/Pollers/Api.php`** - Main polling logic (UPDATED)
   - Backup created: `/app/Pollers/Api.php.backup`

### Database Migrations
2. **`/database/migrations/2025_10_02_230000_create_device_api_metrics_table.php`** (NEW)
   - Creates the device_api_metrics table

3. **`/database/migrations/2025_10_02_230100_add_resource_fields_to_rest_api_endpoints.php`** (NEW)
   - Adds resource_type, resource_id_path, resource_name_path to rest_api_endpoints

### Helper Scripts
4. **`/scripts/diagnostic_rest_api.php`** (NEW)
   - Diagnostic tool to verify configuration

5. **`/scripts/update_purestorage_endpoints.php`** (NEW)
   - Updates PureStorage endpoint configurations

## Installation Steps

### Step 1: Backup Current Data (Optional but Recommended)

```bash
# Backup current database
mysqldump -u librenms -p librenms > ~/librenms_backup_$(date +%Y%m%d).sql
```

### Step 2: Run Database Migrations

```bash
cd /Users/justinwasden/Documents/GitHub/librenms

# Run the migrations
php artisan migrate

# Verify tables exist
php artisan tinker --execute="echo Schema::hasTable('device_api_metrics') ? 'Table exists' : 'Missing';"
```

### Step 3: Make Scripts Executable

```bash
chmod +x scripts/diagnostic_rest_api.php
chmod +x scripts/update_purestorage_endpoints.php
```

### Step 4: Update PureStorage Endpoints

```bash
# Update endpoint configurations with proper mappings
php scripts/update_purestorage_endpoints.php
```

### Step 5: Run Diagnostics

```bash
# Replace <device_id> with your actual device ID
php scripts/diagnostic_rest_api.php <device_id>
```

### Step 6: Test Polling

```bash
# Run a test poll with verbose output
php lnms device:poll <device_id> -m rest-api -vv
```

### Step 7: Verify Data Storage

```bash
php artisan tinker
```

```php
// Count metrics
DB::table('device_api_metrics')->where('device_id', 1)->count();

// View recent metrics
DB::table('device_api_metrics')
    ->where('device_id', 1)
    ->orderBy('collected_at', 'desc')
    ->limit(10)
    ->get(['resource_type', 'resource_name', 'metric_name', 'value', 'string_value']);
```

## Troubleshooting

Run diagnostic script first:
```bash
php scripts/diagnostic_rest_api.php <device_id>
```

Check logs:
```bash
tail -f /opt/librenms/logs/librenms.log | grep -i "rest api"
```

See actual API responses:
```bash
php lnms device:poll <device_id> -m rest-api -vv 2>&1 | grep -A 20 "API Response"
```

## Next Steps

1. Schedule regular polling in cron
2. Create graphs/dashboards using the metrics
3. Set up alerts for capacity thresholds
4. Add more endpoints as needed

For complete documentation, see the artifacts created during setup.
