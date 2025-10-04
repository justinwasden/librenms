# REST API Vendor Overviews - Quick Start Guide

## 🚀 5-Minute Quick Start

### What You Have
**8 vendor-specific REST API overview pages** ready to use immediately:
- PureStorage, Palo Alto, Cisco, Fortinet, Juniper, TrueNAS, Arista + Generic

### Deployment (3 commands)

```bash
# 1. Navigate to LibreNMS directory
cd /Users/justinwasden/Documents/GitHub/librenms

# 2. Clear caches
php artisan optimize:clear

# 3. Done! Visit your device overview page
```

That's it! The overview pages will automatically appear for devices with REST API enabled.

---

## 📋 Vendor-Specific Cheat Sheet

### PureStorage FlashArray
**Shows:** Array capacity, volumes, hosts, network interfaces  
**OS Match:** `purestorage`  
**Key Panel:** Capacity bar with data reduction ratio

### Palo Alto Networks
**Shows:** Session utilization, security policies, threats  
**OS Match:** `panos`  
**Key Panel:** Top security policies by hit count

### Cisco IOS/IOS-XE/NX-OS
**Shows:** CPU/Memory, interfaces, routing, sensors  
**OS Match:** `ios`, `iosxe`, `nxos`  
**Key Panel:** Top 20 interfaces with status

### Fortinet FortiGate
**Shows:** System health, VPN tunnels, policies, IPS  
**OS Match:** `fortios`, `fortigate`  
**Key Panel:** VPN tunnel status and traffic

### Juniper Networks
**Shows:** RE health, BGP peers, FPCs, interfaces  
**OS Match:** `junos`  
**Key Panel:** BGP peer status and routes

### TrueNAS
**Shows:** Pools, datasets, shares, replication  
**OS Match:** `truenas`  
**Key Panel:** Storage pools with health

### Arista EOS
**Shows:** MLAG, port channels, VLANs, interfaces  
**OS Match:** `eos`, `arista`  
**Key Panel:** MLAG status

### Generic (Fallback)
**Shows:** Auto-discovered metrics for any device  
**OS Match:** *any other OS*  
**Key Panel:** Dynamic tables

---

## ✅ Verification (1 minute)

```bash
# Check if metrics exist
php artisan tinker --execute="
DB::table('device_api_metrics')
  ->where('device_id', 1)
  ->count()
"

# If 0, run REST API polling
php lnms device:poll 1 -m rest-api -vv
```

---

## 🔧 Common Tasks

### Change Device to Use Specific Vendor

```sql
# Update device OS
UPDATE devices 
SET os = 'panos' 
WHERE device_id = 1;
```

### Add New Vendor (5 minutes)

```bash
# 1. Copy generic template
cp generic.inc.php netapp.inc.php

# 2. Edit queries for your vendor
nano netapp.inc.php

# 3. Set device OS to match
UPDATE devices SET os = 'netapp' WHERE device_id = 1;
```

### Troubleshoot Missing Panels

```bash
# Check REST API enabled
mysql librenms -e "
SELECT device_id, enabled, base_url 
FROM rest_api_connections 
WHERE device_id = 1
"

# Check device OS
mysql librenms -e "
SELECT device_id, hostname, os 
FROM devices 
WHERE device_id = 1
"

# Verify vendor file exists
ls -la includes/html/pages/device/overview/rest-api/[os].inc.php
```

---

## 📖 Documentation Quick Links

| Need | Read This |
|------|-----------|
| **Vendor Details** | `REST_API_VENDOR_OVERVIEWS_GUIDE.md` |
| **Code Snippets** | `REST_API_OVERVIEW_QUICK_REFERENCE.md` |
| **Full Architecture** | `REST_API_OVERVIEW_IMPLEMENTATION.md` |
| **Testing Steps** | `REST_API_OVERVIEW_CHECKLIST.md` |
| **Complete Summary** | `REST_API_VENDOR_IMPLEMENTATION_SUMMARY.md` |

---

## 🎯 File Locations

```
/includes/html/pages/device/overview/rest-api/
├── purestorage.inc.php    # PureStorage
├── panos.inc.php          # Palo Alto
├── ios.inc.php            # Cisco
├── fortios.inc.php        # Fortinet
├── junos.inc.php          # Juniper
├── truenas.inc.php        # TrueNAS
├── eos.inc.php            # Arista
└── generic.inc.php        # Generic fallback
```

---

## 💡 Pro Tips

1. **OS Name Must Match Filename** (lowercase)
   - Device OS: `panos` → Loads: `panos.inc.php`
   - Device OS: `cisco` → Loads: `generic.inc.php` (no cisco.inc.php exists)

2. **Multiple OS Names? Use Symlink**
   ```bash
   ln -s fortios.inc.php fortigate.inc.php
   ```

3. **Generic Fallback Always Works**
   - If no vendor-specific file exists, `generic.inc.php` loads
   - Auto-discovers and displays all metrics

4. **Clear Caches After Changes**
   ```bash
   php artisan optimize:clear
   ```

---

## 🚨 Quick Troubleshooting

### Problem: No panels appear
**Fix:** Enable REST API connection for device

### Problem: Empty tables
**Fix:** Run REST API polling to collect metrics
```bash
php lnms device:poll 1 -m rest-api -vv
```

### Problem: Wrong vendor page loads
**Fix:** Check device OS matches vendor filename
```bash
mysql librenms -e "SELECT os FROM devices WHERE device_id=1"
```

### Problem: PHP errors
**Fix:** Check logs
```bash
tail -f /opt/librenms/storage/logs/laravel.log
```

---

## ✨ That's It!

You now have complete REST API overview pages for all major vendors. Just ensure:
- ✅ REST API is enabled for devices
- ✅ Metrics are being collected
- ✅ Device OS matches vendor file (or uses generic)

**Everything else is automatic!**

---

**Status:** ✅ Ready to Use  
**Total Files:** 15 (10 PHP + 5 docs)  
**Vendors Supported:** 8  
**Setup Time:** 5 minutes  
**Works With:** Any LibreNMS installation
