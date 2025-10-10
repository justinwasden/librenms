# REST API Metrics - Change Detection & History System

## Overview

The REST API polling system has been optimized to only store metric changes, reducing unnecessary database writes and making it easier to track trends over time.

## How It Works

### 1. Change Detection
Instead of deleting and reinserting all metrics on every poll, the system now:

- **Compares new values** with existing values in the database
- **Only updates metrics that have changed** (with a 0.0001 tolerance for floating point numbers)
- **Updates timestamps** for unchanged metrics to track last poll time
- **Inserts new metrics** when they first appear
- **Deletes metrics** that no longer appear in API responses

### 2. Resource Management
The system automatically handles resources (hosts, volumes, controllers, etc.):

- **Tracks current resources** from each API poll
- **Removes stale resources** that no longer exist (e.g., deleted hosts)
- **Adds new resources** automatically when they appear

### 3. Historical Trending
Changed metrics are archived to a separate history table:

- **device_api_metrics** - Current state (one row per metric)
- **device_api_metrics_history** - Historical changes for trending

This design keeps the main metrics table small while preserving change history for graphing and analysis.

## Database Tables

### device_api_metrics (Current State)
Stores the latest value for each metric:
- One row per resource metric
- Updated only when values change
- `collected_at` updated on every poll
- `updated_at` only when value changes

### device_api_metrics_history (Trending Data)
Stores historical metric changes:
- New row inserted only when a metric value changes
- Indexed for efficient time-series queries
- Can be cleaned up periodically to manage size

## Benefits

### Reduced Database Load
- **Before**: Delete + Insert all metrics every poll (28 operations for 14 metrics)
- **After**: Only update changed metrics (typically 0-5 operations per poll)

### Better Trending
- Historical data preserved in dedicated table
- Easy to query for graphs and analytics
- Current state always available in main table

### Automatic Cleanup
- Stale resources automatically removed
- Old metrics deleted when no longer in API
- Historical data can be pruned on schedule

## Usage Examples

### View Current Metrics
```sql
SELECT resource_name, metric_name, value, string_value, collected_at
FROM device_api_metrics
WHERE device_id = 1
  AND resource_type = 'array_controller'
ORDER BY resource_name, metric_name;
```

### View Metric History for Trending
```sql
SELECT collected_at, value
FROM device_api_metrics_history
WHERE device_id = 1
  AND resource_id = 'bf052793-b26f-4347-88ec-c5c20e864580'
  AND metric_name = 'space.total_used'
ORDER BY collected_at DESC
LIMIT 100;
```

### Find Resources That Changed Recently
```sql
SELECT DISTINCT resource_name, resource_type, updated_at
FROM device_api_metrics
WHERE device_id = 1
  AND updated_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
ORDER BY updated_at DESC;
```

## Maintenance

### Cleanup Old History
```bash
# Dry run - see what would be deleted
php artisan api-metrics:cleanup-history --days=30 --dry-run

# Actually delete history older than 30 days
php artisan api-metrics:cleanup-history --days=30

# Keep 90 days of history
php artisan api-metrics:cleanup-history --days=90
```

### Schedule Automatic Cleanup
Add to your cron or Laravel scheduler:
```php
// In app/Console/Kernel.php
$schedule->command('api-metrics:cleanup-history --days=30')->daily();
```

## Log Messages

The system provides detailed logging:

### New Metrics
```
New metric capacity = 25734521290752 for resource RSA-PS-X50
Inserted 14 new metrics for unknown 'RSA-PS-X50'
```

### Changed Metrics
```
Metric space.total_used changed from 10283894774613 to 10285000000000 for resource RSA-PS-X50
Updated 1 changed metrics for unknown 'RSA-PS-X50'
```

### Unchanged Metrics
```
Metric status unchanged for resource CT0
```

### Removed Resources
```
Removed 6 metrics for 1 stale resources from endpoint Controllers Status: OLD-CT2
```

## Performance Impact

### Typical Scenario (Pure Storage Array)
- **Before**: 28 SQL operations per poll (14 metrics × 2 operations)
- **After**: 14 SQL operations first poll, then 0-2 operations when unchanged

### Real-World Example
For a Pure Storage array with:
- 1 array info endpoint (14 metrics)
- 2 controllers (6 metrics each)
- Poll interval: 5 minutes

**Previous behavior**: 312 operations/hour
**Optimized behavior**: ~30 operations/hour (90% reduction)

## Migration

The migration creates the history table:
```bash
php artisan migrate
```

This adds `device_api_metrics_history` table with appropriate indexes for time-series queries.

## Troubleshooting

### Metrics Not Updating
Check if values are actually changing. The system only records changes, so static values won't create new history entries.

### History Table Growing Too Large
Run the cleanup command more frequently or reduce retention days:
```bash
php artisan api-metrics:cleanup-history --days=7
```

### Missing Historical Data
History is only recorded when values change. If a metric never changes, it will only have one entry in the main table and no history.
