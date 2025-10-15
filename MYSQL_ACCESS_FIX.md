# Fix LibreNMS MySQL Access - Quick Guide

## Problem
```
ERROR 1045 (28000): Access denied for user 'librenms'@'localhost' (using password: NO)
```

This means the librenms user doesn't have a password configured for MySQL access.

## Quick Fix

### Option 1: Automated Script (Recommended)
```bash
cd /opt/librenms
chmod +x scripts/fix_mysql_access.sh
sudo ./scripts/fix_mysql_access.sh
```

Then choose option 1 to set a password.

### Option 2: Manual Fix

**Step 1: Set the database password**
```bash
# Login to MySQL as root
sudo mysql -u root

# Set password for librenms user
ALTER USER 'librenms'@'localhost' IDENTIFIED BY 'your_secure_password_here';
FLUSH PRIVILEGES;
EXIT;
```

**Step 2: Update config.php**
```bash
sudo nano /opt/librenms/config.php
```

Find and update this line:
```php
$config['db_pass'] = 'your_secure_password_here';
```

**Step 3: Create .my.cnf file**
```bash
sudo nano /opt/librenms/.my.cnf
```

Add this content (replace password):
```ini
[client]
user=librenms
password=your_secure_password_here
host=localhost
```

**Step 4: Set permissions**
```bash
sudo chmod 600 /opt/librenms/.my.cnf
sudo chown librenms:librenms /opt/librenms/.my.cnf
```

**Step 5: Test connection**
```bash
sudo -u librenms mysql librenms -e "SELECT COUNT(*) FROM devices;"
```

Should show device count, no errors.

## What Each File Does

### `/opt/librenms/.my.cnf`
- Allows passwordless MySQL access for the librenms user
- Used by scripts and cron jobs
- Must be readable only by librenms user (600 permissions)

### `/opt/librenms/config.php`
- Main LibreNMS configuration
- Contains database credentials used by web interface
- Contains `$config['db_pass']` setting

## After Fixing

Once MySQL access is working, you can run the Pure Storage debug script:

```bash
cd /opt/librenms
./scripts/debug_discovery.sh 172.16.7.40
```

## Troubleshooting

### Test if MySQL user exists:
```bash
sudo mysql -u root -e "SELECT user, host FROM mysql.user WHERE user='librenms';"
```

Should show:
```
+-----------+-----------+
| user      | host      |
+-----------+-----------+
| librenms  | localhost |
+-----------+-----------+
```

### Test connection with password:
```bash
mysql -u librenms -p librenms
# Enter password when prompted
```

### Check current config.php password:
```bash
grep "db_pass" /opt/librenms/config.php
```

### Check .my.cnf exists:
```bash
ls -la /opt/librenms/.my.cnf
```

Should show:
```
-rw------- 1 librenms librenms 60 DATE /opt/librenms/.my.cnf
```

### If .my.cnf has wrong permissions:
```bash
sudo chmod 600 /opt/librenms/.my.cnf
sudo chown librenms:librenms /opt/librenms/.my.cnf
```

## Security Notes

- Use a strong password (mix of letters, numbers, symbols)
- Never commit .my.cnf to git
- Keep .my.cnf permissions at 600 (read/write for owner only)
- Password in config.php should match .my.cnf password

## Common Mistakes

❌ **Wrong**: Password only in config.php (scripts won't work)
✅ **Right**: Password in BOTH config.php and .my.cnf

❌ **Wrong**: .my.cnf has 644 permissions (security risk)
✅ **Right**: .my.cnf has 600 permissions

❌ **Wrong**: Passwords don't match between files
✅ **Right**: Same password in config.php and .my.cnf

## Next Steps

After fixing MySQL access:

1. Test the fix:
   ```bash
   mysql librenms -e "SELECT hostname FROM devices WHERE os='purestorage';"
   ```

2. Run the debug discovery:
   ```bash
   ./scripts/debug_discovery.sh 172.16.7.40
   ```

3. Proceed with Pure Storage ports fix
