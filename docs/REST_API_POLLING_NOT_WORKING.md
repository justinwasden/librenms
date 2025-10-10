# REST API Polling Not Working - Troubleshooting Guide

## 🐛 Issue

REST API polling shows as running (0.1861 seconds) but no metrics are being collected for devices with configured connections and credentials.

---

## 🔍 Diagnostic Steps

### Step 1: Run the Diagnostic Script

I've created a comprehensive diagnostic script:

```bash
cd /opt/librenms
chmod +x diagnostic_rest_api_polling.php
./diagnostic_rest_api_polling.php 2  # Replace 2 with your device ID
```

This will show you:
- ✅ Device status
- ✅ Connection configuration  
- ✅ Endpoint configuration
- ✅ Metric map status
- ✅ shouldPoll logic check
- ✅ Live API test with detailed logs

---

## ⚠️ Common Issues

### Issue 1: Endpoints Have No Metric Map

**Symptom**: Endpoints are configured but `metric_map` is empty or null

**Check**:
```sql
SELECT id, name, metric_map 
FROM rest_api_endpoints 
WHERE connection_id IN (
    SELECT id FROM rest_api_connections WHERE device_id = 2
);
```

**Fix**: Each endpoint MUST have a `metric_map` configured that maps API response paths to metric names.

**Example metric_map for PureStorage array endpoint**:
```json
{
  "capacity": "capacity",
  "total": "space.total_physical",
  "space.available": "space.available",
  "space.data_reduction": "space.data_reduction",
  "name": "name"
}
```

---

### Issue 2: API Response Format Doesn't Match Expected Format

The poller expects responses in specific formats:

**Format 1: Items array (PureStorage style)**
```json
{
  "items": [
    { "name": "array1", "capacity": 1000000 },
    { "name": "volume1", "size": 50000 }
  ]
}
```

**Format 2: Direct array**
```json
[
  { "name": "item1", "value": 100 },
  { "name": "item2", "value": 200 }
]
```

**Format 3: Single object**
```json
{
  "name": "array1",
  "capacity": 1000000,
  "used": 500000
}
```

**Check actual API response**:
```bash
# Enable debug logging
php lnms device:poll 2 -m rest-api -vvv 2>&1 | grep -A 20 "API Response"
```

---

### Issue 3: Resource ID/Name Paths Not Configured

The poller needs to know where to find resource identifiers in the API response.

**Check**:
```sql
SELECT 
    name, 
    resource_id_path, 
    resource_name_path, 
    resource_type 
FROM rest_api_endpoints 
WHERE connection_id IN (
    SELECT id FROM rest_api_connections WHERE device_id = 2
);
```

**Required**:
- `resource_id_path`: JSON path to unique ID (e.g., `id`, `uuid`, `name`)
- `resource_name_path`: JSON path to display name (e.g., `name`, `hostname`)
- `resource_type`: Category (e.g., `array`, `volume`, `host`)

**Example for PureStorage**:
- Array endpoint: `resource_id_path = "name"`, `resource_name_path = "name"`
- Volume endpoint: `resource_id_path = "name"`, `resource_name_path = "name"`

---

### Issue 4: Authentication Failing

**Check logs for auth errors**:
```bash
tail -f /opt/librenms/storage/logs/laravel.log | grep -i "auth\|401\|403\|token"
```

**Common auth issues**:
- Session token not being obtained
- API token header name incorrect
- Token expired
- Credentials incorrect

**For PureStorage Session Token auth**, verify:
```sql
SELECT cp.key, cp.value 
FROM rest_api_credential_params cp
JOIN rest_api_credentials c ON cp.credential_id = c.id
JOIN rest_api_connections conn ON conn.credential_id = c.id
WHERE conn.device_id = 2;
```

Required parameters for Session Token:
- `api_token`: Your API token
- `login_path`: Usually `/api/2.26/login`
- `token_header`: Usually `x-auth-token`
- `api_token_header`: Usually `api-token`
- `session_ttl`: Token lifetime in seconds (default 3600)

---

### Issue 5: SSL Verification Issues

If connecting to device with self-signed certificate:

**Check**:
```sql
SELECT name, base_url, disable_ssl_verify 
FROM rest_api_connections 
WHERE device_id = 2;
```

**Fix**: Set `disable_ssl_verify = 1` for devices with self-signed certificates

---

### Issue 6: Rate Limiting

**Check if rate limited**:
```bash
php artisan tinker
```

```php
$connection = \App\Models\RestApiConnection::where('device_id', 2)->first();
$cacheKey = "rest_api_rate_limit:{$connection->id}";
$requests = Cache::get($cacheKey, []);
echo "Requests in last minute: " . count($requests) . "\n";
echo "Rate limit: " . $connection->rate_limit . "\n";
```

---

## 📊 Check What's Actually Happening

### View Real-Time Polling Logs

```bash
# Clear logs and watch live
> /opt/librenms/storage/logs/laravel.log
tail -f /opt/librenms/storage/logs/laravel.log &
php lnms device:poll 2 -m rest-api -vvv
```

**Look for**:
- "Polling REST APIs for device..."
- "Polling URL: ..."
- "API Response for endpoint..."
- "Processing X items for endpoint..."
- Any errors or warnings

### Check if Metrics Are Being Created

```sql
-- Recent metrics
SELECT 
    resource_type,
    resource_name,
    metric_name,
    value,
    string_value,
    collected_at
FROM device_api_metrics
WHERE device_id = 2
ORDER BY collected_at DESC
LIMIT 20;

-- Count by resource type
SELECT 
    resource_type,
    COUNT(*) as metric_count,
    MAX(collected_at) as last_collected
FROM device_api_metrics
WHERE device_id = 2
GROUP BY resource_type;
```

---

## 🔧 Quick Fixes

### Fix 1: Add Metric Maps to All Endpoints

```sql
-- Example: Update array endpoint with metric map
UPDATE rest_api_endpoints 
SET metric_map = '{
    "name": "name",
    "capacity": "capacity",
    "space.total_physical": "total",
    "space.available": "space.available",
    "space.data_reduction": "space.data_reduction"
}'::jsonb
WHERE name = 'Array Info' 
AND connection_id IN (SELECT id FROM rest_api_connections WHERE device_id = 2);
```

### Fix 2: Set Resource Paths

```sql
-- Update endpoints with proper resource identification
UPDATE rest_api_endpoints
SET 
    resource_id_path = 'name',
    resource_name_path = 'name',
    resource_type = 'array'
WHERE name = 'Array Info'
AND connection_id IN (SELECT id FROM rest_api_connections WHERE device_id = 2);
```

### Fix 3: Enable Debug Logging in Code

Temporarily add to `/app/Pollers/Api.php` at line 91 (in processApiResponse):

```php
Log::debug("API Response structure", [
    'endpoint' => $endpoint->name,
    'has_items' => isset($data['items']),
    'is_array' => Arr::isList($data),
    'keys' => array_keys($data),
    'item_count' => isset($data['items']) ? count($data['items']) : (Arr::isList($data) ? count($data) : 'single item'),
]);
```

---

## ✅ Verification Checklist

Before polling will work, verify ALL of these:

- [ ] Device has at least one REST API connection with `enabled = 1`
- [ ] Connection has credential configured with correct auth type
- [ ] Credential has all required parameters for auth type
- [ ] Connection has at least one endpoint configured
- [ ] Each endpoint has `metric_map` configured (NOT NULL, NOT EMPTY)
- [ ] Each endpoint has `resource_id_path` set
- [ ] Each endpoint has `resource_name_path` set  
- [ ] Each endpoint has `resource_type` set
- [ ] API is reachable from LibreNMS server
- [ ] Authentication works (check with curl or diagnostic script)
- [ ] API response format matches what poller expects

---

## 🚀 Test Commands

```bash
# Run diagnostic script
./diagnostic_rest_api_polling.php 2

# Poll with maximum verbosity
php lnms device:poll 2 -m rest-api -vvv

# Check database directly
mysql -u librenms -p librenms -e "
SELECT COUNT(*) as total_metrics 
FROM device_api_metrics 
WHERE device_id = 2;"

# Test API manually with curl (for PureStorage)
curl -k -X POST https://172.16.7.40/api/2.26/login \
  -H "api-token: YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -v

# If login works, test an endpoint
curl -k -X GET https://172.16.7.40/api/2.26/arrays \
  -H "x-auth-token: SESSION_TOKEN_FROM_LOGIN" \
  -v
```

---

## 📝 Most Likely Cause

Based on your screenshots, the most likely issue is:

**Endpoints are configured but have no `metric_map` defined.**

The poller runs successfully, makes API calls, but doesn't know which metrics to extract from the response because `metric_map` is empty.

**Solution**: Add metric maps to all endpoints via the Edit button for each endpoint.

---

**Run the diagnostic script first - it will tell you exactly what's wrong!**

```bash
./diagnostic_rest_api_polling.php 2
```
