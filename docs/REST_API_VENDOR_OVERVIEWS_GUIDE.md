# REST API Vendor Overview Pages - Complete Guide

## Overview

This document provides a comprehensive guide to all vendor-specific REST API overview pages created for LibreNMS. Each vendor has a customized layout optimized for their specific metrics and features.

## 📁 Created Vendor Overviews (7 files)

### 1. PureStorage FlashArray
**File:** `/includes/html/pages/device/overview/rest-api/purestorage.inc.php`  
**OS Match:** `purestorage`

**Displays:**
- Array storage metrics with capacity visualization
- Data reduction ratio and space savings
- Volume performance table (Top 10 by size)
- Real-time IOPS metrics (Read/Write/Total)
- Host connections list
- Network interfaces with speeds and services

**Key Metrics:**
- `resource_type: array` - capacity, data_reduction, space.available
- `resource_type: volume` - size, provisioned, iops, data_reduction
- `resource_type: host` - connection metrics
- `resource_type: network-interface` - speed, address, services

---

### 2. Palo Alto Networks Firewall
**File:** `/includes/html/pages/device/overview/rest-api/panos.inc.php`  
**OS Match:** `panos`

**Displays:**
- Firewall system information (model, PAN-OS version, HA status)
- Session utilization with capacity bar
- Top security policies by hit count
- Network interface statistics
- Threat detection statistics

**Key Metrics:**
- `resource_type: system` - hostname, model, sw_version, ha_state
- `resource_type: session` - active, max sessions
- `resource_type: security-policy` - hit_count, bytes, sessions
- `resource_type: interface` - status, speed, rx/tx bytes/errors
- `resource_type: threat` - threat counts by type

---

### 3. Cisco IOS/IOS-XE/NX-OS
**File:** `/includes/html/pages/device/overview/rest-api/ios.inc.php`  
**OS Match:** `ios`, `iosxe`, `nxos`

**Displays:**
- System health (CPU, Memory utilization)
- Device information (model, IOS version, serial)
- Interface statistics (Top 20 interfaces)
- Admin/Operational status indicators
- Routing table summary by protocol
- Environmental sensor data (temperature)

**Key Metrics:**
- `resource_type: system` - cpu_utilization, memory_used/total, version, model
- `resource_type: interface` - admin_status, oper_status, speed, octets, errors
- `resource_type: routing` - route_count, protocol
- `resource_type: sensor` - temperature, status, threshold

---

### 4. Fortinet FortiGate
**File:** `/includes/html/pages/device/overview/rest-api/fortios.inc.php`  
**OS Match:** `fortios`, `fortigate`

**Displays:**
- System health (CPU, Memory, Session utilization)
- VPN tunnel status and traffic statistics
- Top security policies by hit count
- IPS/Threat detection statistics
- Network interface statistics

**Key Metrics:**
- `resource_type: system` - cpu, memory, session_count/limit, ha_mode
- `resource_type: vpn-tunnel` - status, remote_gw, tx/rx bytes, uptime
- `resource_type: firewall-policy` - hit_count, bytes, packets, action
- `resource_type: ips` - threat signatures and counts
- `resource_type: interface` - status, speed, tx/rx bytes/packets

---

### 5. Juniper Networks (Junos)
**File:** `/includes/html/pages/device/overview/rest-api/junos.inc.php`  
**OS Match:** `junos`

**Displays:**
- Routing Engine health (CPU, Memory)
- Chassis information
- BGP peer status and route counts
- FPC (Flexible PIC Concentrator) status
- Interface statistics with bandwidth usage

**Key Metrics:**
- `resource_type: routing-engine` - cpu_utilization, memory_utilization, version
- `resource_type: chassis` - hostname, model, serial
- `resource_type: bgp-peer` - state, peer_as, routes_received/accepted, uptime
- `resource_type: fpc` - state, temperature, memory/cpu utilization
- `resource_type: interface` - admin/oper status, speed, input/output bps, errors

---

### 6. TrueNAS
**File:** `/includes/html/pages/device/overview/rest-api/truenas.inc.php`  
**OS Match:** `truenas`

**Displays:**
- System health (CPU, Memory usage)
- Storage pool status with health indicators
- Pool utilization bars and fragmentation
- Top datasets by usage with compression ratios
- Network shares (NFS, SMB, iSCSI)
- Replication task status

**Key Metrics:**
- `resource_type: system` - cpu_usage, memory_used/total, version, uptime
- `resource_type: pool` - status, health, size, allocated, free, fragmentation
- `resource_type: dataset` - used, available, compression_ratio, type
- `resource_type: nfs-share/smb-share/iscsi-share` - path, enabled, type
- `resource_type: replication` - state, direction, last_run

---

### 7. Arista EOS
**File:** `/includes/html/pages/device/overview/rest-api/eos.inc.php`  
**OS Match:** `eos`, `arista`

**Displays:**
- System information and EOS version
- MLAG (Multi-Chassis Link Aggregation) status
- Port channel status and member counts
- VLAN configuration and status
- Interface statistics

**Key Metrics:**
- `resource_type: system` - hostname, model, version, serial
- `resource_type: mlag` - domain_id, state, peer_address, peer_link_status
- `resource_type: port-channel` - status, protocol, member_count
- `resource_type: vlan` - status, name, ports
- `resource_type: interface` - status, description, speed, octets, errors

---

### 8. Generic Fallback
**File:** `/includes/html/pages/device/overview/rest-api/generic.inc.php`  
**OS Match:** *any other OS*

**Displays:**
- Auto-discovered resource types
- Dynamic table generation for each resource type
- Up to 6 most relevant metrics per resource
- Smart value formatting (bytes, numbers, strings)
- Works with any REST API-enabled device

**Features:**
- Automatic resource type discovery
- Adaptive metric display
- Handles mixed value types
- Truncates long strings
- Shows last update timestamps

---

## 🗺️ OS Name Mapping

To ensure the correct vendor overview loads, the device OS must match the filename:

| Vendor | Expected OS Values | Filename |
|--------|-------------------|----------|
| **PureStorage** | `purestorage` | `purestorage.inc.php` |
| **Palo Alto** | `panos` | `panos.inc.php` |
| **Cisco IOS** | `ios`, `iosxe`, `nxos` | `ios.inc.php` |
| **Fortinet** | `fortios`, `fortigate` | `fortios.inc.php` |
| **Juniper** | `junos` | `junos.inc.php` |
| **TrueNAS** | `truenas` | `truenas.inc.php` |
| **Arista** | `eos`, `arista` | `eos.inc.php` |
| **Other** | *any other* | `generic.inc.php` |

**Note:** OS values are case-insensitive. The router converts to lowercase before matching.

---

## 📊 Common Resource Types Across Vendors

### Networking Equipment
- `interface` - Network interfaces and ports
- `bgp-peer` - BGP peering information
- `routing` - Routing table data
- `vlan` - VLAN configuration
- `port-channel` - Link aggregation groups

### Security Devices
- `security-policy` / `firewall-policy` - Firewall rules
- `vpn-tunnel` - VPN connections
- `threat` / `ips` - Security threat data
- `session` - Active sessions

### Storage Arrays
- `array` - Array-level metrics
- `pool` - Storage pools
- `volume` / `dataset` - Volumes and datasets
- `host` - Connected hosts
- `nfs-share` / `smb-share` / `iscsi-share` - Network shares

### System Resources
- `system` / `chassis` - System information
- `routing-engine` - Control plane (Juniper)
- `fpc` - Line cards (Juniper)
- `sensor` - Environmental sensors

---

## 🎨 Visual Elements Used

### Status Indicators
```php
<span class="label label-success">UP</span>      // Green - Good
<span class="label label-danger">DOWN</span>     // Red - Critical
<span class="label label-warning">WARN</span>    // Yellow - Warning
<span class="label label-info">INFO</span>       // Blue - Informational
<span class="label label-primary">PRIMARY</span> // Dark Blue
<span class="label label-default">N/A</span>     // Gray - Neutral
```

### Capacity Bars
- Color-coded based on utilization percentage
- Green (< 70%), Yellow (70-90%), Red (> 90%)
- Shows used/total with visual representation

### Badges
- Count indicators for collections
- Pull-right alignment for visual balance

### Icons (Font Awesome)
- `fa-shield` - Firewalls/Security
- `fa-database` - Storage
- `fa-server` - Servers/Systems
- `fa-network-wired` - Networking
- `fa-lock` - VPN/Security
- `fa-bug` - Threats/IPS
- `fa-project-diagram` - BGP/Routing
- `fa-microchip` - Hardware components

---

## 🔧 Customization Guide

### Adding a New Vendor

1. **Create vendor file:**
```bash
touch /includes/html/pages/device/overview/rest-api/yourvendor.inc.php
```

2. **Use template structure:**
```php
<?php
use Illuminate\Support\Facades\DB;
use LibreNMS\Util\Number;

// Query metrics
$metrics = DB::table('device_api_metrics')
    ->where('device_id', $device['device_id'])
    ->where('resource_type', 'your_type')
    ->get();

// Display panels
?>
<div class="row">
    <!-- Your panels here -->
</div>
```

3. **Set device OS to match filename**

### Modifying Existing Vendor

Each vendor file is standalone. To customize:

1. Open the vendor file
2. Modify SQL queries to add/remove metrics
3. Update table columns as needed
4. Adjust formatting or add calculations
5. Test with device

### Common Query Patterns

**Pivot metrics:**
```php
DB::raw('MAX(CASE WHEN metric_name = "cpu" THEN value END) as cpu')
```

**Filter and limit:**
```php
->where('resource_type', 'interface')
->orderBy('speed', 'desc')
->limit(20)
```

**Group aggregations:**
```php
->groupBy('resource_name', 'resource_id')
```

---

## 📈 Metric Type Reference

### Numeric Metrics (stored in `value` column)
- Capacity, size, bytes
- CPU, memory percentages
- Counts, rates, IOPS
- Speed, bandwidth
- Temperatures

**Formatting:**
```php
// Storage
Number::formatBi($bytes)  // "23.41 TB"

// Percentage
number_format($percent, 2) . "%"  // "46.32%"

// Count
number_format($count)  // "45,450"

// Speed
($bps / 1000000000) . " Gbps"  // "40 Gbps"
```

### String Metrics (stored in `string_value` column)
- Hostnames, names
- Status values (up/down, active/inactive)
- IP addresses
- Descriptions
- Version strings

**Formatting:**
```php
// Truncate long strings
substr($string, 0, 30)

// HTML escape
htmlspecialchars($string)

// Uppercase for labels
strtoupper($status)
```

---

## 🚦 Status Color Mapping

### Standard Status Values
```php
// Interface/Link Status
'up' / 'connected' / 'active' → label-success (green)
'down' / 'disconnected' / 'inactive' → label-danger (red)

// Health Status
'healthy' / 'normal' / 'ok' → label-success (green)
'degraded' / 'warning' → label-warning (yellow)
'failed' / 'critical' / 'error' → label-danger (red)

// Operational Status
'established' / 'online' / 'enabled' → label-success (green)
'disabled' / 'offline' → label-default (gray)
```

### Capacity Thresholds
```php
< 70% → Green (normal)
70-80% → Yellow (warning threshold)
80-90% → Orange (high utilization)
> 90% → Red (critical)
```

---

## 🔄 Adding Support for Multiple OS Names

Some vendors use multiple OS identifiers. To support multiple:

**Option 1: Symlink (Unix/Linux)**
```bash
ln -s fortios.inc.php fortigate.inc.php
```

**Option 2: Conditional in router**
Modify `rest-api.inc.php`:
```php
$vendor_os = strtolower($device['os']);

// Map multiple OS names to same file
$os_map = [
    'iosxe' => 'ios',
    'nxos' => 'ios',
    'fortigate' => 'fortios',
    'arista' => 'eos',
];

$vendor_os = $os_map[$vendor_os] ?? $vendor_os;
```

---

## 📊 Performance Optimization

### Query Best Practices

1. **Use indexes** (already created):
   - `device_id, resource_type, resource_id`
   - `device_id, collected_at`
   - `resource_type, metric_name, collected_at`

2. **Limit result sets:**
```php
->limit(10)  // Top 10 only
->orderBy('collected_at', 'desc')  // Latest first
```

3. **Aggregate in SQL, not PHP:**
```php
// Good: SQL aggregation
DB::raw('MAX(CASE WHEN metric_name = "x" THEN value END)')

// Avoid: PHP loops
foreach ($metrics as $m) { if ($m->metric_name == 'x') ... }
```

4. **Use groupBy efficiently:**
```php
->groupBy('resource_name', 'resource_id')  // Group once
```

### Caching (for high-traffic sites)

```php
use Illuminate\Support\Facades\Cache;

$cache_key = "rest_api_{$device['device_id']}_system";
$metrics = Cache::remember($cache_key, 300, function() use ($device) {
    return DB::table('device_api_metrics')
        ->where('device_id', $device['device_id'])
        ->where('resource_type', 'system')
        ->get();
});
```

---

## 🧪 Testing Vendor Overviews

### 1. Verify OS Match
```bash
mysql librenms -e "SELECT device_id, hostname, os FROM devices WHERE device_id = 1"
```

### 2. Check Metrics Exist
```bash
php artisan tinker
```
```php
DB::table('device_api_metrics')
    ->where('device_id', 1)
    ->distinct()
    ->pluck('resource_type');
```

### 3. Test Specific Queries
```php
// Test interface query
DB::table('device_api_metrics')
    ->select([
        'resource_name',
        DB::raw('MAX(CASE WHEN metric_name = "status" THEN string_value END) as status')
    ])
    ->where('device_id', 1)
    ->where('resource_type', 'interface')
    ->groupBy('resource_name')
    ->get();
```

### 4. View in Browser
1. Navigate to device overview
2. Check browser console for JS errors
3. Verify panels display correctly
4. Check metric values are accurate

---

## 🐛 Troubleshooting

### Issue: Wrong vendor file loads

**Check:**
```bash
# Verify device OS
mysql librenms -e "SELECT os FROM devices WHERE device_id = 1"

# Check if vendor file exists
ls -la includes/html/pages/device/overview/rest-api/[os].inc.php
```

**Solution:**
- Update device OS in LibreNMS
- Create symlink for alternate OS names
- Verify filename matches OS (lowercase)

### Issue: No metrics display

**Check:**
```bash
# Count metrics
mysql librenms -e "SELECT COUNT(*) FROM device_api_metrics WHERE device_id = 1"

# Check resource types
mysql librenms -e "SELECT DISTINCT resource_type FROM device_api_metrics WHERE device_id = 1"
```

**Solution:**
- Run REST API polling: `php lnms device:poll 1 -m rest-api -vv`
- Verify endpoint configuration
- Check API credentials

### Issue: PHP errors in logs

**Check:**
```bash
tail -f /opt/librenms/storage/logs/laravel.log | grep -i error
```

**Common fixes:**
- Add missing `use` statements
- Check for undefined array keys (use `?? 0` or `?? 'N/A'`)
- Verify column names in database

---

## 📝 Vendor Overview Summary

| Vendor | File | Panels | Key Features |
|--------|------|--------|--------------|
| **PureStorage** | `purestorage.inc.php` | 4 | Capacity bars, IOPS, data reduction |
| **Palo Alto** | `panos.inc.php` | 4 | Sessions, policies, threats |
| **Cisco** | `ios.inc.php` | 3 | CPU/Memory, interfaces, routing |
| **Fortinet** | `fortios.inc.php` | 4 | VPN tunnels, policies, IPS |
| **Juniper** | `junos.inc.php` | 4 | BGP peers, FPCs, RE health |
| **TrueNAS** | `truenas.inc.php` | 4 | Pools, datasets, shares, replication |
| **Arista** | `eos.inc.php` | 3 | MLAG, port channels, VLANs |
| **Generic** | `generic.inc.php` | Auto | Dynamic resource discovery |

---

## 🚀 Quick Reference

### File Locations
```
/includes/html/pages/device/overview/rest-api/
├── purestorage.inc.php
├── panos.inc.php
├── ios.inc.php
├── fortios.inc.php
├── junos.inc.php
├── truenas.inc.php
├── eos.inc.php
└── generic.inc.php
```

### Common Imports
```php
use Illuminate\Support\Facades\DB;
use LibreNMS\Util\Number;
use LibreNMS\Util\Color;
```

### Essential Functions
```php
Number::formatBi($bytes)                    // Format storage
\LibreNMS\Util\Color::percentage($pct)      // Get color for percentage
\Carbon\Carbon::parse($ts)->diffForHumans() // Relative time
print_percentage_bar(...)                    // Capacity bar
htmlspecialchars($str)                      // Escape HTML
```

---

**Total Vendor Overviews Created:** 8 (7 vendor-specific + 1 generic)  
**Status:** ✅ Complete and Production-Ready  
**Documentation:** Comprehensive guides included  

All vendor overview pages are optimized for their respective platforms and ready for use!
