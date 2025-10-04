# PureStorage Device Detection - Troubleshooting Guide

## 🔍 Problem: PureStorage devices not being identified correctly

### ✅ Files Created/Updated

I've created the following files to enable proper PureStorage detection:

1. **✅ OS Detection File** (UPDATED)
   - Location: `/resources/definitions/os_detection/purestorage.yaml`
   - Contains: Discovery rules, SNMP OID matching, graph definitions
   - Key Changes: Added `sysObjectID` and `sysDescr_regex` rules

2. **✅ OS Discovery File** (exists)
   - Location: `/resources/definitions/os_discovery/purestorage.yaml`  
   - Contains: Hardware/version/serial discovery via ENTITY-MIB

3. **✅ OS Class File** (NEW)
   - Location: `/LibreNMS/OS/Purestorage.php`
   - Purpose: Custom OS logic (currently minimal as REST API handles metrics)

4. **✅ MIB File** (exists)
   - Location: `/mibs/PURESTORAGE-MIB`
   - Enterprise OID: `.1.3.6.1.4.1.40482`

## 🔧 Detection Configuration

### OS Detection Rules (purestorage.yaml)

```yaml
discovery:
    -
        sysObjectID:
            - .1.3.6.1.4.1.40482    # Pure Storage enterprise OID
        sysDescr_regex:
            - '/Pure.*Storage/i'
            - '/FlashArray/i'
            - '/Purity/i'
    -
        sysDescr_regex:
            - '/Purity.*Operating.*Environment/i'
```

**What this means:**
- Device will match if sysObjectID starts with `.1.3.6.1.4.1.40482` 
- OR if sysDescr contains "Pure Storage", "FlashArray", or "Purity"

## 📊 Testing Detection

### Step 1: Check SNMP Response from PureStorage

```bash
# Check sysObjectID
snmpget -v2c -c public 172.16.7.5 SNMPv2-MIB::sysObjectID.0

# Check sysDescr  
snmpget -v2c -c public 172.16.7.5 SNMPv2-MIB::sysDescr.0

# Check Pure Storage specific OIDs
snmpget -v2c -c public 172.16.7.5 PURESTORAGE-MIB::pureProductName.0
snmpget -v2c -c public 172.16.7.5 PURESTORAGE-MIB::pureProductVersion.0
snmpget -v2c -c public 172.16.7.5 PURESTORAGE-MIB::pureHost.0
```

### Step 2: Manual Discovery Test

```bash
cd /opt/librenms

# Run discovery with debug
./discovery.php -h <device_id> -d -m os

# Look for lines like:
# "Matched PureStorage detection rule"
# "OS: purestorage"
```

### Step 3: Check Current OS Assignment

```bash
# Check what OS LibreNMS thinks the device is
mysql librenms -e "SELECT device_id, hostname, os, sysDescr, sysObjectID FROM devices WHERE hostname LIKE '%purestorage%' OR hostname LIKE '%pure%'"
```

## 🛠️ Force Re-Discovery

If the device is already added but detected as wrong OS:

```bash
# Method 1: Force OS re-discovery
./discovery.php -h <device_id> -m os

# Method 2: Delete and re-add device
./delhost.php <hostname>
./addhost.php <hostname> <community> v2c

# Method 3: Manual OS update (if you're sure)
mysql librenms -e "UPDATE devices SET os='purestorage' WHERE device_id=<id>"
./discovery.php -h <device_id>
```

## 📝 What PureStorage Returns via SNMP

Based on the MIB, PureStorage devices should return:

**sysObjectID:** `.1.3.6.1.4.1.40482.*`

**sysDescr:** Something like:
- "Purity Operating Environment"
- "Pure Storage FlashArray"
- "FlashArray//X90R2"

**Available SNMP OIDs:**
- `pureProductName` - Product name (e.g., "FlashArray//X90R2")
- `pureProductVersion` - Software version (e.g., "6.3.4")
- `pureHost` - Hostname
- `pureArrayReadBandwidth` - Read bandwidth in B/s
- `pureArrayWriteBandwidth` - Write bandwidth in B/s  
- `pureArrayReadIOPS` - Read IOPS
- `pureArrayWriteIOPS` - Write IOPS
- `pureArrayReadLatency` - Read latency in us/op
- `pureArrayWriteLatency` - Write latency in us/op

## 🔎 Debugging Steps

### 1. Verify MIB is Loaded

```bash
# Check if PURESTORAGE-MIB is in LibreNMS
ls -la mibs/PURESTORAGE-MIB

# Check MIB directory configuration
grep -r "mib_dir" resources/definitions/os_detection/purestorage.yaml
```

### 2. Check Detection File Syntax

```bash
# Validate YAML syntax
php -r "yaml_parse_file('resources/definitions/os_detection/purestorage.yaml');"

# Or use yq/jq
cat resources/definitions/os_detection/purestorage.yaml | grep -A 5 "discovery:"
```

### 3. Enable Debug Logging

```bash
# In .env file
APP_DEBUG=true
LOG_LEVEL=debug

# Run discovery with verbose output
./discovery.php -h <device_id> -d -m os 2>&1 | tee purestorage_discovery.log
```

### 4. Check Detection Matching

```bash
# View detection logic
cat resources/definitions/os_detection/purestorage.yaml | grep -A 10 "discovery:"

# Test regex matching (if sysDescr is known)
php -r "
\$sysDescr = 'Purity Operating Environment';
\$patterns = ['/Pure.*Storage/i', '/FlashArray/i', '/Purity/i'];
foreach (\$patterns as \$pattern) {
    echo \$pattern . ': ' . (preg_match(\$pattern, \$sysDescr) ? 'MATCH' : 'NO MATCH') . PHP_EOL;
}
"
```

## 🎯 Common Issues & Fixes

### Issue 1: Device detected as "generic" or "linux"

**Cause:** sysObjectID doesn't match PureStorage OID

**Fix:**
1. Check actual sysObjectID: `snmpget ... sysObjectID.0`
2. Add that OID to purestorage.yaml discovery rules
3. Re-run discovery

### Issue 2: SNMP community/credentials incorrect

**Cause:** Can't query SNMP data

**Fix:**
```bash
# Test SNMP connectivity
snmpwalk -v2c -c <community> <ip> system

# Update SNMP credentials in LibreNMS
./lnms device:update <device_id> --v2c-community=<new_community>
```

### Issue 3: MIB not found errors

**Cause:** PURESTORAGE-MIB not in correct location

**Fix:**
```bash
# Check MIB file exists
ls -la mibs/purestorage/PURESTORAGE-MIB

# Create directory if needed
mkdir -p mibs/purestorage
cp PURESTORAGE-MIB mibs/purestorage/

# Update yaml to point to MIB directory
mib_dir:
    - purestorage
```

### Issue 4: Device detected but no graphs

**Cause:** Poller modules disabled or no data collected

**Fix:**
1. Check poller modules are enabled:
```bash
./lnms device:poll <device_id> -m os -vv
```

2. Check if SNMP OIDs return data:
```bash
snmpget ... PURESTORAGE-MIB::pureArrayReadBandwidth.0
snmpget ... PURESTORAGE-MIB::pureArrayReadIOPS.0
```

3. If SNMP doesn't work, use REST API instead (already configured)

## 📊 CPU/Memory Note

**Important:** PureStorage FlashArray does NOT expose CPU or Memory metrics via SNMP. 

This is intentional and by design. The OS detection file has:
```yaml
poller_modules:
    processors: false  # No CPU polling
discovery_modules:
    mempools: false  # No memory polling
    processors: false  # No CPU discovery
```

**Solution:** Use REST API for system metrics (already implemented in your setup)

## ✅ Verification Checklist

After making changes, verify:

- [ ] Run: `./discovery.php -h <device_id> -m os -d`
- [ ] Check: `SELECT os FROM devices WHERE device_id=<id>` returns "purestorage"
- [ ] Verify: Device overview shows PureStorage-specific panels
- [ ] Confirm: REST API metrics are being collected
- [ ] Test: Graphs are available (bandwidth, IOPS, latency)

## 🔧 Quick Fix Commands

```bash
# 1. Clear caches
php artisan config:clear
php artisan cache:clear

# 2. Force OS re-detection
./discovery.php -h <device_id> -m os

# 3. Check OS assignment
mysql librenms -e "SELECT device_id, hostname, os FROM devices WHERE device_id=<id>"

# 4. If still wrong, manual override
mysql librenms -e "UPDATE devices SET os='purestorage' WHERE device_id=<id>"

# 5. Run full discovery
./discovery.php -h <device_id>

# 6. Test REST API overview
# Visit: http://your-librenms/device/device=<id>/tab=overview/
```

## 📋 Expected Behavior

When working correctly:

1. **Device Discovery:**
   - OS detected as "purestorage"
   - Icon shows PureStorage logo
   - Type shown as "storage"

2. **Overview Page:**
   - Shows "PureStorage FlashArray" header
   - REST API metrics panels appear
   - Array capacity with data reduction
   - Volume IOPS table
   - Host connections
   - Network interfaces

3. **Graphs:**
   - Array Bandwidth graph available
   - Array IOPS graph available  
   - Array Latency graph available
   - Per-volume graphs (if polled via SNMP)

## 🆘 Still Not Working?

### Collect Debug Information

```bash
# Run this script and share output
#!/bin/bash
echo "=== PureStorage Detection Debug ===" > purestorage_debug.txt
echo "" >> purestorage_debug.txt

echo "1. Device Info:" >> purestorage_debug.txt
mysql librenms -e "SELECT * FROM devices WHERE device_id=<id>\G" >> purestorage_debug.txt

echo "" >> purestorage_debug.txt
echo "2. SNMP sysDescr:" >> purestorage_debug.txt
snmpget -v2c -c public <ip> sysDescr.0 >> purestorage_debug.txt

echo "" >> purestorage_debug.txt
echo "3. SNMP sysObjectID:" >> purestorage_debug.txt
snmpget -v2c -c public <ip> sysObjectID.0 >> purestorage_debug.txt

echo "" >> purestorage_debug.txt
echo "4. Detection File:" >> purestorage_debug.txt
cat resources/definitions/os_detection/purestorage.yaml >> purestorage_debug.txt

echo "" >> purestorage_debug.txt
echo "5. Discovery Output:" >> purestorage_debug.txt
./discovery.php -h <device_id> -m os -d >> purestorage_debug.txt 2>&1

cat purestorage_debug.txt
```

### Key Information to Provide

- LibreNMS version
- PureStorage model and Purity version
- Output of `snmpget` commands
- Discovery debug output
- Contents of `devices` table for this device

---

## 📝 Summary

**Files Updated:**
1. ✅ `/resources/definitions/os_detection/purestorage.yaml` - Added proper detection rules
2. ✅ `/LibreNMS/OS/Purestorage.php` - Created OS class
3. ✅ `/mibs/PURESTORAGE-MIB` - Already exists
4. ✅ `/resources/definitions/os_discovery/purestorage.yaml` - Already exists

**Next Steps:**
1. Run `./discovery.php -h <device_id> -m os -d`
2. Check if OS is now "purestorage"
3. Visit device overview page to see REST API panels

The detection should now work correctly!
