# REST API Overview Pages - Blade Template Implementation

## 🎉 Overview

The REST API overview pages have been **converted to modern Laravel Blade templates**, providing better maintainability, cleaner syntax, and improved performance through Blade's caching system.

## 📁 New File Structure

### Blade Templates Location
```
/resources/views/device/overview/
├── rest-api.blade.php              # Main router template
└── rest-api/
    ├── purestorage.blade.php       # PureStorage vendor template
    ├── generic.blade.php           # Generic fallback template
    └── [vendor].blade.php          # Additional vendor templates
```

### Legacy Include Files (Deprecated)
```
/includes/html/pages/device/overview/
├── rest-api.inc.php                # OLD - replaced by Blade
└── rest-api/
    ├── purestorage.inc.php         # OLD - replaced by Blade
    └── generic.inc.php             # OLD - replaced by Blade
```

## 🔄 How It Works

### 1. Main Overview Integration
**File:** `/includes/html/pages/device/overview.inc.php`

```php
// OLD WAY (include file)
require 'overview/rest-api.inc.php';

// NEW WAY (Blade template)
echo view('device.overview.rest-api', ['device' => $device])->render();
```

### 2. Router Template Logic
**File:** `/resources/views/device/overview/rest-api.blade.php`

```blade
@php
// Check if REST API enabled
$api_connection = DB::table('rest_api_connections')
    ->where('device_id', $device['device_id'])
    ->where('enabled', 1)
    ->first();

if (!$api_connection) {
    return; // No REST API, skip
}

// Map OS to vendor template
$vendor_os = strtolower($device['os']);
$os_map = [
    'iosxe' => 'ios',
    'fortigate' => 'fortios',
];
$vendor_os = $os_map[$vendor_os] ?? $vendor_os;

// Render vendor template or generic fallback
$vendor_blade = "device.overview.rest-api.{$vendor_os}";
if (view()->exists($vendor_blade)) {
    echo view($vendor_blade, ['device' => $device])->render();
} else {
    echo view('device.overview.rest-api.generic', ['device' => $device])->render();
}
@endphp
```

### 3. Vendor Template Structure
**Example:** `/resources/views/device/overview/rest-api/purestorage.blade.php`

```blade
{{-- Template Comments --}}

@php
// PHP logic block for queries
$metrics = DB::table('device_api_metrics')
    ->where('device_id', $device['device_id'])
    ->get();
@endphp

<!-- HTML Output -->
<div class="row">
    @if($metrics->count() > 0)
        @foreach($metrics as $metric)
            <td>{{ $metric->value }}</td>
        @endforeach
    @endif
</div>
```

## ✨ Benefits of Blade Templates

### 1. **Cleaner Syntax**
```blade
<!-- Blade (Clean) -->
{{ $value }}
{{ Number::formatBi($bytes) }}
@if($condition)
    <div>Content</div>
@endif

<!-- PHP (Verbose) -->
<?php echo htmlspecialchars($value); ?>
<?php echo Number::formatBi($bytes); ?>
<?php if ($condition): ?>
    <div>Content</div>
<?php endif; ?>
```

### 2. **Automatic XSS Protection**
```blade
<!-- Blade auto-escapes -->
{{ $user_input }}  <!-- Safe -->

<!-- Manual escaping needed in PHP -->
<?php echo htmlspecialchars($user_input); ?>

<!-- Unescaped (when needed) -->
{!! $html_content !!}
```

### 3. **Template Inheritance**
```blade
@extends('layouts.librenms')

@section('content')
    <!-- Your content -->
@endsection
```

### 4. **Blade Caching**
- Compiled templates are cached
- Faster rendering on subsequent loads
- Automatic recompilation when changed

### 5. **Better IDE Support**
- Syntax highlighting
- Auto-completion
- Error detection

## 🚀 Creating New Vendor Templates

### Method 1: Copy Existing Template
```bash
cd resources/views/device/overview/rest-api/
cp generic.blade.php netapp.blade.php
# Edit netapp.blade.php
```

### Method 2: Create from Scratch
```blade
{{-- NetApp ONTAP REST API Overview --}}

@php
use Illuminate\Support\Facades\DB;
use LibreNMS\Util\Number;

// Your queries here
$aggregates = DB::table('device_api_metrics')
    ->where('device_id', $device['device_id'])
    ->where('resource_type', 'aggregate')
    ->get();
@endphp

<div class="row">
    <div class="col-md-12">
        <div class="panel panel-default panel-condensed">
            <div class="panel-heading">
                <i class="fa fa-database fa-lg icon-theme"></i>
                <strong>Storage Aggregates</strong>
            </div>
            <table class="table table-hover table-condensed table-striped">
                <thead>
                    <tr>
                        <th>Aggregate</th>
                        <th>Size</th>
                        <th>Used</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($aggregates as $agg)
                    <tr>
                        <td>{{ $agg->resource_name }}</td>
                        <td>{{ Number::formatBi($agg->size ?? 0) }}</td>
                        <td>{{ Number::formatBi($agg->used ?? 0) }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
```

## 🔑 Blade Directives Reference

### Control Structures
```blade
@if($condition)
    <!-- Content -->
@elseif($other)
    <!-- Other -->
@else
    <!-- Default -->
@endif

@unless($condition)
    <!-- Show if false -->
@endunless

@isset($variable)
    <!-- Variable is set -->
@endisset

@empty($collection)
    <!-- Collection is empty -->
@endempty
```

### Loops
```blade
@foreach($items as $item)
    {{ $item->name }}
@endforeach

@forelse($items as $item)
    {{ $item->name }}
@empty
    <p>No items found</p>
@endforelse

@for($i = 0; $i < 10; $i++)
    {{ $i }}
@endfor

@while($condition)
    <!-- Loop -->
@endwhile
```

### PHP Blocks
```blade
@php
    $total = 0;
    foreach ($items as $item) {
        $total += $item->value;
    }
@endphp

<p>Total: {{ $total }}</p>
```

### Comments
```blade
{{-- This is a Blade comment --}}
{{-- It won't appear in HTML --}}

<!-- This is an HTML comment -->
<!-- It appears in the HTML source -->
```

### Including Sub-views
```blade
@include('device.overview.rest-api.components.capacity-bar', [
    'percent' => $capacity_percent,
    'used' => $capacity_used
])
```

## 📊 Data Passing

### From Router to Vendor Template
```blade
{{-- rest-api.blade.php (router) --}}
@php
echo view('device.overview.rest-api.purestorage', [
    'device' => $device,
    'custom_data' => $someValue
])->render();
@endphp

{{-- purestorage.blade.php (vendor) --}}
@php
// $device is available
// $custom_data is available
@endphp
```

### Sharing Data Across Templates
```blade
@php
// Share data with all views
view()->share('global_var', $value);
@endphp
```

## 🎨 Blade Components (Advanced)

### Creating Reusable Components
**File:** `/resources/views/components/metric-panel.blade.php`
```blade
<div class="panel panel-default panel-condensed">
    <div class="panel-heading">
        <i class="fa fa-{{ $icon }} fa-lg icon-theme"></i>
        <strong>{{ $title }}</strong>
        @isset($badge)
            <span class="badge pull-right">{{ $badge }}</span>
        @endisset
    </div>
    {{ $slot }}
</div>
```

**Using Component:**
```blade
<x-metric-panel icon="database" title="Storage Metrics" :badge="$count">
    <table class="table">
        <!-- Table content -->
    </table>
</x-metric-panel>
```

## 🔧 Converting Existing PHP Files to Blade

### Step-by-Step Process

1. **Create Blade file:**
```bash
cp vendor.inc.php ../../resources/views/device/overview/rest-api/vendor.blade.php
```

2. **Convert PHP tags:**
```blade
<!-- Before -->
<?php
$value = 123;
echo $value;
?>

<!-- After -->
@php
$value = 123;
@endphp
{{ $value }}
```

3. **Convert output:**
```blade
<!-- Before -->
<?php echo htmlspecialchars($name); ?>

<!-- After -->
{{ $name }}
```

4. **Convert control structures:**
```blade
<!-- Before -->
<?php if ($condition): ?>
    <div>Content</div>
<?php endif; ?>

<!-- After -->
@if($condition)
    <div>Content</div>
@endif
```

5. **Convert loops:**
```blade
<!-- Before -->
<?php foreach ($items as $item): ?>
    <td><?php echo $item->name; ?></td>
<?php endforeach; ?>

<!-- After -->
@foreach($items as $item)
    <td>{{ $item->name }}</td>
@endforeach
```

## 🚦 Testing Blade Templates

### 1. Clear View Cache
```bash
php artisan view:clear
```

### 2. Test Template Exists
```bash
php artisan tinker
```
```php
view()->exists('device.overview.rest-api.purestorage');
// Should return: true
```

### 3. Test Rendering
```php
$device = \App\Models\Device::find(1);
echo view('device.overview.rest-api.purestorage', ['device' => $device])->render();
```

### 4. Check for Errors
```bash
tail -f /opt/librenms/storage/logs/laravel.log
```

## 🔍 Debugging Blade Templates

### View Compiled Template
```bash
# Blade templates are compiled to PHP
ls -la storage/framework/views/

# View compiled version
cat storage/framework/views/[hash].php
```

### Enable Debug Mode
```php
// In .env file
APP_DEBUG=true

// Or in code
config(['app.debug' => true]);
```

### Dump Variables
```blade
@dump($variable)  <!-- Dumps and continues -->
@dd($variable)    <!-- Dumps and dies -->

{{ var_export($variable) }}
```

## 📈 Performance Considerations

### Blade Caching
- **Automatic:** Blade compiles templates to PHP
- **Location:** `/storage/framework/views/`
- **Invalidation:** Automatic when template changes
- **Manual clear:** `php artisan view:clear`

### Query Optimization
```blade
{{-- BAD: Query in loop --}}
@foreach($devices as $device)
    @php
    $metrics = DB::table('device_api_metrics')
        ->where('device_id', $device->id)
        ->get(); // N+1 problem!
    @endphp
@endforeach

{{-- GOOD: Single query with eager loading --}}
@php
$metrics = DB::table('device_api_metrics')
    ->whereIn('device_id', $devices->pluck('id'))
    ->get()
    ->groupBy('device_id');
@endphp
@foreach($devices as $device)
    @php $device_metrics = $metrics[$device->id] ?? collect(); @endphp
@endforeach
```

## 🔐 Security Best Practices

### XSS Protection
```blade
<!-- Safe (auto-escaped) -->
{{ $user_input }}

<!-- Unsafe (manual escaping needed) -->
{!! $html_content !!}

<!-- Escape in PHP block -->
@php
$safe = htmlspecialchars($unsafe);
@endphp
```

### SQL Injection Prevention
```blade
@php
// GOOD: Parameter binding
$results = DB::table('table')
    ->where('id', $id)
    ->get();

// BAD: String concatenation
$results = DB::select("SELECT * FROM table WHERE id = $id");
@endphp
```

## 📋 Migration Checklist

### Converting to Blade

- [ ] Create Blade template in `/resources/views/device/overview/rest-api/`
- [ ] Convert PHP tags to `@php` blocks
- [ ] Replace `<?php echo` with `{{ }}`
- [ ] Convert control structures to Blade directives
- [ ] Update loops to `@foreach`, `@for`, etc.
- [ ] Replace comments with `{{-- --}}`
- [ ] Test template rendering
- [ ] Clear view cache
- [ ] Verify in browser
- [ ] Update documentation

### Completed Templates

- [x] `rest-api.blade.php` - Main router
- [x] `purestorage.blade.php` - PureStorage
- [x] `generic.blade.php` - Generic fallback
- [ ] `panos.blade.php` - Palo Alto (TODO)
- [ ] `ios.blade.php` - Cisco (TODO)
- [ ] `fortios.blade.php` - Fortinet (TODO)
- [ ] `junos.blade.php` - Juniper (TODO)
- [ ] `truenas.blade.php` - TrueNAS (TODO)
- [ ] `eos.blade.php` - Arista (TODO)

## 🎯 Quick Reference

### File Naming
```
Device OS: purestorage
Template: purestorage.blade.php
View name: device.overview.rest-api.purestorage
```

### Render Template
```php
// From PHP
echo view('device.overview.rest-api.purestorage', ['device' => $device])->render();

// From Blade
@include('device.overview.rest-api.purestorage', ['device' => $device])
```

### Check Template Exists
```php
if (view()->exists('device.overview.rest-api.vendor')) {
    // Template exists
}
```

### Pass Data
```blade
{{ view('template', ['key' => 'value'])->render() }}
```

## 🔗 Resources

- **Laravel Blade Docs:** https://laravel.com/docs/blade
- **LibreNMS Views:** `/resources/views/`
- **Blade Compilation:** `/storage/framework/views/`
- **Clear Cache:** `php artisan view:clear`

---

## 🎉 Summary

**Blade templates provide:**
- ✅ Cleaner, more readable syntax
- ✅ Automatic XSS protection
- ✅ Built-in template caching
- ✅ Better IDE support
- ✅ Easier to maintain and extend
- ✅ Modern Laravel best practices

**Status:** Router and 2 templates converted (PureStorage + Generic)  
**Next:** Convert remaining vendor templates as needed  
**Recommendation:** Use Blade for all new templates
