# REST API Template Testing - Enhanced Features

## ✅ Complete Implementation with Advanced Features!

The template testing screen now includes comprehensive testing capabilities with endpoint selection, variable preview, and advanced options.

---

## 🎯 New Features Added

### 1. **Endpoint Selection Dropdown** ⭐
- **All Endpoints**: Test every endpoint in the template
- **First Endpoint Only**: Quick test (default)
- **Specific Endpoint**: Select individual endpoints from dropdown

### 2. **Variable Preview Panel**
- Shows all template variables and their resolved values
- Updates automatically when device is selected
- Collapsible panel to save space

### 3. **Advanced Test Options**
- ✅ SSL verification toggle
- ✅ Show response headers option
- ✅ Verbose output mode
- ✅ Configurable timeout (1-300 seconds)

### 4. **Quick Actions**
- ✅ Copy results to clipboard
- ✅ Download results as JSON
- ✅ Clear results

### 5. **Enhanced Results Display**
- Total time metric
- Progress bar for success rate
- Timestamp of test
- Copy individual responses
- Collapsible response previews

---

## 📍 UI Components

### Device Selection
```
┌─────────────────────────────────────────┐
│ Select Device to Test *                 │
│ ┌─────────────────────────────────────┐ │
│ │ 172.16.7.40 (172.16.7.40) - array1  │ │
│ └─────────────────────────────────────┘ │
│ Variables will be replaced with this    │
│ device's values                          │
└─────────────────────────────────────────┘
```

### Endpoint Selection
```
┌─────────────────────────────────────────┐
│ Select Endpoint(s) to Test              │
│ ┌─────────────────────────────────────┐ │
│ │ ▼ First Endpoint Only (Quick Test)  │ │
│ │   All Endpoints                      │ │
│ │   Primary → Array Info (GET /api...) │ │
│ │   Primary → Volumes (GET /api/2.2...) │ │
│ └─────────────────────────────────────┘ │
└─────────────────────────────────────────┘
```

### Variable Preview
```
┌─────────────────────────────────────────┐
│ 👁 Variable Preview          [Hide ▲]   │
├─────────────────────────────────────────┤
│ Variable              Will Be Replaced  │
│ {device_hostname}  →  172.16.7.40      │
│ {device_ip}        →  172.16.7.40      │
│ {device_sysname}   →  pure-array-01    │
│ {device_display}   →  Pure Storage Array│
└─────────────────────────────────────────┘
```

### Test Options
```
┌─────────────────────────────────────────┐
│ ⚙ Test Options                          │
├─────────────────────────────────────────┤
│ ☐ Verify SSL certificate                │
│   Uncheck for self-signed certificates  │
│                                          │
│ ☐ Show response headers                 │
│   Include HTTP headers in results       │
│                                          │
│ ☐ Verbose output                        │
│   Show detailed request/response info   │
│                                          │
│ Timeout: [30] seconds                   │
└─────────────────────────────────────────┘
```

### Results Summary
```
┌─────────────────────────────────────────┐
│ ✓ Test Successful  Tested: 10:30:45 AM │
├─────────────────────────────────────────┤
│ Device:          172.16.7.40            │
│ Connection:      Primary Connection     │
│ Base URL:        https://172.16.7.40    │
│ Endpoints:       3                      │
│ Total Time:      456.32ms               │
│ Success Rate:    ████████████ 100%      │
└─────────────────────────────────────────┘
```

---

## 🚀 How to Use

### Basic Test (Quick)
1. Select device from dropdown
2. Leave endpoint selection on "First Endpoint Only"
3. Click "Run Test"
4. View results

### Test Specific Endpoint
1. Select device
2. Choose specific endpoint from dropdown
   - Example: "Primary Connection → Volumes (GET /api/2.26/volumes)"
3. Click "Run Test"
4. View results for that endpoint only

### Test All Endpoints
1. Select device
2. Select "All Endpoints" from dropdown
3. Optionally enable verbose output or headers
4. Click "Run Test"
5. View results for all endpoints

### Advanced Testing
1. Select device
2. Choose endpoint(s)
3. Configure options:
   - ☑ Verify SSL (if using valid cert)
   - ☑ Show response headers (to debug)
   - ☑ Verbose output (detailed info)
   - Set custom timeout (e.g., 60 seconds)
4. Click "Run Test"
5. Review detailed results

---

## 📊 What Gets Tested

### For Each Endpoint:
1. **Variable Replacement**: All `{device_*}` variables replaced
2. **URL Construction**: Base URL + endpoint path
3. **Authentication**: Applies credential if configured
   - Basic Auth
   - Token Auth
   - Session Token (automatic login)
4. **HTTP Request**: Makes actual API call
5. **Response Capture**: Records:
   - Status code
   - Response time
   - Response body
   - Headers (if enabled)
   - Errors

---

## 📥 Export Options

### Copy to Clipboard
- Copies full test results as formatted JSON
- Includes all endpoints and responses
- Useful for sharing results

### Download JSON
- Downloads complete test report
- Filename: `template-test-{id}-{timestamp}.json`
- Includes:
  - Template name
  - Test timestamp
  - Summary statistics
  - All endpoint results
  - Full responses

**Downloaded JSON Structure**:
```json
{
  "template": "PureStorage Template",
  "tested_at": "2025-10-04T10:30:45.123Z",
  "summary": {
    "device": "172.16.7.40",
    "connection": "Primary Connection",
    "base_url": "https://172.16.7.40",
    "endpoints_tested": 3,
    "total_time": 456.32,
    "success_rate": 100
  },
  "endpoints": [
    {
      "name": "Array Info",
      "url": "https://172.16.7.40/api/2.26/arrays",
      "method": "GET",
      "status_code": 200,
      "response_time": 145.23,
      "success": true,
      "response_preview": "{...}",
      "headers": {...},
      "verbose": {...}
    }
  ]
}
```

---

## 🔍 Understanding Results

### Success Indicators
- ✅ **Green check**: HTTP 2xx response
- ❌ **Red X**: HTTP error or exception
- **Status code badge**: Shows HTTP status
- **Response time**: Milliseconds to complete

### Response Preview
- Automatically formatted JSON
- Syntax highlighted
- Collapsible (click to expand/collapse)
- Copy button for each response
- Truncated at 5000 characters (shows "...truncated")

### Error Display
- Shows full error message
- Includes response body if available (up to 500 chars)
- Network errors clearly indicated

---

## 💡 Use Cases

### 1. Template Validation
**Before applying to production devices**:
- Test template on a single device
- Verify all endpoints work
- Check authentication
- Review response format

### 2. Troubleshooting
**When a template isn't working**:
- Select the failing device
- Test specific endpoint
- Enable verbose output
- Check error messages
- Verify SSL settings

### 3. API Exploration
**Learning a new API**:
- Create template with sample endpoints
- Test against device
- View response structure
- Copy responses for analysis
- Download JSON for documentation

### 4. Performance Testing
**Check API response times**:
- Test all endpoints
- Review total time
- Identify slow endpoints
- Adjust timeout if needed

### 5. Credential Validation
**Verify authentication works**:
- Test with different credentials
- Check session token login
- Verify headers are correct
- Debug auth failures

---

## 🎨 Visual Enhancements

### Progress Bar
```
Success Rate: ████████████ 100%
              ██████░░░░░░  50%
              ░░░░░░░░░░░░   0%
```
- Green: 100% success
- Yellow: Partial success
- Shows exact percentage

### Status Badges
```
✓ 200  (Green badge)
✗ 404  (Red badge)
  145ms (Blue badge - response time)
```

### Collapsible Sections
- Variable preview (▼/▲)
- Response bodies (▼/▲)
- Smooth animations

---

## ⚙️ Advanced Options Explained

### Verify SSL Certificate
- **Checked**: Validates SSL cert (production)
- **Unchecked**: Ignores cert errors (self-signed)
- Default: Unchecked (most test environments use self-signed)

### Show Response Headers
- Includes all HTTP response headers
- Useful for debugging:
  - Content-Type
  - Cache headers
  - Custom headers
  - Auth tokens in response

### Verbose Output
Adds extra information:
- Request URL (full)
- Request method
- Response size (bytes)
- Content-Type header
- Additional metadata

### Timeout
- Default: 30 seconds
- Range: 1-300 seconds
- Use longer timeout for:
  - Slow APIs
  - Complex queries
  - Large datasets

---

## 🐛 Troubleshooting

### "No endpoints to test"
- Template has no endpoints defined
- Add endpoints in template configuration

### "Authentication failed" (401/403)
- Check credential configuration
- Verify API token is valid
- For session token: check login endpoint

### "Connection timeout"
- Increase timeout setting
- Check network connectivity
- Verify base URL is correct

### "SSL error"
- Uncheck "Verify SSL certificate"
- Or install proper SSL cert on device

### Variables not replaced
- Check device has required fields
- Verify variable syntax: `{device_hostname}`
- View Variable Preview panel

---

## ✅ Best Practices

### Before Testing
1. ✅ Verify device is reachable
2. ✅ Check credentials are configured
3. ✅ Review template endpoint paths
4. ✅ Start with "First Endpoint Only"

### During Testing
1. ✅ Use Variable Preview to verify replacements
2. ✅ Test one endpoint first, then all
3. ✅ Enable verbose for debugging
4. ✅ Disable SSL verify for self-signed certs

### After Testing
1. ✅ Review all endpoint results
2. ✅ Check response formats match expectations
3. ✅ Download results for documentation
4. ✅ Fix any failing endpoints before applying

---

## 📋 Quick Reference

| Feature | Button/Location | Purpose |
|---------|----------------|---------|
| Device Selector | Top dropdown | Choose device for testing |
| Endpoint Selector | Second dropdown | Choose which endpoints to test |
| Variable Preview | Collapsible panel | See variable replacements |
| Test Options | Card with checkboxes | Configure test behavior |
| Run Test | Large blue button | Execute test |
| Copy Results | After test | Copy JSON to clipboard |
| Download | After test | Save results as file |
| Clear | After test | Remove results |
| Show Response | Per endpoint | View API response |
| Copy Response | Per endpoint | Copy single response |

---

## 🎯 Summary

The enhanced template testing screen provides:

✅ **Flexible endpoint selection** - Test all, first, or specific endpoints  
✅ **Variable preview** - See exactly what will be replaced  
✅ **Advanced options** - SSL, headers, verbose, timeout  
✅ **Export capabilities** - Copy or download results  
✅ **Detailed results** - Status, timing, responses, errors  
✅ **Great UX** - Collapsible sections, animations, badges  

**Perfect for validating templates before applying them to devices!** 🚀
