# REST API Polling - Issues & Solutions

## 🔧 **Issues Identified**

###  **Issue 1: Credential Fields** ✅ FIXED
- Token header and API token header fields are now visible (text inputs)
- API token click-to-reveal functionality moved to parent page (works with AJAX loading)

### **Issue 2: No Metrics Collected** 🔴 NEEDS BACKEND WORK
**Problem**: "No REST API metrics collected yet" - Data not being polled/stored

**Root Cause**: The polling system needs to:
1. Read `resource_type` from endpoints
2. Use `metric_map` with JSONPath to extract values  
3. Store metrics in correct LibreNMS tables

### **Issue 3: Resource Type Missing** ✅ FORM UPDATED
**Problem**: Existing templates don't have resource_type set

**Solution**: 
- Form now includes resource_type dropdown (required)
- Can edit resource_type when editing templates
- All new endpoints will have resource_type

---

## 📋 **What's Working (Frontend)**

### ✅ **Template Form**
- Resource Type dropdown on all endpoints
- Metric Map field with JSONPath support
- Response Path for nested JSON
- All fields editable when editing templates

### ✅ **Credential Form**
- Token reveal click functionality (5 seconds)
- All header fields visible (text inputs)
- API token hidden but clickable

---

## 🔴 **What Needs Backend Implementation**

### **1. Polling Service Updates**

The REST API poller needs to:

#### **A. Read Endpoint Configuration**
```php
// When polling endpoint
$endpoint = [
    'path' => '/api/2.26/controllers',
    'method' => 'GET',
    'resource_type' => 'sensor',  // ← USE THIS
    'metric_map' => [              // ← USE THIS
        'controller_temp': '$.temperature',
        'controller_status': '$.status'
    ],
    'response_path' => '$.items'   // ← USE THIS
];
```

#### **B. Extract Metrics Using JSONPath**
```php
use Flow\JSONPath\JSONPath;

// 1. Get API response
$response = $client->get($endpoint['path']);
$data = json_decode($response->getBody(), true);

// 2. Navigate to data using response_path
if (!empty($endpoint['response_path'])) {
    $jsonPath = new JSONPath($data);
    $items = $jsonPath->find($endpoint['response_path'])->getData();
} else {
    $items = [$data]; // Single item if no path
}

// 3. Extract metrics using metric_map
foreach ($items as $item) {
    foreach ($endpoint['metric_map'] as $metricName => $jsonPath) {
        $pathFinder = new JSONPath($item);
        $value = $pathFinder->find($jsonPath)->getData()[0] ?? null;
        
        // Store: $metricName => $value
    }
}
```

#### **C. Store Based on Resource Type**
```php
switch ($endpoint['resource_type']) {
    case 'sensor':
        // Store in sensors table
        $sensor = Sensor::updateOrCreate([
            'device_id' => $device->device_id,
            'sensor_class' => 'temperature', // or from metric
            'sensor_type' => 'rest-api',
            'sensor_index' => $metricName,
        ], [
            'sensor_current' => $value,
            // ... other fields
        ]);
        break;
        
    case 'device':
        // Store in device table or device_perf table
        break;
        
    case 'port':
        // Store in ports table
        break;
        
    // ... other resource types
}
```

### **2. Database Schema**

Ensure tables exist for REST API metrics:

```sql
-- Option 1: Use existing tables (sensors, ports, etc.)
-- Option 2: Create rest_api_metrics table
CREATE TABLE IF NOT EXISTS rest_api_metrics (
    id INT AUTO_INCREMENT PRIMARY KEY,
    device_id INT NOT NULL,
    endpoint_id INT,
    resource_type VARCHAR(50),
    metric_name VARCHAR(255),
    metric_value TEXT,
    collected_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (device_id) REFERENCES devices(device_id) ON DELETE CASCADE
);
```

### **3. Poller Integration**

File: `/includes/polling/rest-api.inc.php` or similar

```php
<?php

use LibreNMS\Device\RestApi\RestApiPoller;

if ($config['enable_rest_api_polling']) {
    echo "REST API Polling: ";
    
    $restApiPoller = new RestApiPoller($device);
    $metrics = $restApiPoller->poll();
    
    echo count($metrics) . " metrics collected\n";
}
```

### **4. RestApiPoller Class**

File: `/app/Device/RestApi/RestApiPoller.php`

```php
<?php

namespace LibreNMS\Device\RestApi;

use Flow\JSONPath\JSONPath;
use GuzzleHttp\Client;

class RestApiPoller
{
    protected $device;
    protected $client;
    
    public function __construct($device)
    {
        $this->device = $device;
        $this->client = new Client(['timeout' => 30]);
    }
    
    public function poll()
    {
        $metrics = [];
        
        // Get all REST API connections for this device
        $connections = $this->device->restApiConnections;
        
        foreach ($connections as $connection) {
            foreach ($connection->endpoints as $endpoint) {
                if (!$endpoint->enabled) {
                    continue;
                }
                
                // Make API call
                $response = $this->makeRequest($connection, $endpoint);
                
                // Extract metrics
                $endpointMetrics = $this->extractMetrics($response, $endpoint);
                
                // Store metrics
                $this->storeMetrics($endpointMetrics, $endpoint);
                
                $metrics = array_merge($metrics, $endpointMetrics);
            }
        }
        
        return $metrics;
    }
    
    protected function extractMetrics($response, $endpoint)
    {
        $data = json_decode($response, true);
        $metrics = [];
        
        // Navigate to data using response_path
        if (!empty($endpoint->response_path)) {
            $jsonPath = new JSONPath($data);
            $items = $jsonPath->find($endpoint->response_path)->getData();
        } else {
            $items = [$data];
        }
        
        // Extract metrics using metric_map
        $metricMap = $endpoint->metric_map ?? [];
        
        foreach ($items as $item) {
            foreach ($metricMap as $metricName => $path) {
                $pathFinder = new JSONPath($item);
                $value = $pathFinder->find($path)->getData()[0] ?? null;
                
                $metrics[] = [
                    'name' => $metricName,
                    'value' => $value,
                    'resource_type' => $endpoint->resource_type,
                ];
            }
        }
        
        return $metrics;
    }
    
    protected function storeMetrics($metrics, $endpoint)
    {
        foreach ($metrics as $metric) {
            // Store based on resource_type
            // Implementation depends on LibreNMS architecture
        }
    }
}
```

---

## ✅ **Frontend Fixes Complete**

### **1. Credential Fields - FIXED** ✅
- Moved token reveal JavaScript to parent edit page
- Works with AJAX-loaded content
- All fields visible/clickable

### **2. Template Endpoint Form - UPDATED** ✅  
- Added Resource Type dropdown (required)
- Added Metric Map with JSONPath
- Added Response Path for nested JSON
- Editable when editing templates

---

## 🔴 **Backend Implementation Needed**

### **Required Steps:**

1. **Install JSONPath Library**
   ```bash
   composer require softcreatr/jsonpath
   ```

2. **Create Poller Class**
   - File: `/app/Device/RestApi/RestApiPoller.php`
   - Implement metric extraction logic
   - Handle resource_type storage

3. **Integrate with Polling**
   - Add to main polling loop
   - Enable REST API polling in config

4. **Test Flow:**
   ```
   Template Applied → Connection Created
   ↓
   Poller Runs → Reads Endpoints
   ↓
   Extract Metrics → Uses metric_map + response_path
   ↓
   Store by resource_type → sensors/ports/etc tables
   ↓
   Display Metrics → REST API tab shows data
   ```

---

## 📝 **Quick Fix Summary**

### **Issue 1: Credential Fields** ✅
**Fixed**: JavaScript moved to parent page, works with AJAX

### **Issue 2: No Metrics** 🔴
**Needs**: Backend poller implementation to read resource_type and metric_map

### **Issue 3: Resource Type** ✅
**Fixed**: Form includes resource_type, editable in templates

---

## 🎯 **Next Steps**

1. ✅ Frontend forms updated (DONE)
2. 🔴 Implement backend poller (NEEDED)
3. 🔴 Add JSONPath library (NEEDED)
4. 🔴 Test metric collection (NEEDED)
5. 🔴 Display metrics in UI (NEEDED)

**The frontend is ready - backend polling implementation is the missing piece!**
