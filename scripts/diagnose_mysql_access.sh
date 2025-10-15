#!/bin/bash
#
# Diagnose and Fix .my.cnf Issues
#

echo "============================================"
echo "LibreNMS .my.cnf Diagnostic"
echo "============================================"
echo ""

echo "Step 1: Checking .my.cnf locations"
echo "============================================"
echo ""

# Check all possible locations
LOCATIONS=(
    "/root/.my.cnf"
    "/opt/librenms/.my.cnf"
    "/home/librenms/.my.cnf"
    "~librenms/.my.cnf"
)

for loc in "${LOCATIONS[@]}"; do
    if [ -f "$loc" ]; then
        echo "✓ Found: $loc"
        ls -la "$loc"
        echo "Content:"
        cat "$loc"
        echo ""
    else
        echo "✗ Not found: $loc"
    fi
done

echo ""
echo "Step 2: Checking MariaDB config issue"
echo "============================================"
echo ""

# The error mentions /etc/mysql/mariadb.conf.d/50-server.cnf
if [ -f /etc/mysql/mariadb.conf.d/50-server.cnf ]; then
    echo "First few lines of 50-server.cnf:"
    head -5 /etc/mysql/mariadb.conf.d/50-server.cnf
    echo ""
    
    # Check if file starts with a group
    FIRST_LINE=$(head -1 /etc/mysql/mariadb.conf.d/50-server.cnf)
    if [[ ! "$FIRST_LINE" =~ ^\[.*\] ]]; then
        echo "⚠️  WARNING: Config file doesn't start with a group (e.g., [mysqld])"
        echo "First line is: $FIRST_LINE"
        echo ""
    fi
fi

echo ""
echo "Step 3: Testing MySQL access methods"
echo "============================================"
echo ""

# Get password from config.php if it exists
if [ -f /opt/librenms/config.php ]; then
    DB_PASS=$(grep "^\$config\['db_pass'\]" /opt/librenms/config.php | sed "s/\$config\['db_pass'\] = '\(.*\)';/\1/" | sed "s/\$config\['db_pass'\] = \"\(.*\)\";/\1/")
    echo "Password found in config.php: ${DB_PASS:0:3}***"
    echo ""
fi

# Test 1: With password on command line
echo "Test 1: mysql -u librenms -p'***' librenms"
if [ -n "$DB_PASS" ]; then
    mysql -u librenms -p"$DB_PASS" librenms -e "SELECT 1 as test;" 2>&1 | head -5
    if [ $? -eq 0 ]; then
        echo "✓ Direct password works"
    else
        echo "✗ Direct password failed"
    fi
else
    echo "Skipped - no password in config.php"
fi

echo ""

# Test 2: As librenms user with .my.cnf
echo "Test 2: As librenms user with .my.cnf"
sudo -u librenms mysql librenms -e "SELECT 1 as test;" 2>&1 | head -5
if [ $? -eq 0 ]; then
    echo "✓ librenms user with .my.cnf works"
else
    echo "✗ librenms user with .my.cnf failed"
fi

echo ""

# Test 3: Current user
echo "Test 3: Current user ($(whoami))"
mysql librenms -e "SELECT 1 as test;" 2>&1 | head -5
if [ $? -eq 0 ]; then
    echo "✓ Current user works"
else
    echo "✗ Current user failed"
fi

echo ""
echo "============================================"
echo "Recommended Fix"
echo "============================================"
echo ""

if [ -z "$DB_PASS" ]; then
    echo "❌ Cannot find password in config.php"
    echo ""
    echo "Please run:"
    echo "  grep db_pass /opt/librenms/config.php"
    echo ""
    exit 1
fi

echo "Creating correct .my.cnf file..."
echo ""

# Create .my.cnf in librenms home directory
cat > /opt/librenms/.my.cnf << EOF
[client]
user=librenms
password=$DB_PASS
host=localhost

[mysql]
user=librenms
password=$DB_PASS
host=localhost
EOF

chmod 600 /opt/librenms/.my.cnf
chown librenms:librenms /opt/librenms/.my.cnf

echo "✓ Created /opt/librenms/.my.cnf"
echo ""

# Also create for root if running scripts as root
if [ -f /root/.my.cnf ]; then
    echo "Backing up existing /root/.my.cnf..."
    mv /root/.my.cnf /root/.my.cnf.backup.$(date +%Y%m%d_%H%M%S)
fi

cat > /root/.my.cnf << EOF
[client]
user=librenms
password=$DB_PASS
host=localhost
database=librenms

[mysql]
user=librenms
password=$DB_PASS
host=localhost
database=librenms
EOF

chmod 600 /root/.my.cnf

echo "✓ Created /root/.my.cnf"
echo ""

echo "Testing new configuration..."
echo ""

# Test as librenms user
echo "Test as librenms user:"
sudo -u librenms mysql librenms -e "SELECT COUNT(*) as devices FROM devices;" 2>&1
LIBRENMS_TEST=$?

echo ""

# Test as root
echo "Test as root:"
mysql librenms -e "SELECT COUNT(*) as devices FROM devices;" 2>&1
ROOT_TEST=$?

echo ""
echo "============================================"
echo "Results"
echo "============================================"
echo ""

if [ $LIBRENMS_TEST -eq 0 ] && [ $ROOT_TEST -eq 0 ]; then
    echo "✅ SUCCESS! MySQL access is working for both users"
    echo ""
    echo "You can now run:"
    echo "  mysql librenms"
    echo "  ./scripts/debug_discovery.sh 172.16.7.40"
    echo ""
elif [ $LIBRENMS_TEST -eq 0 ]; then
    echo "⚠️  PARTIAL: librenms user works, but root doesn't"
    echo ""
    echo "This is OK for most operations. Run scripts as:"
    echo "  sudo -u librenms ./scripts/debug_discovery.sh 172.16.7.40"
    echo ""
elif [ $ROOT_TEST -eq 0 ]; then
    echo "⚠️  PARTIAL: root works, but librenms user doesn't"
    echo ""
    echo "Check permissions on /opt/librenms/.my.cnf"
    echo "  ls -la /opt/librenms/.my.cnf"
    echo ""
else
    echo "❌ FAILED: Neither user can connect"
    echo ""
    echo "Please check:"
    echo "  1. Password in config.php is correct"
    echo "  2. MySQL user exists: sudo mysql -u root -e \"SELECT user,host FROM mysql.user WHERE user='librenms';\""
    echo "  3. User has correct password: sudo mysql -u root -e \"ALTER USER 'librenms'@'localhost' IDENTIFIED BY 'password';\""
    echo ""
fi

echo ""
echo "Files created:"
echo "  /opt/librenms/.my.cnf (for librenms user)"
echo "  /root/.my.cnf (for root user)"
echo ""
