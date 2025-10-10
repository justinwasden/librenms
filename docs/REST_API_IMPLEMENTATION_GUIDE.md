# REST API Module Integration - Complete Implementation Guide

## ✅ Current Status

### What's Already Working
1. **Database Schema** - All migrations are in place and properly structured
2. **Models & Relationships** - Complete Eloquent models with proper relationships
3. **Routes** - Comprehensive routing for both device-level and global management
4. **Controllers** - Well-structured controllers separating concerns
5. **Views** - Modern Blade templates with Alpine.js interactivity
6. **Service Provider** - Registered in `bootstrap/providers.php`

## 🔧 Required Fixes

### 1. Module Architecture (✅ FIXED)

**Problem:** Your original module classes used non-existent interfaces.

**Solution:** Created new `LibreNMS\Modules\RestApi.php` that properly implements the `Module` interface. This module:
- Implements all required methods from `LibreNMS\Interfaces\Module`
- Checks for REST API connections before running
- Properly integrates with LibreNMS discovery and polling cycles
- Handles cleanup when disabled
- Provides dump functionality for testing

**Files Modified:**
- ✅ Created: `/LibreNMS/Modules/RestApi.php` (NEW ARCHITECTURE)
- ✅ Deprecated: `/LibreNMS/Discovery/RestApi.php` (legacy)
- ✅ Deprecated: `/LibreNMS/Polling/RestApi.php` (legacy)
- ✅ Deprecated: `/includes/discovery/restapi.inc.php` (legacy)
- ✅ Deprecated: `/includes/polling/restapi.inc.php` (legacy)

### 2. Module Registration

The module will be automatically discovered by LibreNMS via:
```php
LibreNMS\Util\Module::fromName('rest-api')
```

This works because:
1. Class exists at `LibreNMS\Modules\RestApi`
2. LibreNMS converts 'rest-api' to 'RestApi' class name automatically
3. No config.php changes needed!

### 3. Enable the Module

To enable the module for devices, users can:

**Option A: Via WebUI (Recommended)**
1. Go to device edit page
2. Navigate to Modules tab
3. Enable "RestApi" module

**Option B: Via Config**
Add to `config.php`:
```php
$config['discovery_modules']['rest-api'] = true;
$config['poller_modules']['rest-api'] = true;
```

**Option C: Per-Device Override**
```php
// Enable for specific device
$device->setAttrib('poll_rest-api', 'true');
$device->setAttrib('discover_rest-api', 'true');
```

## 📋 Template Forms - Current Issues & Solutions

### Issues with Template Edit Form

1. **Endpoint Management Not Saving to Template**
   - The Alpine.js endpoint manager saves via AJAX to device connections
   - But templates aren't tied to devices, so this won't work
   - Need different approach for template endpoints

2. **Route Mismatch**
   - Edit form tries to use device connection routes
   - Templates should save directly to `template_data` JSON

### Recommended Template Architecture

#### For Global Templates (Settings):
Templates should store configuration as JSON in `template_data` field:

```json
{
  "connections": [
    {
      "name": "API Connection",
      "credential_type": "bearer_token",
      "base_url": "{{ $device->ip }}",
      "disable_ssl_verify": true,
      "endpoints": [
        {
          "name": "System Status",
          "path": "/api/v1/status",
          "method": "GET",
          "resource_type": "device",
          "enabled": true,
          "metric_map": {
            "cpu_usage": "metrics.cpu.percentage",
            "memory_usage": "metrics.memory.percentage"
          }
        }
      ]
    }
  ]
}
```

#### When Applying Template to Device:
1. Parse `template_data` JSON
2. Replace placeholders (`{{ $device->hostname }}`, `{{ $device->ip }}`, etc.)
3. Create actual `RestApiConnection` and `RestApiEndpoint` records
4. Link credential to connection

## 🎯 Next Steps to Complete Implementation

### Step 1: Fix Template Controller Update Method

The template update should handle the full JSON properly:

```php
public function update(Request $request, RestApiTemplate $template)
{
    $validated = $request->validate([
        'name' => 'required|string|max:255|unique:rest_api_templates,name,' . $template->id,
        'vendor' => 'nullable|string|max:255',
        'resource_type' => 'nullable|string|max:50',
        'template_data' => 'required',
        'description' => 'nullable|string',
    ]);

    // If template_data comes as string, decode it
    if (is_string($validated['template_data'])) {
        $validated['template_data'] = json_decode($validated['template_data'], true);
    }

    $template->update($validated);

    return redirect()
        ->route('settings.rest-api.templates.edit', $template->id)
        ->with('success', 'Template updated successfully.');
}
```

### Step 2: Improve Template Apply Logic

When applying a template to a device:

```php
public function applyTemplate(Request $request, Device $device)
{
    $template = RestApiTemplate::findOrFail($request->template_id);
    $credential = RestApiCredential::findOrFail($request->credential_id);
    
    $templateData = is_array($template->template_data) 
        ? $template->template_data 
        : json_decode($template->template_data, true);

    foreach ($templateData['connections'] ?? [] as $connData) {
        // Replace placeholders
        $connData = $this->replacePlaceholders($connData, $device);
        
        // Create connection
        $connection = $device->restApiConnections()->create([
            'credential_id' => $credential->id,
            'name' => $connData['name'] ?? 'API Connection',
            'base_url' => $connData['base_url'],
            'rate_limit' => $connData['rate_limit'] ?? 60,
            'enabled' => $connData['enabled'] ?? true,
            'disable_ssl_verify' => $connData['disable_ssl_verify'] ?? false,
        ]);

        // Create endpoints
        foreach ($connData['endpoints'] ?? [] as $epData) {
            $connection->endpoints()->create([
                'name' => $epData['name'],
                'path' => $epData['path'],
                'method' => $epData['method'] ?? 'GET',
                'resource_type' => $epData['resource_type'] ?? 'custom',
                'query_params' => $epData['query_params'] ?? null,
                'headers' => $epData['headers'] ?? null,
                'body' => $epData['body'] ?? null,
                'metric_map' => $epData['metric_map'] ?? null,
            ]);
        }
    }

    return response()->json(['success' => true]);
}
```

### Step 3: Fix Template Edit Form

Replace the complex Alpine.js endpoint manager with a simpler JSON editor for templates:

```blade
<!-- In edit.blade.php -->
<div class="form-group">
    <label for="template_data">Template Configuration (JSON)</label>
    <textarea name="template_data" 
              id="template_data" 
              class="form-control font-monospace" 
              rows="25" 
              required>{{ old('template_data', json_encode($template->template_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) }}</textarea>
    
    <small class="form-text text-muted">
        Define connections and endpoints in JSON format. Use placeholders like:
        <code>{{ '{{ $device->hostname }}' }}</code>, 
        <code>{{ '{{ $device->ip }}' }}</code>, 
        <code>{{ '{{ $device->getAttrib("some_key") }}' }}</code>
    </small>
</div>

<!-- Add JSON validator -->
<script>
document.getElementById('template_data').addEventListener('blur', function() {
    try {
        JSON.parse(this.value);
        this.classList.remove('is-invalid');
        this.classList.add('is-valid');
    } catch (e) {
        this.classList.remove('is-valid');
        this.classList.add('is-invalid');
        alert('Invalid JSON: ' + e.message);
    }
});
</script>
```

### Step 4: Add Visual Template Builder (Optional Enhancement)

For better UX, create a visual builder that generates the JSON:

```blade
<div x-data="templateBuilder()">
    <!-- Connection Builder UI -->
    <button @click="addConnection()">Add Connection</button>
    
    <template x-for="(conn, idx) in connections" :key="idx">
        <div>
            <input type="text" x-model="conn.name" placeholder="Connection Name">
            <input type="text" x-model="conn.base_url" placeholder="Base URL">
            
            <!-- Endpoint Builder -->
            <button @click="addEndpoint(idx)">Add Endpoint</button>
            <template x-for="(ep, epIdx) in conn.endpoints" :key="epIdx">
                <div>
                    <input type="text" x-model="ep.name" placeholder="Endpoint Name">
                    <input type="text" x-model="ep.path" placeholder="Path">
                    <!-- ... -->
                </div>
            </template>
        </div>
    </template>
    
    <!-- Hidden field with JSON -->
    <input type="hidden" name="template_data" :value="JSON.stringify({connections: connections})">
</div>

<script>
function templateBuilder() {
    return {
        connections: @json($template->template_data['connections'] ?? []),
        addConnection() {
            this.connections.push({
                name: '',
                base_url: '',
                endpoints: []
            });
        },
        addEndpoint(connIdx) {
            this.connections[connIdx].endpoints.push({
                name: '',
                path: '',
                method: 'GET',
                resource_type: 'custom',
                metric_map: {}
            });
        }
    }
}
</script>
```

## 🚀 Testing Your Module

### 1. Check Module is Loaded
```bash
php artisan tinker
>>> LibreNMS\Util\Module::exists('rest-api');
=> true
>>> $module = LibreNMS\Util\Module::fromName('rest-api');
=> LibreNMS\Modules\RestApi
```

### 2. Enable for a Device
```bash
./lnms device:poll {device_id} -m rest-api -vvv
```

### 3. Check Discovery Works
```bash
./discover.php -h {hostname} -m rest-api -d
```

### 4. Verify Data Collection
```bash
php artisan tinker
>>> $device = App\Models\Device::first();
>>> $device->restApiConnections()->count();
>>> $device->restApiConnections()->with('endpoints')->get();
```

## 🔍 Key Architecture Decisions

### Why Not Use Legacy Include Files?

**Your Approach (✅ Correct):**
- Modern Laravel architecture
- Proper dependency injection
- Type safety with models
- Easy to test and maintain
- Follows LibreNMS modern module pattern

**Legacy Approach (❌ Avoid):**
- Uses old PHP include files
- Global variables and arrays
- Hard to maintain
- No type safety

### Module Discovery Flow

```
1. LibreNMS calls discover.php
2. Discovers which modules to run for device
3. Checks: LibreNMS\Util\Module::fromName('rest-api')
4. Finds: LibreNMS\Modules\RestApi
5. Calls: shouldDiscover() -> discover()
6. Your RestApiDiscovery class runs
```

### Polling Flow

```
1. LibreNMS calls poller.php
2. Checks which modules to poll
3. Finds: LibreNMS\Modules\RestApi
4. Calls: shouldPoll() -> poll()
5. Your RestApiPoller class runs
6. Data stored via DataStorageInterface
```

## 📊 Data Flow

### Discovery Phase
```
Device -> RestApiConnections -> RestApiEndpoints
                              -> Make API Requests
                              -> Parse Responses
                              -> Store Metrics in DB
```

### Polling Phase
```
Device -> RestApiConnections -> RestApiEndpoints
                              -> Make API Requests
                              -> Update Metrics
                              -> Store RRD Data (via DataStorageInterface)
```

## ⚡ Performance Considerations

### 1. Rate Limiting
Already implemented in your `RestApiConnection` model:
```php
'rate_limit' => 60, // requests per minute
```

Make sure your poller respects this:
```php
// In RestApiPoller
protected function checkRateLimit($connection)
{
    $lastCall = Cache::get("rest_api_last_call_{$connection->id}");
    $minInterval = 60 / $connection->rate_limit; // seconds between calls
    
    if ($lastCall && (time() - $lastCall) < $minInterval) {
        sleep($minInterval - (time() - $lastCall));
    }
    
    Cache::put("rest_api_last_call_{$connection->id}", time(), 300);
}
```

### 2. Timeout Settings
```php
$client = new Client([
    'base_uri' => $connection->base_url,
    'timeout' => 15, // Already good!
    'verify' => !$connection->disable_ssl_verify,
]);
```

### 3. Parallel Requests (Future Enhancement)
```php
use GuzzleHttp\Promise;

$promises = [];
foreach ($endpoints as $endpoint) {
    $promises[] = $client->getAsync($endpoint->path);
}

$results = Promise\Utils::settle($promises)->wait();
```

## 🛠️ Missing Pieces to Implement

### 1. MetricsStager Class
You reference this but I don't see it. Create it:

```php
namespace App\RestApi\Metrics;

use App\Models\Device;

class MetricsStager
{
    protected Device $device;
    
    public function __construct(Device $device)
    {
        $this->device = $device;
    }
    
    public function stageMetrics(array $metrics, bool $isPoller = false)
    {
        foreach ($metrics as $key => $value) {
            // Store in appropriate table based on resource type
            // For polling: also create RRD files
            if ($isPoller) {
                $this->storeRrdData($key, $value);
            }
        }
    }
    
    protected function storeRrdData($key, $value)
    {
        // Use LibreNMS RRD helper
        $rrd_def = RrdDefinition::make()->addDataset($key, 'GAUGE', 0);
        
        app('Datastore')->put(
            ['device_id' => $this->device->device_id],
            "rest_api_$key",
            ['rrd_def' => $rrd_def],
            $value
        );
    }
}
```

### 2. ApiMetricsCollector Class
Referenced in discovery:

```php
namespace App\Pollers;

use App\Models\Device;
use App\Models\RestApiMetric;

class ApiMetricsCollector
{
    protected Device $device;
    
    public function __construct(Device $device)
    {
        $this->device = $device;
    }
    
    public function storeMetric(string $resourceType, string $endpointName, array $metrics)
    {
        // Store discovered metrics for reference
        foreach ($metrics as $key => $value) {
            RestApiMetric::updateOrCreate(
                [
                    'device_id' => $this->device->device_id,
                    'endpoint_name' => $endpointName,
                    'metric_key' => $key,
                ],
                [
                    'metric_value' => $value,
                    'resource_type' => $resourceType,
                    'last_updated' => now(),
                ]
            );
        }
    }
}
```

### 3. CredentialHelper Class
Already referenced, ensure it exists:

```php
namespace App\RestApi\Credentials;

class CredentialHelper
{
    public static function getAuthHeader(array $credential): array
    {
        $authType = $credential['authentication_type']['name'] ?? '';
        $params = collect($credential['params'] ?? [])->pluck('value', 'key');
        
        switch (strtolower($authType)) {
            case 'bearer token':
                return [
                    'Authorization' => 'Bearer ' . $params['token'],
                ];
                
            case 'api key':
                $headerName = $params['header_name'] ?? 'X-API-Key';
                return [
                    $headerName => $params['api_key'],
                ];
                
            case 'basic auth':
                $auth = base64_encode($params['username'] . ':' . $params['password']);
                return [
                    'Authorization' => 'Basic ' . $auth,
                ];
                
            case 'session token':
                // Session token requires login first
                return [
                    $params['token_header'] ?? 'X-Auth-Token' => $params['session_token'] ?? '',
                ];
                
            default:
                return [];
        }
    }
}
```

### 4. JsonFlattener Utility
```php
namespace App\RestApi\Utils;

class JsonFlattener
{
    public static function flatten(array $data, string $prefix = '', string $separator = '_'): array
    {
        $result = [];
        
        foreach ($data as $key => $value) {
            $newKey = $prefix . $key;
            
            if (is_array($value)) {
                if (self::isAssociative($value)) {
                    $result = array_merge($result, self::flatten($value, $newKey . $separator, $separator));
                } else {
                    // Numeric array - store as JSON or skip
                    $result[$newKey] = json_encode($value);
                }
            } else {
                $result[$newKey] = $value;
            }
        }
        
        return $result;
    }
    
    protected static function isAssociative(array $arr): bool
    {
        if ([] === $arr) return false;
        return array_keys($arr) !== range(0, count($arr) - 1);
    }
}
```

## 🎨 Credential Management UI

The credential forms look good. Make sure you have partials for each auth type:

**File: `resources/views/settings/rest-api/credentials/partials/bearer-token.blade.php`**
```blade
<div class="form-group">
    <label for="params_token">Bearer Token <span class="text-danger">*</span></label>
    <input type="text" 
           name="params[token]" 
           id="params_token" 
           class="form-control" 
           value="{{ old('params.token', $credential->params->where('key', 'token')->first()->value ?? '') }}" 
           required>
</div>
```

**File: `resources/views/settings/rest-api/credentials/partials/api-key.blade.php`**
```blade
<div class="form-group">
    <label for="params_api_key">API Key <span class="text-danger">*</span></label>
    <input type="text" 
           name="params[api_key]" 
           id="params_api_key" 
           class="form-control" 
           value="{{ old('params.api_key', $credential->params->where('key', 'api_key')->first()->value ?? '') }}" 
           required>
</div>

<div class="form-group">
    <label for="params_header_name">Header Name</label>
    <input type="text" 
           name="params[header_name]" 
           id="params_header_name" 
           class="form-control" 
           value="{{ old('params.header_name', $credential->params->where('key', 'header_name')->first()->value ?? 'X-API-Key') }}" 
           placeholder="X-API-Key">
</div>
```

## 📝 Summary of Changes Made

### Files Created:
1. ✅ `LibreNMS/Modules/RestApi.php` - Main module implementation

### Files Modified:
1. ✅ `LibreNMS/Discovery/RestApi.php` - Deprecated
2. ✅ `LibreNMS/Polling/RestApi.php` - Deprecated  
3. ✅ `includes/discovery/restapi.inc.php` - Deprecated
4. ✅ `includes/polling/restapi.inc.php` - Deprecated

### Files That Need Creation:
1. ⏳ `app/RestApi/Metrics/MetricsStager.php`
2. ⏳ `app/Pollers/ApiMetricsCollector.php`
3. ⏳ `app/RestApi/Credentials/CredentialHelper.php` (might exist)
4. ⏳ `app/RestApi/Utils/JsonFlattener.php` (might exist)

## 🚦 Action Items

### Immediate (Required for Basic Functionality):
1. ✅ Module architecture fixed
2. ⏳ Create missing utility classes (MetricsStager, ApiMetricsCollector, etc.)
3. ⏳ Simplify template edit form to use JSON editor
4. ⏳ Test discovery and polling with a real device

### Short-term (Improve UX):
1. ⏳ Add visual template builder
2. ⏳ Improve error handling and logging
3. ⏳ Add template testing/preview functionality
4. ⏳ Create seeder with example templates

### Long-term (Nice to Have):
1. ⏳ GraphQL support
2. ⏳ OAuth2 authentication
3. ⏳ Metric transformation pipeline
4. ⏳ Rate limiting with Redis
5. ⏳ Async/queue-based polling for high-volume APIs

## 🎯 Path Forward

**Best approach to complete this:**

1. **Test the module registration** (5 min)
   ```bash
   ./lnms device:poll 1 -m rest-api -vvv
   ```

2. **Create missing utility classes** (30 min)
   - Copy the code snippets above
   - Adjust namespaces if needed

3. **Simplify template forms** (1 hour)
   - Remove complex Alpine.js endpoint manager
   - Use simple JSON textarea
   - Add validation

4. **Test with real device** (30 min)
   - Create a credential
   - Create a template
   - Apply to device
   - Run discovery
   - Run poller
   - Check data

5. **Fix any issues** (variable)
   - Check logs
   - Debug step by step
   - Iterate

You have a solid foundation - the architecture is good, you just need to connect the remaining pieces!
