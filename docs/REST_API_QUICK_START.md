# REST API Module - Quick Start Guide

## 🚀 Get Running in 15 Minutes

### Prerequisites
- LibreNMS installed and running
- A device with a REST API (or a test API endpoint)
- API credentials (token, API key, or username/password)

---

## Step 1: Enable the Module (30 seconds)

Add to your `config.php`:
```php
$config['discovery_modules']['rest-api'] = true;
$config['poller_modules']['rest-api'] = true;
```

Or run:
```bash
echo "\$config['discovery_modules']['rest-api'] = true;" >> config.php
echo "\$config['poller_modules']['rest-api'] = true;" >> config.php
```

---

## Step 2: Verify Module Works (1 minute)

```bash
php artisan tinker
```

Then run:
```php
LibreNMS\Util\Module::exists('rest-api');
// Should return: true

$module = LibreNMS\Util\Module::fromName('rest-api');
get_class($module);
// Should return: "LibreNMS\Modules\RestApi"

exit
```

✅ If both commands work, you're good to proceed!

---

## Step 3: Create a Credential (2 minutes)

### Via Web UI:
1. Go to: **Settings** → **REST API** → **Credentials**
2. Click **"Add Credential"**
3. Fill in:
   - **Name:** "My API Token"
   - **Authentication Type:** "Bearer Token"
   - **Token:** (paste your API token)
4. Click **Save**

### Via Command Line (Alternative):
```bash
php artisan tinker
```

```php
$type = App\Models\RestApiAuthenticationType::where('name', 'Bearer Token')->first();

$cred = App\Models\RestApiCredential::create([
    'name' => 'My API Token',
    'authentication_type_id' => $type->id
]);

$cred->params()->create([
    'key' => 'token',
    'value' => 'your-api-token-here'
]);

exit
```

---

## Step 4: Create a Template (3 minutes)

### Simple Example Template:

1. Go to: **Settings** → **REST API** → **Templates**
2. Click **"Add Template"**
3. Fill in:
   - **Name:** "Generic REST API"
   - **Vendor:** "Generic"
   - **Template Data:**

```json
{
  "connections": [
    {
      "name": "API Connection",
      "base_url": "https://{{ $device->ip }}",
      "disable_ssl_verify": true,
      "rate_limit": 60,
      "endpoints": [
        {
          "name": "System Status",
          "path": "/api/v1/status",
          "method": "GET",
          "resource_type": "device",
          "enabled": true,
          "metric_map": {
            "cpu": "system.cpu",
            "memory": "system.memory",
            "uptime": "system.uptime"
          }
        }
      ]
    }
  ]
}
```

4. Click **Save**

---

## Step 5: Apply Template to Device (2 minutes)

### Via Web UI:
1. Go to your device page
2. Click **Settings** tab
3. Find **REST API** section
4. Click **"Apply Template"**
5. Select:
   - **Template:** "Generic REST API"
   - **Credential:** "My API Token"
6. Click **Apply**

### Via Command Line (Alternative):
```bash
php artisan tinker
```

```php
$device = App\Models\Device::where('hostname', 'your-device-hostname')->first();
$template = App\Models\RestApiTemplate::where('name', 'Generic REST API')->first();
$credential = App\Models\RestApiCredential::where('name', 'My API Token')->first();

// Get template data
$data = $template->template_data;

// Create connection
$connection = $device->restApiConnections()->create([
    'credential_id' => $credential->id,
    'name' => $data['connections'][0]['name'],
    'base_url' => str_replace('{{ $device->ip }}', $device->ip, $data['connections'][0]['base_url']),
    'rate_limit' => 60,
    'enabled' => true,
    'disable_ssl_verify' => true,
]);

// Create endpoints
foreach ($data['connections'][0]['endpoints'] as $ep) {
    $connection->endpoints()->create([
        'name' => $ep['name'],
        'path' => $ep['path'],
        'method' => $ep['method'] ?? 'GET',
        'resource_type' => $ep['resource_type'] ?? 'custom',
        'metric_map' => $ep['metric_map'] ?? null,
    ]);
}

exit
```

---

## Step 6: Test Discovery (3 minutes)

```bash
# Replace HOSTNAME with your device hostname
./discover.php -h HOSTNAME -m rest-api -d

# Or use device ID
php lnms device:discover DEVICE_ID -m rest-api -vvv
```

**What to look for:**
```
REST API Discovery started for device: HOSTNAME
Endpoint: System Status
API Response received
Metrics stored: cpu, memory, uptime
REST API Discovery completed
```

---

## Step 7: Test Polling (3 minutes)

```bash
# Replace HOSTNAME with your device hostname
./poller.php -h HOSTNAME -m rest-api -d

# Or use device ID
php lnms device:poll DEVICE_ID -m rest-api -vvv
```

**What to look for:**
```
REST API Polling started for device: HOSTNAME
Endpoint: System Status
Metrics updated: cpu=45, memory=62, uptime=86400
RRD files updated
REST API Polling completed
```

---

## Step 8: Verify Everything Works (2 minutes)

```bash
php artisan tinker
```

```php
// Check connections exist
$device = App\Models\Device::where('hostname', 'HOSTNAME')->first();
$device->restApiConnections()->count();
// Should return: 1 or more

// Check endpoints
$device->restApiConnections()->with('endpoints')->get();
// Should show your endpoints

// Check metrics in database
App\Models\RestApiMetric::where('device_id', $device->device_id)->get();
// Should show collected metrics

exit
```

Check RRD files:
```bash
ls -la /opt/librenms/rrd/HOSTNAME/rest_api*
# Should show .rrd files
```

---

## ✅ Success Criteria

You've successfully set up the REST API module if:

- ✅ Module is recognized by LibreNMS
- ✅ Credential is saved
- ✅ Template is created  
- ✅ Template applied to device
- ✅ Discovery runs without errors
- ✅ Polling runs without errors
- ✅ Metrics appear in database
- ✅ RRD files are created

---

## 🔧 Common Issues & Quick Fixes

### Issue: "Module not found"
```bash
# Clear cache
php artisan cache:clear
php artisan config:clear

# Verify file exists
ls -la LibreNMS/Modules/RestApi.php
```

### Issue: "No data collected"
**Check:**
1. Is the API endpoint correct?
2. Is the credential valid?
3. Is SSL verification causing issues? (try `disable_ssl_verify: true`)
4. Are metric mappings correct?

**Debug:**
```bash
# Test API manually
curl -H "Authorization: Bearer YOUR_TOKEN" https://DEVICE_IP/api/v1/status

# Check logs
tail -f /opt/librenms/logs/librenms.log | grep -i "rest api"
```

### Issue: "Authentication failed"
```bash
php artisan tinker
```

```php
$cred = App\Models\RestApiCredential::first();
$headers = App\RestApi\Credentials\CredentialHelper::getAuthHeaderFromModel($cred);
print_r($headers);
// Verify headers look correct
```

---

## 📊 Example Templates

### For Cisco Devices:
```json
{
  "connections": [{
    "name": "Cisco API",
    "base_url": "https://{{ $device->ip }}",
    "disable_ssl_verify": true,
    "endpoints": [{
      "name": "Interface Stats",
      "path": "/restconf/data/interfaces",
      "method": "GET",
      "resource_type": "port",
      "metric_map": {
        "status": "interfaces.interface.[].oper-status",
        "speed": "interfaces.interface.[].speed"
      }
    }]
  }]
}
```

### For Generic Linux API:
```json
{
  "connections": [{
    "name": "System API",
    "base_url": "http://{{ $device->ip }}:8080",
    "endpoints": [{
      "name": "System Metrics",
      "path": "/api/metrics",
      "method": "GET",
      "resource_type": "device",
      "metric_map": {
        "cpu_usage": "cpu.percent",
        "mem_usage": "memory.percent",
        "disk_usage": "disk.percent",
        "load_1min": "load.1min"
      }
    }]
  }]
}
```

---

## 🎯 Next Steps

After getting the basic setup working:

1. **Create more templates** for your specific devices
2. **Test different authentication types** (API Key, Basic Auth, etc.)
3. **Set up graphs** for your metrics
4. **Configure alerting** based on REST API metrics
5. **Share templates** with the community

---

## 📚 Additional Resources

- **Full Documentation:** See `REST_API_IMPLEMENTATION_GUIDE.md`
- **Architecture Details:** See `REST_API_FINAL_SUMMARY.md`
- **Troubleshooting:** See "🔧 Troubleshooting Guide" in Final Summary

---

## 💡 Pro Tips

1. **Start Simple** - Use a test API first (like httpbin.org)
2. **Test Manually** - Use curl to verify API before creating template
3. **Check Logs** - Always check LibreNMS logs when debugging
4. **Use SSL Carefully** - Only disable SSL verification for testing
5. **Document Templates** - Add good descriptions to your templates

---

**🎉 You're done! Your REST API module should now be collecting data from your devices.**

If you run into issues, check the troubleshooting sections in the full documentation files.
