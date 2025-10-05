# REST API Metric Field Mapping System

## Overview

The Metric Field Mapping system automatically maps REST API metrics to LibreNMS database fields, allowing seamless integration of API data into the standard LibreNMS data model.

## Architecture

### Components

1. **MetricFieldMapping Model** - Stores mapping configurations
2. **DataMatcher Service** - Handles automatic metric matching logic
3. **RestApi Module** - Integrates matching into the polling cycle
4. **MatchMetrics Command** - CLI tool for manual matching
5. **Admin UI** - Web interface for managing mappings

### Data Flow

```
REST API Poll → device_api_metrics table → DataMatcher Service → LibreNMS tables (devices, sensors, ports, etc.)
```

## How It Works

### 1. Metric Collection

When the REST API poller runs, it:
- Fetches data from configured API endpoints
- Stores metrics in `device_api_metrics` table
- Marks metrics as unmatched (`matched_at = NULL`)

### 2. Automatic Matching

The `DataMatcher` service:
1. **Static Mapping** - Tries built-in mappings first (common metric names)
2. **Dynamic Mapping** - Queries `metric_field_mappings` table for custom mappings
3. **Placeholder Creation** - Creates unmatched placeholders for admin review

### 3. Data Storage

Matched metrics are stored in appropriate LibreNMS tables:
- **devices** - Device-level metrics (serial, version, capacity)
- **sensors** - Sensor readings (temperature, power, fan speed)
- **ports** - Interface metrics (speed, status, errors)

## Database Tables

### metric_field_mappings

| Column | Type | Description |
|--------|------|-------------|
| metric_name | string | API metric name (lowercase) |
| resource_type | string | Type of resource (array, volume, interface) |
| vendor | string | Device vendor (optional, for specificity) |
| os | string | Device OS (optional, for specificity) |
| librenms_table | string | Target LibreNMS table |
| librenms_field | string | Target table field |
| data_type | enum | numeric, string, boolean, json |
| unit | string | Measurement unit |
| multiplier | decimal | Value transformation multiplier |
| enabled | boolean | Active status |
| auto_learned | boolean | System-created vs manual |

### device_api_metrics (extended)

New columns added:
- `matched_at` - Timestamp when metric was matched
- `mapping_id` - Foreign key to the mapping used

## Usage

### CLI Commands

```bash
# Match all unmatched metrics
php artisan metrics:match

# Match metrics for specific device
php artisan metrics:match --device_id=123

# Match metrics for specific vendor
php artisan metrics:match --vendor=PureStorage

# Reset and re-match all metrics
php artisan metrics:match --reset

# Show unmatched metrics
php artisan metrics:match --show-unmatched
```

### Admin UI

Access at: `/admin/metric-field-mappings`

Features:
- View all mappings
- Filter by vendor, OS, status, type
- Create manual mappings
- Edit auto-learned mappings
- Enable/disable mappings
- Run matching from web UI
- Bulk delete unmatched placeholders

### Programmatic Usage

```php
use App\Services\DataMatcher;
use App\Models\Device;

$device = Device::find(123);
$matcher = new DataMatcher();
$stats = $matcher->processDeviceMetrics($device);

// Returns:
// [
//     'matched' => 10,
//     'unmatched' => 2,
//     'errors' => 0
// ]
```

## Creating Custom Mappings

### Via Admin UI

1. Navigate to Admin → Metric Field Mappings
2. Click "Create New Mapping"
3. Fill in:
   - Metric Name (e.g., "controller_temp")
   - Resource Type (optional, e.g., "controller")
   - Vendor/OS (optional, for specificity)
   - LibreNMS Table (e.g., "sensors")
   - LibreNMS Field (e.g., "sensor_current")
   - Data Type
   - Unit (optional)
   - Multiplier (optional, for unit conversion)
4. Save

### Via Database

```php
use App\Models\MetricFieldMapping;

MetricFieldMapping::create([
    'metric_name' => 'array_capacity_total',
    'resource_type' => 'array',
    'vendor' => 'PureStorage',
    'os' => 'Purity',
    'librenms_table' => 'devices',
    'librenms_field' => 'storage_total',
    'data_type' => 'numeric',
    'unit' => 'bytes',
    'multiplier' => 1.0,
    'enabled' => true,
    'auto_learned' => false,
]);
```

## Static Mappings

Built-in mappings are defined in `DataMatcher::$staticMap`:

### Device Metrics
- `serial`, `serial_number` → `devices.serial`
- `model`, `hardware_model` → `devices.hardware`
- `firmware_version`, `firmware` → `devices.version`
- `total_capacity` → `devices.storage_total`

### Sensor Metrics
- `temperature`, `temp` → `sensors.sensor_current` (class: temperature)
- `power`, `power_consumption` → `sensors.sensor_current` (class: power)
- `fan_speed`, `fanspeed` → `sensors.sensor_current` (class: fanspeed)

### Port Metrics
- `interface_speed`, `speed` → `ports.ifSpeed`
- `interface_status`, `oper_status` → `ports.ifOperStatus`

## Vendor/OS Specificity

Mappings support vendor and OS specificity for handling identical metric names differently across platforms:

```php
// Generic mapping for all vendors
MetricFieldMapping::create([
    'metric_name' => 'status',
    'vendor' => null,
    'os' => null,
    'librenms_table' => 'devices',
    'librenms_field' => 'status',
]);

// Specific mapping for PureStorage
MetricFieldMapping::create([
    'metric_name' => 'status',
    'vendor' => 'PureStorage',
    'os' => 'Purity',
    'librenms_table' => 'sensors',
    'librenms_field' => 'sensor_current',
]);
```

The matcher prefers specific mappings over generic ones.

## Value Transformation

### Multiplier

Use multipliers for unit conversions:

```php
// Convert KB to bytes
'multiplier' => 1024

// Convert percentage (0-100) to decimal (0-1)
'multiplier' => 0.01
```

### Data Types

- **numeric** - Numeric values, applies multiplier
- **string** - String values
- **boolean** - Boolean (0/1, true/false)
- **json** - Complex objects, stored as JSON

## Sensor Handling

Sensors require special handling:

1. **Sensor Class** - Auto-determined from metric name:
   - `temperature`, `temp` → temperature
   - `power` → power
   - `fan`, `fanspeed` → fanspeed
   - `voltage`, `volt` → voltage

2. **Sensor Index** - Generated from resource_id: `api-{resource_id}`

3. **Sensor Creation** - Automatically creates sensor if doesn't exist

## Troubleshooting

### Metrics Not Matching

1. Check if metric exists in `device_api_metrics`
2. Verify mapping exists and is enabled
3. Check vendor/OS specificity
4. Run with `--reset` flag to re-process

### View Unmatched Metrics

```bash
php artisan metrics:match --show-unmatched
```

Or in Admin UI:
- Filter by Status: "Unmatched"
- These are placeholders waiting for configuration

### Debug Matching

Check logs in `storage/logs/laravel.log`:
- DataMatcher logs all matches/unmatches
- Error details for failed storage attempts

## Integration with Polling

The `RestApi` module automatically calls `DataMatcher` after polling:

```php
// In LibreNMS/Modules/RestApi.php
public function poll(OS $os, DataStorageInterface $datastore): void
{
    // 1. Poll APIs
    $poller = new ApiPoller($device);
    $poller->poll();

    // 2. Match metrics
    $matcher = new DataMatcher();
    $stats = $matcher->processDeviceMetrics($device);

    // 3. Log results
    echo sprintf(
        " REST API Metrics: %d matched, %d unmatched\n",
        $stats['matched'],
        $stats['unmatched']
    );
}
```

## Best Practices

1. **Start with Static Mappings** - Use built-in mappings when possible
2. **Vendor-Specific When Needed** - Only add vendor/OS when metrics conflict
3. **Regular Review** - Check unmatched metrics periodically
4. **Document Custom Mappings** - Add descriptions to manual mappings
5. **Test Before Enabling** - Create disabled, test, then enable
6. **Use Multipliers Wisely** - Ensure unit conversions are correct

## API Routes

```
GET  /admin/metric-field-mappings           # List mappings
GET  /admin/metric-field-mappings/create    # Create form
POST /admin/metric-field-mappings           # Store mapping
GET  /admin/metric-field-mappings/{id}/edit # Edit form
PUT  /admin/metric-field-mappings/{id}      # Update mapping
DELETE /admin/metric-field-mappings/{id}    # Delete mapping
POST /admin/metric-field-mappings/{id}/toggle # Toggle enabled
POST /admin/metric-field-mappings/run-matching # Run CLI command
DELETE /admin/metric-field-mappings/bulk/unmatched # Delete all unmatched
```

## Future Enhancements

- Mapping templates for common vendors
- Metric value validation rules
- Mapping import/export
- Automatic mapping suggestions based on field name similarity
- Graphing integration for matched metrics
- Alerting on unmatched metrics
