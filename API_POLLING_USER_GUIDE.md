# LibreNMS API Polling User Guide

## Table of Contents
1. [Overview](#overview)
2. [Supported Device Types](#supported-device-types)
3. [Prerequisites](#prerequisites)
4. [Configuration Guide by Device Type](#configuration-guide-by-device-type)
5. [Advanced Features](#advanced-features)
6. [Troubleshooting](#troubleshooting)
7. [API Templates Reference](#api-templates-reference)

---

## Overview

LibreNMS REST API Polling provides an alternative to SNMP for monitoring devices that have modern REST/SOAP/XML APIs. This system offers several advantages:

- **Better Coverage**: Many cloud and virtualization platforms have limited SNMP support but comprehensive APIs
- **Real-time Metrics**: APIs often provide more current data than SNMP
- **Richer Data**: APIs expose detailed information not available via SNMP (VMs, clusters, hypervisor stats)
- **Authentication**: Modern token-based authentication instead of SNMP community strings
- **Performance**: Reduced polling overhead compared to walking large SNMP tables

### When to Use API Polling

| Device Type | Recommendation |
|-------------|---------------|
| VMware VeloCloud | **Required** - SNMP very limited |
| VMware ESXi | **Recommended** - SOAP API provides VM discovery, datastores |
| VMware vCenter | **Recommended** - Cluster-wide visibility |
| Pure Storage FlashArray | **Recommended** - Better performance metrics than SNMP |
| Proxmox VE | **Both** - Use API for VM discovery, SNMP for host metrics |
| Cisco UCS Manager | **Either** - Both XML API and SNMP work well |
| NetApp ONTAP | **Either** - Both REST API and SNMP are mature |
| FortiGate | **Either** - REST API and SNMP both comprehensive |

---

## Supported Device Types

The following device types have built-in API polling templates:

| OS Key | Device Type | API Type | Auth Method | Capabilities |
|--------|-------------|----------|-------------|-------------|
| `velocloud` | VMware VeloCloud | REST | Token/Session | Device info, ports, IPv4, processors, memory, sensors |
| `vmware-esxi` | VMware ESXi | SOAP | Username/Password | Device info, VMs, datastores, ports, VLANs, processors, memory |
| `vmware-vcsa` | VMware vCenter | REST | Username/Password | Clusters, VMs, datastores, networks |
| `purestorage` | Pure Storage FlashArray | REST | API Token | Array metrics, controllers, drives, volumes, ports, transceivers |
| `proxmox` | Proxmox VE | REST | API Token | Node metrics, VMs, storage, clusters, ports, disks |
| `netapp` | NetApp ONTAP | REST | Basic Auth | Cluster nodes, ports, volumes, storage, IPv4 |
| `cisco-usm` | Cisco UCS Manager | XML | Username/Password | Blades, FIs, chassis, processors, memory, sensors |
| `fortigate` | Fortinet FortiGate | REST | Bearer Token | System status, interfaces, sessions, VPNs, sensors |

---

## Prerequisites

### System Requirements
- LibreNMS version 25.10.0 or later
- PHP 8.x with `php-curl` extension
- Network connectivity to device API endpoints
- Valid API credentials for each device

### Device Requirements
- Device must have API access enabled
- Network firewall rules must allow API traffic
- Valid SSL certificates (or disable SSL verification for testing)

---

## Configuration Guide by Device Type

### 1. VMware VeloCloud SD-WAN

**Use Case**: Monitor SD-WAN edges, link quality, tunnel status

#### Step 1: Enable API Access on VeloCloud Orchestrator
1. Log in to VeloCloud Orchestrator
2. Navigate to **Administration > API Access**
3. Create an API user with `read-only` permissions
4. Note the **username** and **password**

#### Step 2: Add Device in LibreNMS
1. Add the VeloCloud Orchestrator as a device (use the VCO hostname/IP)
2. OS will be detected as `velocloud`

#### Step 3: Configure API Polling
1. Go to device → **Settings** → **Device API** tab
2. Click **Enable API Polling**
3. Select template: **VMware VeloCloud (SD-WAN)**
4. Fill in credentials:
   - **Base URL**: `https://vco-hostname.velocloud.net`
   - **Username**: Your VeloCloud API username
   - **Password**: Your VeloCloud API password
   - **Enterprise ID**: Your enterprise ID (numeric)
   - **Monitoring Mode**: `single_edge` or `all_edges`

#### Step 4: Configure Per-Edge Polling
For monitoring individual edges:
1. Add each edge as a separate device (use edge public IP)
2. Configure with Edge ID for single-edge monitoring
3. Enable these endpoints:
   - `enterprise/getEnterpriseEdges` - Device info, inventory
   - `monitoring/getAggregateEdgeLinkMetrics` - Ports, sensors, statistics
   - `metrics/getEdgeStatusMetrics` - Processors, memory

**Collected Data**:
- Edge device information (model, serial, version)
- WAN link status and quality metrics
- Active tunnels and flows
- CPU and memory utilization
- Link speed and throughput

---

### 2. VMware ESXi (Standalone Host)

**Use Case**: Monitor ESXi hosts, VMs, datastores without vCenter

#### Step 1: Enable API Access on ESXi
ESXi SOAP API is enabled by default. No configuration needed.

#### Step 2: Create API User
```bash
# SSH to ESXi host
# Create a read-only user
vim-cmd vimsvc/auth/entity_permission_add vim.Folder:ha-folder-root librenms false ReadOnly true
```

#### Step 3: Configure in LibreNMS
1. Add ESXi host as device
2. Go to **Settings** → **Device API**
3. Select template: **VMware ESXi Host (SOAP API)**
4. Fill in credentials:
   - **Base URL**: `https://esxi-hostname/sdk`
   - **Username**: `root` or API user
   - **Password**: ESXi password

**Collected Data**:
- Host hardware (model, serial, BIOS version)
- CPU and memory utilization
- Network interfaces and statistics
- Datastores and capacity
- Virtual machines and their state
- VLANs and port groups

---

### 3. VMware vCenter Server

**Use Case**: Monitor vCenter clusters, VMs, distributed switches

#### Step 1: Create API User
1. Log in to vCenter
2. Create a user with `Read-only` role
3. Assign permissions at datacenter level

#### Step 2: Configure in LibreNMS
1. Add vCenter as device (use VCSA IP/hostname)
2. OS: `vmware-vcsa`
3. **Settings** → **Device API**
4. Select template: **VMware vCenter Server**
5. Credentials:
   - **Base URL**: `https://vcenter-hostname`
   - **Username**: `username@vsphere.local`
   - **Password**: vCenter password

**Collected Data**:
- Datacenter and cluster topology
- All VMs across clusters
- Datastore utilization
- Distributed port groups
- Resource pools

---

### 4. Pure Storage FlashArray

**Use Case**: Monitor storage arrays, controllers, volumes

#### Step 1: Create API Token
```bash
# SSH to FlashArray or use GUI
pureuser create librenms --role readonly
pureuser create --api-token librenms
# Note the generated API token
```

#### Step 2: Configure in LibreNMS
1. Add FlashArray as device
2. **Settings** → **Device API**
3. Template: **Pure Storage FlashArray**
4. Credentials:
   - **Base URL**: `https://array-hostname/api/2.26`
   - **API Token**: Token from Step 1

**Collected Data**:
- Array performance (IOPS, bandwidth, latency)
- Controller status
- Drive inventory and health
- Volume metrics
- Network interfaces and transceivers
- Data reduction ratios

---

### 5. Proxmox VE

**Use Case**: Monitor Proxmox cluster, nodes, VMs, storage

#### Step 1: Create API Token
```bash
# On Proxmox node
pveum user add librenms@pve
pveum aclmod / -user librenms@pve -role PVEAuditor
pveum user token add librenms@pve librenms-token --privsep 0
# Note the token ID and secret
```

#### Step 2: Configure in LibreNMS
1. Add Proxmox node as device
2. **Settings** → **Device API**
3. Template: **Proxmox VE Node**
4. Credentials:
   - **Base URL**: `https://pxe-hostname:8006/api2/json`
   - **Token User**: `librenms@pve`
   - **Token ID**: `librenms-token`
   - **Token Secret**: Secret from Step 1

**Collected Data**:
- Node CPU, memory, uptime
- Storage pools and utilization
- Network interfaces
- Virtual machines (QEMU/LXC)
- Disk SMART data
- Cluster status

---

### 6. NetApp ONTAP

**Use Case**: Monitor NetApp storage clusters, volumes, network

#### Step 1: Create API User
```bash
# On ONTAP cluster
security login create -user-or-group-name librenms -application http -authentication-method password -role readonly
security login create -user-or-group-name librenms -application ontapi -authentication-method password -role readonly
```

#### Step 2: Configure in LibreNMS
1. Add NetApp cluster management interface as device
2. **Settings** → **Device API**
3. Template: **NetApp ONTAP API**
4. Credentials:
   - **Base URL**: `https://cluster-mgmt-ip/api`
   - **Username**: `librenms`
   - **Password**: User password

**Version Notes**:
- ONTAP 9.8-9.10: Basic metrics (ports, volumes, inventory)
- ONTAP 9.11+: Includes CPU/memory statistics (auto-enabled)

**Collected Data**:
- Cluster nodes and configuration
- Network ports and IPv4 addresses
- Storage volumes and capacity
- Volume IOPS and throughput
- CPU/memory (9.11+ only)

---

### 7. Cisco UCS Manager

**Use Case**: Monitor UCS chassis, blade servers, fabric interconnects

#### Step 1: Create Read-Only User
1. Log in to UCS Manager GUI
2. Navigate to **Admin** → **User Management**
3. Create user with **read-only** role

#### Step 2: Configure in LibreNMS
1. Add UCS Manager VIP as device
2. **Settings** → **Device API**
3. Template: **Cisco UCS Manager (XML API)**
4. Credentials:
   - **Base URL**: `https://ucsm-vip`
   - **Username**: API username
   - **Password**: User password

**Collected Data**:
- Fabric interconnect status
- Chassis inventory
- Blade servers (CPU, memory)
- Power supplies and fans
- Temperature sensors
- Faults and alarms

---

### 8. Fortinet FortiGate

**Use Case**: Monitor firewall resources, sessions, VPNs

#### Step 1: Create API User
```
config system api-user
    edit "librenms"
        set api-key <generate-strong-key>
        set accprofile "prof_admin"
        config trusthost
            edit 1
                set ipv4-trusthost <librenms-ip>/32
            next
        end
    next
end
```

#### Step 2: Configure in LibreNMS
1. Add FortiGate as device
2. **Settings** → **Device API**
3. Template: **Fortinet FortiGate**
4. Credentials:
   - **Base URL**: `https://fortigate-hostname/api/v2`
   - **Bearer Token**: API key from Step 1

**Collected Data**:
- System CPU and memory
- Interfaces and statistics
- Session counts and rates
- VPN status (IPsec, SSL)
- DHCP leases
- License information

---

## Advanced Features

### 1. Version-Based Endpoint Management

The system automatically enables/disables endpoints based on device version:

**Example: NetApp ONTAP**
- ONTAP 9.8-9.10: Processor/mempool endpoints **disabled** (statistics field not supported)
- ONTAP 9.11+: Processor/mempool endpoints **auto-enabled** on first poll

This happens automatically during polling via `DeviceApiVersionManager`.

### 2. API Response Caching

To reduce API load, responses are cached based on data type:

| Data Type | Cache TTL | Reason |
|-----------|-----------|--------|
| Device Info | 24 hours | Hardware rarely changes |
| Inventory | 1 hour | Components added infrequently |
| VLANs | 10 minutes | Config changes are rare |
| Ports Discovery | 5 minutes | Interfaces don't change often |
| VMs/Storage | 5 minutes | Moderate change rate |
| **Metrics** | **Not Cached** | Real-time data |

**Clear Cache**:
```bash
# Clear cache for a specific device
php artisan cache:forget "device_api_keys:{device_id}"

# Or poll with discovery flag to refresh all
./lnms device:poll {device_id} -m device-api
```

### 3. Error Handling and Degradation

API polling includes graceful error handling:

- **Endpoint Failures**: Continue polling other endpoints if one fails
- **Transform Errors**: Log error and skip persistence, don't crash poll
- **Error Tracking**: Failed endpoints logged to `devices_attribs` table
- **Cache on Success**: Only cache successful responses

**View Errors**:
```bash
# Check device attributes for API errors
php -r '
$device = App\Models\Device::find(33);
$attribs = $device->attribs;
foreach ($attribs as $attrib) {
    if (str_starts_with($attrib->attrib_type, "api_endpoint_last_error")) {
        echo $attrib->attrib_value . "\n";
    }
}
'
```

### 4. Custom Endpoint Configuration

Override template endpoints per-device:

1. Go to device → **Settings** → **Device API**
2. Click **Endpoint Configuration**
3. Enable/disable specific endpoints
4. Adjust display order

**Example Use Cases**:
- Disable `ports_statistics` endpoint if causing high load
- Enable experimental endpoints for testing
- Customize for specific device capabilities

### 5. For-Each Loops (Dynamic Endpoints)

Some endpoints loop over discovered items:

**Example: Proxmox Storage Polling**
```yaml
capability: storage
path: nodes/{node}/storage/{storageid}/status
for_each: inventory
for_each_options:
  placeholder: storageid
  value_key: storage
  filter_key: storage
```

This polls `/nodes/pve1/storage/local/status`, `/nodes/pve1/storage/zfs1/status`, etc.

---

## Troubleshooting

### Problem: API Polling Not Running

**Symptoms**: No data being collected via API

**Solutions**:
1. Check if API polling is enabled:
   ```bash
   php -r '$dev = App\Models\Device::find(XX); echo $dev->getAttrib("rest_enabled");'
   ```

2. Verify template assignment:
   ```bash
   php -r '$dev = App\Models\Device::find(XX); echo $dev->apiConfig->template->key;'
   ```

3. Check polling logs:
   ```bash
   tail -f /opt/librenms/logs/librenms.log | grep "REST API"
   ```

### Problem: Authentication Failures

**Symptoms**: `401 Unauthorized` or `403 Forbidden` errors

**Solutions**:
1. Verify credentials in device API config
2. Check token expiration (regenerate if needed)
3. Verify network connectivity:
   ```bash
   curl -k -u username:password https://device-api-url/endpoint
   ```
4. Check firewall rules between LibreNMS and device

### Problem: Missing Data (Processors, Mempools, etc.)

**Symptoms**: Device polls successfully but specific metrics missing

**Solutions**:
1. Check if capability is enabled in template:
   ```sql
   SELECT capability, enabled FROM device_api_endpoints WHERE device_id = XX;
   ```

2. Verify endpoint is returning data:
   ```bash
   ./lnms device:poll XX -vv 2>&1 | grep -A 10 "capability_name"
   ```

3. Check normalizer for errors:
   ```bash
   tail -f logs/librenms.log | grep "transform failed"
   ```

### Problem: Performance Issues

**Symptoms**: Polling takes too long, high API load

**Solutions**:
1. Review enabled endpoints (disable unnecessary ones)
2. Increase cache TTLs for static data
3. Disable `for_each` endpoints if looping over many items
4. Check API rate limits on device
5. Monitor API response times:
   ```bash
   ./lnms device:poll XX -vv 2>&1 | grep "DeviceApiExecutor"
   ```

### Problem: Stale Cache Data

**Symptoms**: Data not updating after device changes

**Solutions**:
1. Clear device cache:
   ```bash
   php artisan cache:clear
   # Or device-specific
   php -r 'App\Services\DeviceApiCache::clearDevice(App\Models\Device::find(XX));'
   ```

2. Force discovery poll:
   ```bash
   ./lnms device:poll XX -m device-api
   ```

---

## API Templates Reference

### Template Endpoint Structure

```yaml
capability: capability_name    # What this endpoint provides
method: GET|POST|SOAP|XML     # HTTP method or API type
path: /api/endpoint/{placeholder}  # Endpoint path with placeholders
transform: ClassName::method  # Normalizer function
display_order: 10            # Execution order
enabled: 1|0                # Enable/disable endpoint
for_each: capability        # Optional: loop over discovered items
for_each_options:           # Options for looping
  placeholder: param       # Path placeholder to replace
  value_key: field        # Field to extract from items
  filter_key: field       # Only loop over items with this field
```

### Available Placeholders

| Placeholder | Description | Example |
|------------|-------------|---------|
| `{hostname}` | Device hostname | `node1.example.com` |
| `{device_id}` | LibreNMS device ID | `123` |
| `{node}` | Proxmox node name | `pve1` |
| `{storageid}` | Storage ID (for_each loop) | `local-lvm` |
| `{uuid}` | Port/resource UUID (for_each loop) | `abc-123` |

### Available Capabilities

| Capability | Description | Example Endpoints |
|------------|-------------|-------------------|
| `device_info` | Hardware, serial, version | `/system/info`, `/arrays` |
| `inventory` | Physical components | `/hardware`, `/chassis`, `/nodes` |
| `ports` | Network interfaces | `/interfaces`, `/network-interfaces` |
| `ipv4` | IPv4 addresses | `/ip/interfaces`, `/network` |
| `vlans` | VLAN configuration | `/vlans`, `/network/vlans` |
| `processors` | CPU metrics | `/nodes/{node}/status`, `/statistics` |
| `mempools` | Memory metrics | `/nodes/{node}/status`, `/statistics` |
| `sensors` | Sensors (temp, state, etc.) | `/sensors`, `/performance` |
| `storage` | Datastores/volumes | `/storage`, `/datastores`, `/volumes` |
| `vminfo` | Virtual machines | `/vms`, `/cluster/resources?type=vm` |
| `ports_statistics` | Interface traffic | `/statistics`, `/performance` |
| `clusters` | Cluster information | `/cluster/status` |
| `hypervisor_hosts` | Hypervisor nodes | `/nodes`, `/hosts` |

---

## Best Practices

1. **Start with Discovery**: Run a discovery poll first to populate inventory before enabling statistics endpoints

2. **Monitor API Performance**: Track polling times and adjust endpoints if polls take too long

3. **Use Caching**: Let the system cache static data - don't disable caching unless needed

4. **Test Credentials**: Verify API access works before adding to LibreNMS:
   ```bash
   curl -k -u user:pass https://device-api/endpoint | jq .
   ```

5. **Review Logs**: Check logs after initial setup to catch authentication or parsing errors

6. **Plan for Scale**: If monitoring many devices, consider:
   - Distributing pollers
   - Increasing cache TTLs
   - Disabling verbose logging in production

7. **Keep Templates Updated**: Run seeders after upgrades to get new endpoints:
   ```bash
   php artisan db:seed --class=DeviceApiTemplatesSeeder
   ```

---

## Support and Resources

- **Documentation**: https://docs.librenms.org/
- **GitHub Issues**: https://github.com/librenms/librenms/issues
- **Discord**: https://discord.gg/librenms
- **API Polling Code**: `/app/Services/DeviceApiExecutor.php`
- **Templates**: `/database/seeders/DeviceApiTemplatesSeeder.php`
- **Normalizers**: `/LibreNMS/Modules/Support/RestNormalizers.php`

---

## Changelog

- **2025-12-01**: Added version-based endpoint management, caching, error handling enhancements
- **2025-11-21**: Added VeloCloud processor fix, graph enablement for API devices
- **2025-11-19**: Added Proxmox cluster and hypervisor host support
- **2025-11-18**: Added ESXi SOAP API, Cisco UCS Manager XML API support
- **2025-11-10**: Added NetApp processor/mempool endpoints (9.11+ only)
