# Template Endpoints - Resource Type & Metric Mapping

## ✅ **Enhanced Endpoint Configuration**

Templates now include **Resource Type** selection and proper **Metric Map** configuration to prevent null values when applying templates to devices.

---

## 🎯 **New Features Added**

### **1. Resource Type Field**
- **Required dropdown** for each endpoint
- Defines what type of resource the endpoint monitors
- Used by LibreNMS to properly categorize metrics

### **2. Enhanced Metric Mapping**
- **Metric Map** - Replaces problematic response_mapping
- **Response Path** - Navigate nested JSON responses
- **JSONPath support** - Extract values from complex responses
- **Clear examples** - Inline help and placeholders

---

## 📋 **Resource Types Available**

| Resource Type | Description | Example Use |
|---------------|-------------|-------------|
| **Device** | Device-level metrics | CPU, Memory, Uptime |
| **Port** | Network port/interface | Bandwidth, Errors, Status |
| **Storage** | Storage/disk metrics | Used Space, Free Space |
| **Memory Pool** | Memory pool stats | RAM Usage, Buffers, Cache |
| **Processor** | CPU/processor metrics | Load, Temperature, Frequency |
| **Sensor** | Environmental sensors | Temperature, Humidity, Voltage |
| **Custom** | Custom metric types | Application-specific metrics |

---

## 🗺️ **Metric Map Configuration**

### **Purpose:**
Maps API response fields to LibreNMS metric names using JSONPath notation.

### **Format:**
```json
{
  "metric_name": "$.path.to.value",
  "another_metric": "$.different.path"
}
```

### **Example 1: Simple Metrics**
**API Response:**
```json
{
  "cpu": {
    "usage": 45.2
  },
  "memory": {
    "used": 8589934592
  }
}
```

**Metric Map:**
```json
{
  "cpu_usage": "$.cpu.usage",
  "memory_used": "$.memory.used"
}
```

**Result:**
- `cpu_usage` = 45.2
- `memory_used` = 8589934592

### **Example 2: Nested Response with Response Path**
**API Response:**
```json
{
  "status": "ok",
  "data": {
    "items": [
      {
        "name": "vol1",
        "used_bytes": 1073741824,
        "total_bytes": 10737418240
      }
    ]
  }
}
```

**Response Path:** `$.data.items`  
**Metric Map:**
```json
{
  "storage_used": "$.used_bytes",
  "storage_total": "$.total_bytes",
  "volume_name": "$.name"
}
```

**Result:**
- Navigates to `$.data.items` first
- Then maps each item's fields to metrics

### **Example 3: PureStorage FlashArray**
**API Response:**
```json
{
  "items": [
    {
      "name": "controller0",
      "temperature": 32,
      "status": "ok"
    }
  ]
}
```

**Response Path:** `$.items`  
**Metric Map:**
```json
{
  "controller_temp": "$.temperature",
  "controller_status": "$.status",
  "controller_name": "$.name"
}
```

---

## 🔧 **Endpoint Form Fields**

### **Basic Configuration**
```
┌─────────────────────────────────────┐
│ Endpoint Name *: [Controllers]      │
│ Path *: [/api/2.26/controllers]     │
│                                     │
│ HTTP Method: [GET ▼]                │
│ Resource Type *: [Sensor ▼]         │ ← NEW!
│ Poll Interval: [300] seconds        │
└─────────────────────────────────────┘
```

### **Metric Mapping Section**
```
╔══════════════════════════════════════════╗
║  📊 Metric Mapping                       ║
╠══════════════════════════════════════════╣
║  ℹ️ Map API response fields to metrics  ║
║                                          ║
║  Metric Map (JSON):                      ║
║  ┌────────────────────────────────────┐  ║
║  │ {                                  │  ║
║  │   "cpu_usage": "$.cpu.percent",    │  ║
║  │   "temp_celsius": "$.temp"         │  ║
║  │ }                                  │  ║
║  └────────────────────────────────────┘  ║
║                                          ║
║  Response Path (Optional):               ║
║  [$.data.items              ]            ║
╚══════════════════════════════════════════╝
```

---

## 💡 **Complete Example: PureStorage Controllers**

### **Endpoint Configuration:**
```
Name: Controller Stats
Path: /api/2.26/controllers
Method: GET
Resource Type: Sensor
Poll Interval: 300
```

### **API Response:**
```json
{
  "continuation_token": null,
  "total_item_count": 2,
  "items": [
    {
      "name": "CT0",
      "mode": "primary",
      "model": "FA-X70R2",
      "status": "ok",
      "temperature": 31,
      "version": "6.4.10"
    },
    {
      "name": "CT1",
      "mode": "secondary",
      "model": "FA-X70R2",
      "status": "ok",
      "temperature": 29,
      "version": "6.4.10"
    }
  ]
}
```

### **Configuration:**
**Response Path:**
```
$.items
```

**Metric Map:**
```json
{
  "controller_name": "$.name",
  "controller_mode": "$.mode",
  "controller_temp": "$.temperature",
  "controller_status": "$.status",
  "controller_version": "$.version"
}
```

### **Result:**
LibreNMS will create sensors for each controller:
- Controller CT0: temp=31, status=ok, mode=primary
- Controller CT1: temp=29, status=ok, mode=secondary

---

## 📊 **Field Comparison**

### **Old (Problematic):**
```json
// response_mapping field
// Often resulted in null values
{
  "metric_name": "value"
}
```

### **New (Working):**
```json
// metric_map field with JSONPath
{
  "metric_name": "$.path.to.value"
}

// Plus response_path for nested data
"$.data.items"
```

---

## 🎯 **Why This Fixes Null Metrics**

### **Problem:**
- Old `response_mapping` didn't support JSONPath
- Couldn't navigate nested responses
- No resource type meant improper categorization
- Metrics were always null when applied to devices

### **Solution:**
1. ✅ **Resource Type** - Tells LibreNMS what kind of metric this is
2. ✅ **Metric Map** - Uses JSONPath to extract values correctly
3. ✅ **Response Path** - Handles nested JSON structures
4. ✅ **Clear Format** - Proper key-value mapping

---

## 🔄 **Data Flow**

```
1. API Call
   GET /api/2.26/controllers
   ↓
2. Response Received
   {
     "items": [
       {"name": "CT0", "temperature": 31}
     ]
   }
   ↓
3. Apply Response Path
   Navigate to: $.items
   Result: [{"name": "CT0", "temperature": 31}]
   ↓
4. Apply Metric Map
   "controller_temp": "$.temperature"
   Extract: temperature = 31
   ↓
5. Store in LibreNMS
   Device: 10.199.1.10
   Resource Type: sensor
   Metric: controller_temp = 31
   ✅ Success! (Not null)
```

---

## 📝 **Form Fields Summary**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| **Endpoint Name** | Text | Yes | Descriptive name |
| **Path** | Text | Yes | API endpoint path |
| **HTTP Method** | Dropdown | Yes | GET, POST, PUT, etc. |
| **Resource Type** | Dropdown | Yes | device, port, sensor, etc. |
| **Poll Interval** | Number | No | Seconds (default: 300) |
| **Enabled** | Checkbox | No | Enable/disable endpoint |
| **Description** | Textarea | No | Notes about endpoint |
| **Metric Map** | JSON | No | JSONPath value mapping |
| **Response Path** | Text | No | Path to data array |

---

## ✅ **Migration Notes**

### **For Existing Templates:**
- Old `response_mapping` field is hidden but preserved
- Add `resource_type` to existing endpoints
- Add `metric_map` with JSONPath syntax
- Optionally add `response_path` for nested responses

### **For New Templates:**
- Always set `resource_type`
- Use `metric_map` instead of `response_mapping`
- Use JSONPath notation (starts with `$`)
- Test with Preview tab before applying

---

## 🚀 **Quick Start Examples**

### **Example 1: Device CPU**
```json
Resource Type: device
Metric Map:
{
  "cpu_percent": "$.cpu.usage",
  "cpu_cores": "$.cpu.cores"
}
```

### **Example 2: Storage Array**
```json
Resource Type: storage
Response Path: $.volumes
Metric Map:
{
  "volume_name": "$.name",
  "used_bytes": "$.space.total_physical",
  "provisioned_bytes": "$.space.total_provisioned"
}
```

### **Example 3: Network Port**
```json
Resource Type: port
Response Path: $.interfaces
Metric Map:
{
  "port_name": "$.name",
  "speed_bps": "$.speed",
  "status": "$.operational_status"
}
```

---

## 📄 **File Modified**

**Path**: `/resources/views/settings/rest-api/templates/partials/endpoint-form.blade.php`

### **Changes:**
1. ✅ Added **Resource Type** dropdown (required)
2. ✅ Added **Metric Map** section with examples
3. ✅ Added **Response Path** field for nested data
4. ✅ Enhanced UI with info cards and help text
5. ✅ Hidden old `response_mapping` (deprecated)
6. ✅ Better layout (3-column for method/type/interval)

---

## ✅ **Summary**

### **What's Fixed:**
- ❌ Metrics were null when applying templates
- ❌ No resource type categorization
- ❌ Couldn't handle nested JSON responses
- ❌ Poor value extraction

### **What's Working Now:**
- ✅ **Resource Type** - Proper categorization
- ✅ **Metric Map** - JSONPath extraction
- ✅ **Response Path** - Nested response support
- ✅ **Metrics populate correctly** when applied!

**Templates now properly map API responses to LibreNMS metrics!** 🎉
