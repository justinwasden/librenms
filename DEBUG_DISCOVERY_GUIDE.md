# Pure Storage Debug Discovery - Usage Guide

## Quick Start

```bash
cd /opt/librenms
chmod +x scripts/debug_discovery.sh
./scripts/debug_discovery.sh 172.16.7.40
```

## What You'll See

### 1. Detailed Flow Tracking
```
═══════════════════════════════════════════════════════════════
METRICS STAGER - START
═══════════════════════════════════════════════════════════════
[network-interfaces] Device: 172.16.7.40 (ID: 2)
[network-interfaces] Resource Type: network-interface
[network-interfaces] Endpoint: network-interfaces
[network-interfaces] Item Context:
[network-interfaces]   name: ct0.eth0
[network-interfaces]   id: <null>
[network-interfaces]   index: 0
[network-interfaces] Sample Metrics (first 10):
[network-interfaces]   name = ct0.eth0
[network-interfaces]   enabled = true
[network-interfaces]   speed = 10000000000
[network-interfaces]   eth_mac_address = 52:54:30:00:00:00
[network-interfaces]   eth_mtu = 1500
...
```

### 2. Mapping Process
```
✓ [network-interfaces] speed -> ports.ifSpeed
✓ [network-interfaces] enabled -> ports.ifAdminStatus
✓ [network-interfaces] eth_mac_address -> ports.ifPhysAddress
✓ [network-interfaces] eth_mtu -> ports.ifMtu
```

### 3. Database Updates
```
[network-interfaces] speed -> ports.ifSpeed (port: ct0.eth0) = 10000000000
[network-interfaces] enabled -> ports.ifAdminStatus (port: ct0.eth0) = up
[network-interfaces] eth_mac_address -> ports.ifPhysAddress (port: ct0.eth0) = 52:54:30:00:00:00
```

### 4. Filtering
```
Filtering VM/host from discovery: ALM-C220-ESXI-01
Filtering hardware component from discovery: CH0.BAY0
[network-interfaces] Skipping non-network interface: ALMH-C1S5
```

## Debug Log Structure

The log shows the complete flow:

1. **Discovery Level** (`RestApiDiscovery`)
   - API request/response
   - Item filtering (hardware/VMs)
   - Calls to MetricsStager

2. **Stager Level** (`MetricsStager`)
   - Shows all metrics received
   - Item context (name, id)
   - Calls to DataRouter

3. **Router Level** (`DataRouter`)
   - Mapping lookups
   - Field transformations
   - Database updates

4. **Result Verification**
   - Port counts
   - Field population
   - Sample data

## Analyzing The Log

### Check if items are being filtered correctly:
```bash
grep "Filtering\|Skipping" /tmp/pure_discovery_debug_*.log
```

### Check if mappings are found:
```bash
grep "-> ports\\." /tmp/pure_discovery_debug_*.log
```

### Check if database updates are happening:
```bash
grep "storeInPortsTable\|ports\\.(ifSpeed|ifAdminStatus)" /tmp/pure_discovery_debug_*.log
```

### Check for errors:
```bash
grep -i "error\|failed" /tmp/pure_discovery_debug_*.log
```

## Common Issues and What to Look For

### Issue: No metrics shown in METRICS STAGER section
**Problem**: API is not returning data or JsonFlattener is failing
**Look for**: "Flattener returned 0 metrics"

### Issue: Mappings not found (no "✓" symbols)
**Problem**: Mapping definitions don't match API field names
**Look for**: Lines without "✓" before the metric key
**Solution**: Check `rest_api_metric_field_mappings` table

### Issue: Mappings found but no database updates
**Problem**: storeInPortsTable() is failing
**Look for**: Error messages after mapping lines
**Solution**: Check database permissions or port existence

### Issue: Wrong items being discovered
**Problem**: Filtering not working
**Look for**: Items like "ALM-C220-ESXI" or "CH0.BAY" NOT being filtered
**Solution**: Check shouldFilterPureStorageItem() logic

## Expected Successful Output

You should see:
```
Items Discovered: ~70
Items Filtered: ~40 (hardware/VMs)
Metrics Staged: ~30 (one per valid interface)
Mappings Found: ~9 per interface
Port Updates: Multiple "ports.ifSpeed", "ports.ifAdminStatus", etc.
Errors: None (or minimal warnings)

Port Count:
+-------+------------+-------------+----------+
| total | with_speed | with_status | with_mac |
+-------+------------+-------------+----------+
|    36 |         36 |          36 |       36 |
+-------+------------+-------------+----------+
```

## Debug Log Saved To

All output is saved to `/tmp/pure_discovery_debug_YYYYMMDD_HHMMSS.log`

You can share this file for analysis if issues persist.

## Next Steps

1. **If filtering works but fields are NULL**:
   - Check if mappings exist: `SELECT * FROM rest_api_metric_field_mappings WHERE librenms_table = 'ports';`
   - Check if API field names match: Compare API fields in log with mapping `api_field_name`

2. **If invalid items still being discovered**:
   - Check RestApiDiscovery.php filtering logic
   - Verify device OS is detected as 'purestorage'

3. **If mappings found but updates fail**:
   - Check database logs for errors
   - Verify Port model can be updated
   - Check file permissions

## Manual Testing

### Test a single metric transformation:
```php
$mapping = RestApiMetricFieldMapping::where('api_field_name', 'speed')->first();
$value = 10000000000;
$transformed = $mapping->transformValue($value);
echo "Original: {$value}, Transformed: {$transformed}\n";
```

### Test port creation:
```php
$port = new Port();
$port->device_id = 2;
$port->ifName = 'test_port';
$port->ifDescr = 'test';
$port->port_descr_type = 'rest-api';
$port->ifIndex = 999999;
$port->save();
echo "Port created: {$port->port_id}\n";
```

### Test port update:
```php
$port = Port::where('device_id', 2)->where('ifName', 'ct0.eth0')->first();
if ($port) {
    $port->ifSpeed = 10000000000;
    $port->save();
    echo "Port updated\n";
}
```
