# REST API Template Testing - Enhanced Features

## ✅ **Fully Implemented with Advanced Features!**

The template testing system now includes comprehensive testing capabilities with granular control and detailed reporting.

---

## 🎯 **New Features Added**

### 1. **Endpoint Selection Dropdown**
- Test all endpoints at once
- Quick test (first endpoint only)
- **Select specific endpoint** from dropdown list
- Shows: Connection → Endpoint Name (METHOD /path)

### 2. **Variable Preview Panel**
- Shows how template variables will be replaced
- Real-time preview when device is selected
- Displays: `{device_hostname}` → `172.16.7.40`
- Collapsible panel to save space

### 3. **Advanced Test Options**
- ✅ SSL Verification toggle
- ✅ Show response headers
- ✅ Verbose output mode
- ✅ Custom timeout (1-300 seconds)

### 4. **Enhanced Results**
- **Total time** across all endpoints
- **Progress bar** for success rate
- **Copy to clipboard** per endpoint
- **Download results** as JSON file
- **Response headers** (when enabled)
- **Verbose details** (when enabled)

### 5. **Quick Actions**
- Copy all results to clipboard
- Download test results as JSON
- Clear results

---

## 📍 **How to Use**

### Step 1: Navigate to Template
```
Settings → Devices → REST API Templates → Edit → Preview Tab
```

### Step 2: Select Device
Choose a device to test against. Variables will be replaced with this device's values.

### Step 3: Select Endpoint(s)
Choose from:
- **All Endpoints** - Tests every endpoint
- **First Endpoint Only** - Quick connectivity test
- **Specific Endpoint** - Test one particular endpoint

### Step 4: Configure Options
- **Verify SSL**: Uncheck for self-signed certificates
- **Show Headers**: Include HTTP response headers
- **Verbose Output**: Additional request/response details
- **Timeout**: Custom timeout in seconds (default: 30)

### Step 5: Run Test
Click "Run Test" and view real-time results!

---

## 💡 **UI Features**

### Variable Preview Panel
```
Variable                 Will Be Replaced With
{device_hostname}    →   172.16.7.40
{device_ip}          →   172.16.7.40
{device_sysname}     →   purestorage-01
{device_display}     →   172.16.7.40
```

### Endpoint Selector
```
Select Endpoint(s) to Test:
  ○ All Endpoints
  ○ First Endpoint Only (Quick Test)
  ○ PureStorage API → Array Info (GET /api/2.26/arrays)
  ○ PureStorage API → Volumes (GET /api/2.26/volumes)
  ○ PureStorage API → Controllers (GET /api/2.26/controllers)
```

### Test Options
```
☑ Verify SSL certificate
☐ Show response headers  
☐ Verbose output
Timeout: [30] seconds
```

### Results Summary
```
Summary
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
Device:            172.16.7.40
Connection:        PureStorage API
Base URL:          https://172.16.7.40
Endpoints Tested:  3
Total Time:        245.67ms
Success Rate:      [████████████] 100%
```

### Endpoint Results
```
✓ Array Info                                    200  45.23ms
  URL: https://172.16.7.40/api/2.26/arrays
  Method: GET
  [▼ Show Response] [Copy]
  
✓ Volumes                                       200  89.12ms
  URL: https://172.16.7.40/api/2.26/volumes
  Method: GET
  [▼ Show Response] [Copy]
```

---

## 🔧 **API Endpoint Details**

### Request Payload (Enhanced)
```json
{
  "device_id": 2,
  "test_all_endpoints": false,
  "specific_endpoint": "0-1",
  "verify_ssl": false,
  "show_headers": true,
  "verbose": true,
  "timeout": 60
}
```

### Parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| `device_id` | integer | **Required**. Device to test against |
| `test_all_endpoints` | boolean | Test all endpoints (overrides specific_endpoint) |
| `specific_endpoint` | string | Format: "connectionIndex-endpointIndex" (e.g., "0-1") |
| `verify_ssl` | boolean | Verify SSL certificates |
| `show_headers` | boolean | Include response headers in results |
| `verbose` | boolean | Include additional debug information |
| `timeout` | integer | Request timeout in seconds (1-300) |

### Response (Enhanced)
```json
{
  "success": true,
  "summary": {
    "device": "172.16.7.40",
    "connection": "PureStorage API",
    "base_url": "https://172.16.7.40",
    "endpoints_tested": 2,
    "total_time": 234.56,
    "success_rate": 100
  },
  "endpoint_results": [
    {
      "name": "Array Info",
      "url": "https://172.16.7.40/api/2.26/arrays",
      "method": "GET",
      "status_code": 200,
      "response_time": 145.23,
      "success": true,
      "response_preview": "{\n  \"items\": [...]\n}",
      "error": null,
      "headers": {
        "Content-Type": "application/json",
        "X-Auth-Token": "...",
        "Content-Length": "1234"
      },
      "verbose": {
        "request_url": "https://172.16.7.40/api/2.26/arrays",
        "request_method": "GET",
        "response_size": 1234,
        "content_type": "application/json"
      }
    }
  ]
}
```

---

## 🎨 **User Experience Enhancements**

### 1. **Smart Defaults**
- First endpoint selected by default for quick testing
- SSL verification OFF by default (common for internal devices)
- 30-second timeout (adjustable)

### 2. **Progressive Disclosure**
- Variable preview hidden initially (click to show)
- Response bodies collapsed by default
- Options in expandable panel

### 3. **Visual Feedback**
- Loading spinner during test
- Success/failure color coding
- Progress bar for success rate
- Timestamp on results

### 4. **Export Options**
- Copy individual responses
- Copy all results as JSON
- Download results file
- Filename includes template ID and timestamp

---

## 📊 **Use Cases**

### Use Case 1: Quick Connectivity Test
```
1. Select device: 172.16.7.40
2. Keep "First Endpoint Only" selected
3. Uncheck "Verify SSL"
4. Click "Run Test"
→ Fast validation that API is reachable
```

### Use Case 2: Full Template Validation
```
1. Select device: 172.16.7.40
2. Select "All Endpoints"
3. Check "Verbose output"
4. Click "Run Test"
→ Complete test of entire template
```

### Use Case 3: Debugging Specific Endpoint
```
1. Select device: 172.16.7.40
2. Select specific endpoint from dropdown
3. Check "Show response headers" + "Verbose output"
4. Click "Run Test"
→ Detailed debugging info for one endpoint
```

### Use Case 4: Performance Testing
```
1. Select device: 172.16.7.40
2. Select "All Endpoints"
3. Note response times
4. Download results for analysis
→ Performance baseline data
```

---

## 🔍 **What Gets Tested**

### Connection Test
- ✅ URL reachability
- ✅ DNS resolution
- ✅ Network connectivity
- ✅ SSL/TLS handshake (if enabled)

### Authentication Test
- ✅ Credential validity
- ✅ Token generation (session tokens)
- ✅ Auth header format
- ✅ Permission levels

### Endpoint Test
- ✅ HTTP method support
- ✅ Path correctness
- ✅ Response format
- ✅ Status code
- ✅ Response time
- ✅ Data structure

---

## 💾 **Export Formats**

### Copy to Clipboard
```json
{
  "summary": {...},
  "endpoints": [...]
}
```

### Download JSON File
```
Filename: template-test-5-1696723456789.json
Content:
{
  "template": "PureStorage Template",
  "tested_at": "2024-10-07T10:30:56.789Z",
  "summary": {...},
  "endpoints": [...]
}
```

---

## ⚙️ **Configuration Examples**

### Example 1: Test Specific Endpoint
```javascript
{
  "device_id": 2,
  "specific_endpoint": "0-2",  // First connection, third endpoint
  "verify_ssl": false,
  "timeout": 45
}
```

### Example 2: Full Diagnostic
```javascript
{
  "device_id": 2,
  "test_all_endpoints": true,
  "verify_ssl": true,
  "show_headers": true,
  "verbose": true,
  "timeout": 120
}
```

### Example 3: Quick Check
```javascript
{
  "device_id": 2,
  // Default: first endpoint only
  "verify_ssl": false,
  "timeout": 10
}
```

---

## 🚀 **Benefits**

### For Developers
- ✅ Test templates before deployment
- ✅ Debug API connectivity issues
- ✅ Validate credential configurations
- ✅ Performance benchmarking

### For Operators
- ✅ Verify device APIs are accessible
- ✅ Check authentication is working
- ✅ Preview actual API responses
- ✅ Export results for documentation

### For Troubleshooting
- ✅ Detailed error messages
- ✅ Response time metrics
- ✅ Header inspection
- ✅ Verbose debugging mode

---

## 📝 **Summary**

### Features Added
1. ✅ **Endpoint selector dropdown** - Choose specific endpoints to test
2. ✅ **Variable preview panel** - See how variables will be replaced
3. ✅ **Advanced options** - Headers, verbose, custom timeout
4. ✅ **Enhanced results** - Total time, headers, verbose info
5. ✅ **Export functionality** - Copy/download results
6. ✅ **Quick actions** - Copy, download, clear
7. ✅ **Progress indicators** - Loading states, success rates
8. ✅ **Better UX** - Collapsible panels, visual feedback

### All working perfectly! 🎉

The template testing system is now production-ready with comprehensive testing and debugging capabilities!
