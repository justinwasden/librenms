# VeloCloud - Collecting ALL Edge Interfaces

**Date**: December 1, 2025
**Status**: Solution Identified
**Branch**: feat/rest-api-polling

---

## Current Limitation

Currently, the VeloCloud API integration only collects **2 WAN interfaces** (typically GE3 and GE4) via the `monitoring/getAggregateEdgeLinkMetrics` endpoint. This endpoint only returns interfaces that have active WAN links configured.

**Example of current collection:**
- Port 749: GE3 (24.227.64.62/28)
- Port 750: GE4 (142.190.72.70/30)

---

## Solution: Use getEdgeConfigurationStack Endpoint

### API Endpoint Details

**Endpoint**: `/portal/rest/edge/getEdgeConfigurationStack`
**Method**: POST
**Authentication**: Cookie-based session (velocloud.session)

**Request Body**:
```json
{
  "enterpriseId": 1288,
  "edgeId": 23359
}
```

**Response Structure**:
```
Array of configuration stacks (usually 2 elements)
├── Stack 0: Edge-specific configuration
│   └── modules[]
│       └── deviceSettings module
│           ├── routedInterfaces[] - ALL physical/logical interfaces
│           ├── switchedInterfaces[] - Switched port configs
│           └── lan
│               └── networks[] - LAN network definitions
└── Stack 1: Enterprise/Profile configuration
    └── modules[] (similar structure)
```

---

## Available Interface Data

### Complete Interface Inventory (Test Device)

From `getEdgeConfigurationStack`, we discovered **8 routed interfaces**:

| Interface | Type | Addressing | IP Address | CIDR | Status | WAN Overlay |
|-----------|------|------------|------------|------|--------|-------------|
| **GE3** | Physical | STATIC | 24.227.64.62 | /28 | Active | AUTO_DISCOVERED |
| **GE4** | Physical | STATIC | 142.190.72.70 | /30 | Active | AUTO_DISCOVERED |
| **GE5** | Physical | DHCP | - | - | Configured | AUTO_DISCOVERED |
| **GE6** | Physical | DHCP | - | - | Configured | AUTO_DISCOVERED |
| **SFP1** | SFP | DHCP | - | - | Configured | AUTO_DISCOVERED |
| **SFP2** | SFP | DHCP | - | - | Configured | AUTO_DISCOVERED |
| **LAG1** | Link Aggregation | DHCP | - | - | Configured | AUTO_DISCOVERED |
| **LAG2** | Link Aggregation | DHCP | - | - | Configured | AUTO_DISCOVERED |

**Current API only returns**: GE3, GE4 (the 2 active WAN interfaces)
**New endpoint would return**: All 8 interfaces + LAN networks

---

## Interface Data Structure

Each interface in `routedInterfaces[]` contains:

```json
{
  "name": "GE3",
  "disabled": false,
  "addressing": {
    "netmask": "255.255.255.240",
    "type": "STATIC",
    "gateway": "24.227.64.49",
    "cidrIp": "24.227.64.62",
    "cidrPrefix": 28
  },
  "wanOverlay": "AUTO_DISCOVERED",
  "encryptOverlay": true,
  "advertise": false,
  "natDirect": true,
  "pingResponse": true,
  "trusted": false,
  "vlanId": null,
  "underlayAccounting": true,
  "segmentId": -1,
  "l2": {
    "MTU": 1500,
    "autonegotiation": true,
    "speed": "100M",
    "duplex": "FULL",
    "losDetection": false,
    "probeInterval": "3"
  },
  "disableV4": false,
  "disableV6": true,
  "ospf": { ... },
  "multicast": { ... },
  "dhcpServer": { ... }
}
```

### Key Fields for Port Discovery

| Field | Purpose | LibreNMS Mapping |
|-------|---------|------------------|
| `name` | Interface name (GE3, GE4, etc.) | `ifName`, `ifDescr` |
| `disabled` | Admin status | `ifAdminStatus` (up=1, down=2) |
| `addressing.type` | IP addressing mode | Port metadata |
| `addressing.cidrIp` | IPv4 address | `ipv4_addresses` table |
| `addressing.cidrPrefix` | Subnet prefix length | `ipv4_prefixlen` |
| `addressing.gateway` | Default gateway | Network metadata |
| `wanOverlay` | WAN overlay mode | Port metadata |
| `l2.speed` | Interface speed | `ifSpeed` |
| `l2.duplex` | Duplex mode | Port metadata |
| `l2.MTU` | Maximum transmission unit | `ifMtu` |
| `vlanId` | VLAN ID (if subinterface) | VLAN association |

---

## Implementation Plan

### Step 1: Add New API Template Endpoint

Add to `database/seeders/DeviceApiTemplatesSeeder.php`:

```php
[
    'path' => '/portal/rest/edge/getEdgeConfigurationStack',
    'method' => 'POST',
    'capability' => 'ports',
    'description' => 'Collect all edge interfaces (WAN + LAN)',
    'enabled' => true,
    'request_body' => json_encode([
        'enterpriseId' => '{{enterprise_id}}',
        'edgeId' => '{{edge_id}}'
    ]),
    'transform' => 'velocloud_config_stack_to_ports',
],
```

### Step 2: Create Normalizer Function

Add to `LibreNMS/Modules/Support/RestNormalizers.php`:

```php
public static function normalizeVelocloudConfigStackPorts($device, array $payload): array
{
    $ports = [];

    // Get edge-specific config (first stack)
    $edgeConfig = $payload[0] ?? [];

    // Find deviceSettings module
    foreach ($edgeConfig['modules'] ?? [] as $module) {
        if ($module['name'] === 'deviceSettings') {
            $routedInterfaces = $module['data']['routedInterfaces'] ?? [];

            foreach ($routedInterfaces as $idx => $intf) {
                $ifName = $intf['name'] ?? "Interface$idx";
                $addressing = $intf['addressing'] ?? [];
                $l2 = $intf['l2'] ?? [];

                // Map to LibreNMS port structure
                $port = [
                    'ifName' => $ifName,
                    'ifDescr' => $ifName,
                    'ifAlias' => $intf['segmentId'] ?? '',
                    'ifType' => 'ethernetCsmacd',
                    'ifOperStatus' => $intf['disabled'] ? 'down' : 'up',
                    'ifAdminStatus' => $intf['disabled'] ? 'down' : 'up',
                    'ifMtu' => $l2['MTU'] ?? 1500,
                ];

                // Convert speed string to bits/sec
                $speed = $l2['speed'] ?? '1G';
                if (preg_match('/(\d+)([MG])/', $speed, $matches)) {
                    $value = (int)$matches[1];
                    $unit = $matches[2];
                    $port['ifSpeed'] = $unit === 'G' ? ($value * 1000000000) : ($value * 1000000);
                }

                $ports[] = $port;
            }

            break;
        }
    }

    return $ports;
}
```

### Step 3: Create IPv4 Address Normalizer

```php
public static function normalizeVelocloudConfigStackIpv4($device, array $payload): array
{
    $addresses = [];

    // Get edge-specific config (first stack)
    $edgeConfig = $payload[0] ?? [];

    // Find deviceSettings module
    foreach ($edgeConfig['modules'] ?? [] as $module) {
        if ($module['name'] === 'deviceSettings') {
            $routedInterfaces = $module['data']['routedInterfaces'] ?? [];

            foreach ($routedInterfaces as $intf) {
                $ifName = $intf['name'] ?? null;
                $addressing = $intf['addressing'] ?? [];

                // Only process interfaces with static IPs or current DHCP leases
                if ($addressing['type'] === 'STATIC' && !empty($addressing['cidrIp'])) {
                    $addresses[] = [
                        'ipv4_address' => $addressing['cidrIp'],
                        'ipv4_prefixlen' => $addressing['cidrPrefix'] ?? 24,
                        'ipv4_network_id' => null,
                        'ifName' => $ifName,
                    ];
                }
            }

            break;
        }
    }

    return $addresses;
}
```

### Step 4: Add Transform Mapping

In `LibreNMS/Util/TransformRunner.php`, add:

```php
'velocloud_config_stack_to_ports' => function($device, $payload, $endpoint) {
    return RestNormalizers::normalizeVelocloudConfigStackPorts($device, $payload);
},
'velocloud_config_stack_to_ipv4' => function($device, $payload, $endpoint) {
    return RestNormalizers::normalizeVelocloudConfigStackIpv4($device, $payload);
},
```

### Step 5: Update Device API Template

Modify the VeloCloud template in the seeder to include both endpoints:

```php
[
    'path' => '/portal/rest/edge/getEdgeConfigurationStack',
    'method' => 'POST',
    'capability' => 'ports',
    'description' => 'Collect all edge interfaces',
    'enabled' => true,
    'request_body' => json_encode([
        'enterpriseId' => '{{enterprise_id}}',
        'edgeId' => '{{edge_id}}'
    ]),
    'transform' => 'velocloud_config_stack_to_ports',
],
[
    'path' => '/portal/rest/edge/getEdgeConfigurationStack',
    'method' => 'POST',
    'capability' => 'ipv4_addresses',
    'description' => 'Collect IPv4 addresses from config',
    'enabled' => true,
    'request_body' => json_encode([
        'enterpriseId' => '{{enterprise_id}}',
        'edgeId' => '{{edge_id}}'
    ]),
    'transform' => 'velocloud_config_stack_to_ipv4',
],
```

---

## Benefits of This Approach

### 1. Complete Interface Inventory
- Discovers **all** physical interfaces (GE1-8, SFP1-2)
- Includes **logical interfaces** (LAG1-2)
- Shows **disabled/unused** interfaces
- Displays **DHCP-configured** interfaces

### 2. Better Network Visibility
- See complete edge device hardware
- Identify unused ports
- Track interface configuration changes
- Monitor all WAN and LAN interfaces

### 3. Configuration-Based Discovery
- No dependency on active link metrics
- Works even if interface is down
- Captures interface intent (configured but not active)
- More reliable than metrics-based discovery

### 4. Additional Metadata
- Interface speed/duplex settings
- MTU configuration
- OSPF/routing settings
- VLAN assignments
- WAN overlay configuration

---

## Comparison: Current vs New Approach

### Current Approach
**Endpoint**: `monitoring/getAggregateEdgeLinkMetrics`
- ✅ Provides real-time traffic statistics
- ✅ Shows link quality metrics
- ❌ **Only returns active WAN links** (2 interfaces)
- ❌ Misses LAN interfaces
- ❌ Misses disabled/standby interfaces
- ❌ No configuration metadata

### New Approach
**Endpoint**: `edge/getEdgeConfigurationStack`
- ✅ **Returns ALL interfaces** (8+ interfaces)
- ✅ Includes WAN and LAN interfaces
- ✅ Shows disabled/standby interfaces
- ✅ Provides configuration metadata
- ✅ Interface speed/duplex/MTU
- ❌ No real-time traffic statistics
- ❌ Larger response payload (~367KB vs ~5KB)

### Recommended Solution: **Use Both**
1. **Config Stack** (`getEdgeConfigurationStack`) for port **discovery**
2. **Link Metrics** (`getAggregateEdgeLinkMetrics`) for port **statistics**

This provides:
- Complete interface inventory (discovery)
- Real-time traffic data (statistics)
- Best of both worlds

---

## Testing Commands

### Test Config Stack Endpoint
```bash
php /opt/librenms/test_velocloud_interfaces2.php
```

### Test Full Interface Data
```bash
php /opt/librenms/test_velocloud_interfaces3.php
```

### Verify Current Port Collection
```bash
./lnms device:poll 33 -m ports
```

---

## Implementation Timeline

### Phase 1: Add Config Stack Discovery (Recommended)
1. Create normalizer functions (30 minutes)
2. Add template endpoints (15 minutes)
3. Add transform mappings (15 minutes)
4. Test on device 33 (30 minutes)
5. Verify port discovery (15 minutes)

**Total**: ~2 hours

### Phase 2: Keep Link Metrics for Statistics (Already Working)
- No changes needed
- Continue collecting traffic stats from current endpoint

### Phase 3: Integration Testing
1. Run discovery on test device
2. Verify all 8 interfaces appear
3. Confirm traffic stats still working
4. Check IPv4 address linking
5. Validate graphs displaying

**Total**: ~1 hour

---

## Expected Results After Implementation

### Before
```
Device 33 Ports:
  - Port 749: GE3 (24.227.64.62/28)
  - Port 750: GE4 (142.190.72.70/30)

Total: 2 ports
```

### After
```
Device 33 Ports:
  - Port 749: GE3 (24.227.64.62/28) - UP, 100 Mbps
  - Port 750: GE4 (142.190.72.70/30) - UP, 100 Mbps
  - Port 751: GE5 - DOWN, DHCP
  - Port 752: GE6 - DOWN, DHCP
  - Port 753: SFP1 - DOWN, DHCP
  - Port 754: SFP2 - DOWN, DHCP
  - Port 755: LAG1 - DOWN, DHCP
  - Port 756: LAG2 - DOWN, DHCP

Total: 8 ports
```

**Plus**:
- Real-time traffic statistics on GE3/GE4 (from link metrics endpoint)
- Configuration metadata on all ports (from config stack endpoint)
- Complete network topology visibility

---

## API Reference

### getEdgeConfigurationStack

**Full Path**: `https://vco124-usca1.velocloud.net/portal/rest/edge/getEdgeConfigurationStack`

**Request**:
```json
POST /portal/rest/edge/getEdgeConfigurationStack
Content-Type: application/json
Cookie: velocloud.session=<session_token>

{
  "enterpriseId": 1288,
  "edgeId": 23359
}
```

**Response**: Array of configuration stacks (2 elements)
- Stack 0: Edge-specific configuration
- Stack 1: Enterprise/Profile configuration

**Key Data Paths**:
- `[0].modules[].name == "deviceSettings"`
  - `.data.routedInterfaces[]` - All routed interfaces
  - `.data.switchedInterfaces[]` - All switched interfaces
  - `.data.lan.networks[]` - LAN network definitions
  - `.data.segments[]` - Network segments

**Response Size**: ~367KB (contains full configuration)

**Cache Recommendation**: 5 minutes (configuration doesn't change frequently)

---

## Conclusion

**Solution Found**: ✅ The `edge/getEdgeConfigurationStack` endpoint provides complete interface inventory.

**Next Steps**:
1. Implement the normalizer functions
2. Add template endpoints
3. Test on device 33
4. Validate results

**Impact**: This will provide **4x more interface visibility** (2 → 8+ interfaces) and complete network topology for VeloCloud SD-WAN edges.
