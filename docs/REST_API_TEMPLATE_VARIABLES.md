# REST API Template Variable Replacement

## ✅ Variable Replacement IS Implemented!

When a template is applied to a device, all placeholder variables are automatically replaced with actual device values. The variables are **NOT** stored in the device configuration - only the resolved values are saved.

---

## 🔄 How It Works

When you apply a template to a device:

1. Template is loaded with placeholder variables
2. `applyTemplate()` method calls `replacePlaceholdersInArray()`
3. All placeholders are replaced with actual device values
4. **Only the resolved values** are stored in the database
5. Device-specific configuration is created with real values

**Example:**
```
Template: https://{device_hostname}/api/
Device hostname: 172.16.7.40
Result stored: https://172.16.7.40/api/
```

---

## 📝 Supported Variable Formats

### Format 1: Laravel Blade Style (Original)

```
{{ $device->hostname }}      → Device hostname
{{ $device->ip }}            → Device IP address
{{ $device->sysName }}       → System name
{{ $device->display }}       → Display name
{{ $device->getAttrib('name') }} → Custom attribute
```

### Format 2: Simple Curly Braces (NEW - Added)

```
{device_hostname}            → Device hostname
{device_ip}                  → Device IP address
{device_sysname}             → System name
{device_display}             → Display name
{device_attrib:name}         → Custom attribute
```

**Both formats work!** Use whichever is easier for your templates.

---

## 🎯 Available Variables

### Standard Device Fields

| Variable | Blade Style | Simple Style | Value |
|----------|-------------|--------------|-------|
| Hostname | `{{ $device->hostname }}` | `{device_hostname}` | Device hostname/FQDN |
| IP Address | `{{ $device->ip }}` | `{device_ip}` | Device IP address |
| System Name | `{{ $device->sysName }}` | `{device_sysname}` | SNMP sysName |
| Display Name | `{{ $device->display }}` | `{device_display}` | Custom display name |

### Custom Attributes

| Variable | Blade Style | Simple Style |
|----------|-------------|--------------|
| Any Attribute | `{{ $device->getAttrib('api_key') }}` | `{device_attrib:api_key}` |

---

## 💡 Example Templates

### Example 1: PureStorage with Hostname

**Template JSON:**
```json
{
  "connections": [
    {
      "name": "Primary Connection",
      "base_url": "https://{device_hostname}",
      "credential_id": 1,
      "endpoints": [
        {
          "name": "Array Info",
          "path": "/api/2.26/arrays",
          "method": "GET"
        }
      ]
    }
  ]
}
```

**Applied to device `172.16.7.40`:**
```json
{
  "connections": [
    {
      "name": "Primary Connection",
      "base_url": "https://172.16.7.40",
      "credential_id": 1,
      "endpoints": [
        {
          "name": "Array Info",
          "path": "/api/2.26/arrays",
          "method": "GET"
        }
      ]
    }
  ]
}
```

### Example 2: Using IP Address

**Template:**
```json
{
  "connections": [
    {
      "name": "Management API",
      "base_url": "https://{{ $device->ip }}:8443",
      "endpoints": [...]
    }
  ]
}
```

**Applied to device with IP `10.1.1.100`:**
```json
{
  "connections": [
    {
      "name": "Management API", 
      "base_url": "https://10.1.1.100:8443",
      "endpoints": [...]
    }
  ]
}
```

### Example 3: Using Custom Attributes

First, set a custom attribute on the device:
```php
$device->setAttrib('storage_array_name', 'PURE-PROD-01');
```

**Template:**
```json
{
  "connections": [
    {
      "name": "Connection to {device_attrib:storage_array_name}",
      "base_url": "https://{device_ip}",
      "endpoints": [
        {
          "name": "Array {device_attrib:storage_array_name} Status",
          "path": "/api/2.26/arrays"
        }
      ]
    }
  ]
}
```

**Applied:**
```json
{
  "connections": [
    {
      "name": "Connection to PURE-PROD-01",
      "base_url": "https://10.1.1.100",
      "endpoints": [
        {
          "name": "Array PURE-PROD-01 Status",
          "path": "/api/2.26/arrays"
        }
      ]
    }
  ]
}
```

---

## 🔧 How to Use in Templates

### Step 1: Create Template with Placeholders

When creating a global template, use placeholders instead of hardcoded values:

```json
{
  "connections": [
    {
      "name": "Primary Connection",
      "base_url": "https://{device_hostname}",
      "rate_limit": 60,
      "enabled": true,
      "disable_ssl_verify": true,
      "credential_id": 1,
      "endpoints": [
        {
          "name": "Device Info",
          "path": "/api/info",
          "method": "GET",
          "resource_type": "device",
          "resource_id_path": "id",
          "resource_name_path": "name",
          "metric_map": {
            "status": "status",
            "version": "version"
          }
        }
      ]
    }
  ]
}
```

### Step 2: Apply Template to Device

1. Go to device edit page
2. Navigate to REST API tab
3. Click "Apply Template"
4. Select your template
5. Click "Apply"

### Step 3: Verify Replacement

The connection will be created with the actual device values:
- `{device_hostname}` → `172.16.7.40`
- `{device_ip}` → `172.16.7.40`
- etc.

---

## 🧪 Testing Variable Replacement

### Test in Tinker

```bash
php artisan tinker
```

```php
$device = \App\Models\Device::find(2);
$template = \App\Models\RestApiTemplate::find(1);

// Simulate the replacement
$controller = new \App\Http\Controllers\Device\RestApiController();
$reflection = new ReflectionClass($controller);

$method = $reflection->getMethod('replacePlaceholdersInString');
$method->setAccessible(true);

$test = "https://{device_hostname}/api with IP {device_ip}";
$result = $method->invoke($controller, $test, $device);

echo "Original: $test\n";
echo "Replaced: $result\n";
```

### Test All Formats

```php
$tests = [
    'Blade hostname: {{ $device->hostname }}',
    'Simple hostname: {device_hostname}',
    'Blade IP: {{ $device->ip }}',
    'Simple IP: {device_ip}',
    'Custom attrib: {device_attrib:api_key}',
];

foreach ($tests as $test) {
    echo $method->invoke($controller, $test, $device) . "\n";
}
```

---

## 📊 Database Storage

**IMPORTANT**: Only the **resolved values** are stored in the database.

### Before Application (Template)
```sql
SELECT * FROM rest_api_templates WHERE id = 1;
-- template_data contains: {"connections": [{"base_url": "{device_hostname}"}]}
```

### After Application (Device Connection)
```sql
SELECT * FROM rest_api_connections WHERE device_id = 2;
-- base_url contains: "https://172.16.7.40" (actual value, not variable)
```

**The variable is NOT stored in the device configuration!**

---

## ⚠️ Important Notes

### 1. Variables are Replaced ONCE
- Variables are replaced when template is applied
- After application, the connection stores the actual value
- Re-applying the template will create a NEW connection with current values

### 2. Null/Empty Values
- If a variable value is null, it's replaced with empty string
- Example: `{device_sysname}` → `""` if sysName is null
- Use `{{ $device->display }}` as fallback (uses hostname if display is null)

### 3. Custom Attributes Must Exist
- For `{device_attrib:name}`, the attribute must be set on the device
- If attribute doesn't exist, replaced with empty string
- Set attributes before applying template:
  ```php
  $device->setAttrib('api_key', 'abc123');
  ```

### 4. Case Sensitivity
- Simple format is case-insensitive: `{device_hostname}` or `{DEVICE_HOSTNAME}`
- Blade format is case-sensitive: must be `{{ $device->hostname }}`

---

## 🔍 Verification Checklist

After applying a template, verify variables were replaced:

- [ ] Check connection `base_url` - should show actual hostname/IP, not `{device_hostname}`
- [ ] Check endpoint paths - should have resolved values
- [ ] Check connection names - should show actual device info
- [ ] Test API connection - should work with resolved URLs
- [ ] Check database - `rest_api_connections.base_url` should have actual value

---

## 🐛 Troubleshooting

### Variables Not Being Replaced

**Check 1: Template Format**
```bash
# View template data
php artisan tinker
>>> $template = \App\Models\RestApiTemplate::find(1);
>>> print_r($template->template_data);
```

Make sure variables use correct format: `{device_hostname}` or `{{ $device->hostname }}`

**Check 2: Apply Template Method**
```bash
# Check if applyTemplate is being called
tail -f storage/logs/laravel.log | grep -i template
```

**Check 3: Device Values Exist**
```php
$device = \App\Models\Device::find(2);
echo "Hostname: " . $device->hostname . "\n";
echo "IP: " . $device->ip . "\n";
echo "SysName: " . $device->sysName . "\n";
```

### Partial Replacement

If some variables work but others don't:
1. Check variable format matches exactly
2. Verify device field has a value
3. Check for typos in variable name

---

## ✅ Summary

**YES, variable replacement is fully implemented!**

✅ Variables are replaced when template is applied  
✅ Only actual values are stored in device configuration  
✅ Multiple variable formats supported  
✅ Custom attributes supported  
✅ Null values handled safely  

**Supported Variables:**
- `{device_hostname}` or `{{ $device->hostname }}`
- `{device_ip}` or `{{ $device->ip }}`
- `{device_sysname}` or `{{ $device->sysName }}`
- `{device_display}` or `{{ $device->display }}`
- `{device_attrib:name}` or `{{ $device->getAttrib('name') }}`

**All working as expected!** 🎉
