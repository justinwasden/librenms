#!/bin/bash
#
# Fix LibreNMS MySQL Access
# This script helps configure the database password for LibreNMS
#

echo "============================================"
echo "LibreNMS MySQL Access Fix"
echo "============================================"
echo ""

# Check if running as root
if [ "$EUID" -ne 0 ]; then 
    echo "Please run as root (sudo)"
    exit 1
fi

echo "Step 1: Check current database configuration"
echo "============================================"
echo ""

# Check if .my.cnf exists
if [ -f /opt/librenms/.my.cnf ]; then
    echo "Found existing .my.cnf file"
    cat /opt/librenms/.my.cnf
    echo ""
else
    echo "No .my.cnf file found"
    echo ""
fi

# Check config.php for database password
if [ -f /opt/librenms/config.php ]; then
    echo "Database config in config.php:"
    grep -A 5 "^\$config\['db" /opt/librenms/config.php | grep -v "^--"
    echo ""
fi

echo ""
echo "Step 2: What would you like to do?"
echo "============================================"
echo "1. Set/Reset LibreNMS database password"
echo "2. Create .my.cnf file for passwordless access"
echo "3. Test current database connection"
echo "4. Exit"
echo ""
read -p "Enter your choice (1-4): " choice

case $choice in
    1)
        echo ""
        echo "Setting LibreNMS database password..."
        echo "============================================"
        echo ""
        
        read -p "Enter new password for 'librenms' database user: " -s DB_PASS
        echo ""
        read -p "Confirm password: " -s DB_PASS_CONFIRM
        echo ""
        
        if [ "$DB_PASS" != "$DB_PASS_CONFIRM" ]; then
            echo "Passwords do not match!"
            exit 1
        fi
        
        if [ -z "$DB_PASS" ]; then
            echo "Password cannot be empty!"
            exit 1
        fi
        
        echo ""
        echo "Updating database user password..."
        
        # Update MySQL user password
        mysql -u root << EOF
ALTER USER 'librenms'@'localhost' IDENTIFIED BY '${DB_PASS}';
FLUSH PRIVILEGES;
EOF
        
        if [ $? -eq 0 ]; then
            echo "✓ Database password updated successfully"
        else
            echo "✗ Failed to update database password"
            echo "Try running manually:"
            echo "  mysql -u root"
            echo "  ALTER USER 'librenms'@'localhost' IDENTIFIED BY 'your_password';"
            exit 1
        fi
        
        # Update config.php
        echo ""
        echo "Updating config.php..."
        
        if [ -f /opt/librenms/config.php ]; then
            # Backup config.php
            cp /opt/librenms/config.php /opt/librenms/config.php.backup.$(date +%Y%m%d_%H%M%S)
            
            # Update password in config.php
            sed -i "s/\$config\['db_pass'\] = '.*';/\$config['db_pass'] = '${DB_PASS}';/" /opt/librenms/config.php
            
            echo "✓ config.php updated"
        fi
        
        # Create .my.cnf
        echo ""
        echo "Creating .my.cnf file..."
        
        cat > /opt/librenms/.my.cnf << EOF
[client]
user=librenms
password=${DB_PASS}
host=localhost
EOF
        
        chmod 600 /opt/librenms/.my.cnf
        chown librenms:librenms /opt/librenms/.my.cnf
        
        echo "✓ .my.cnf created"
        
        echo ""
        echo "Testing connection..."
        sudo -u librenms mysql librenms -e "SELECT 1;" > /dev/null 2>&1
        
        if [ $? -eq 0 ]; then
            echo "✓ Database connection successful!"
        else
            echo "✗ Database connection failed"
            echo "Please check the password and try again"
            exit 1
        fi
        ;;
        
    2)
        echo ""
        echo "Creating .my.cnf file..."
        echo "============================================"
        echo ""
        
        # Get password from config.php
        if [ -f /opt/librenms/config.php ]; then
            DB_PASS=$(grep "^\$config\['db_pass'\]" /opt/librenms/config.php | sed "s/\$config\['db_pass'\] = '\(.*\)';/\1/")
            
            if [ -z "$DB_PASS" ]; then
                echo "Could not find password in config.php"
                read -p "Enter database password: " -s DB_PASS
                echo ""
            else
                echo "Found password in config.php"
            fi
        else
            read -p "Enter database password: " -s DB_PASS
            echo ""
        fi
        
        cat > /opt/librenms/.my.cnf << EOF
[client]
user=librenms
password=${DB_PASS}
host=localhost
EOF
        
        chmod 600 /opt/librenms/.my.cnf
        chown librenms:librenms /opt/librenms/.my.cnf
        
        echo "✓ .my.cnf created at /opt/librenms/.my.cnf"
        
        echo ""
        echo "Testing connection..."
        sudo -u librenms mysql librenms -e "SELECT 1;" > /dev/null 2>&1
        
        if [ $? -eq 0 ]; then
            echo "✓ Database connection successful!"
        else
            echo "✗ Database connection failed"
            echo "Please check the password and try again"
        fi
        ;;
        
    3)
        echo ""
        echo "Testing database connection..."
        echo "============================================"
        echo ""
        
        # Test as librenms user
        echo "Testing as librenms user..."
        sudo -u librenms mysql librenms -e "SELECT COUNT(*) as device_count FROM devices;" 2>&1
        
        if [ $? -eq 0 ]; then
            echo ""
            echo "✓ Connection successful!"
        else
            echo ""
            echo "✗ Connection failed!"
            echo ""
            echo "This could be because:"
            echo "  1. No password configured in .my.cnf"
            echo "  2. Wrong password in .my.cnf or config.php"
            echo "  3. Database user doesn't exist"
            echo ""
            echo "Run option 1 to set/reset the password"
        fi
        ;;
        
    4)
        echo "Exiting..."
        exit 0
        ;;
        
    *)
        echo "Invalid choice"
        exit 1
        ;;
esac

echo ""
echo "============================================"
echo "Configuration Complete"
echo "============================================"
echo ""
echo "You can now run:"
echo "  mysql librenms"
echo "  ./scripts/debug_discovery.sh 172.16.7.40"
echo ""
