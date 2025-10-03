#!/bin/bash

# PureStorage REST API Setup Script for LibreNMS
# This script completes the setup for PureStorage FlashArray REST API polling

set -e  # Exit on any error

SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
cd "$SCRIPT_DIR"

echo "============================================"
echo "PureStorage REST API Setup for LibreNMS"
echo "============================================"
echo ""

# Step 1: Validate JSON configuration
echo "Step 1: Validating JSON configuration file..."
php validate_json.php
if [ $? -ne 0 ]; then
    echo "❌ JSON validation failed. Please fix the config_definitions.json file."
    exit 1
fi
echo ""

# Step 2: Run database seeder
echo "Step 2: Adding 'Session Token' authentication type..."
php artisan db:seed --class=RestApiAuthenticationTypeSeeder
if [ $? -eq 0 ]; then
    echo "✅ Authentication type seeded successfully"
else
    echo "❌ Failed to seed authentication type"
    exit 1
fi
echo ""

# Step 3: Clear caches
echo "Step 3: Clearing caches..."
php artisan config:clear
php artisan cache:clear
echo "✅ Caches cleared"
echo ""

echo "============================================"
echo "✅ Setup Complete!"
echo "============================================"
echo ""
echo "Next Steps:"
echo ""
echo "1. Generate API Token on PureStorage:"
echo "   - Log into FlashArray web interface"
echo "   - Go to Settings > Access > Users"
echo "   - Click 'Create API Token' for your user"
echo "   - Save the token securely"
echo ""
echo "2. Create Credential in LibreNMS:"
echo "   - Navigate to Settings > REST API > Credentials"
echo "   - Click 'Create New Credential'"
echo "   - Name: 'PureStorage API Token'"
echo "   - Authentication Type: 'Session Token'"
echo "   - Add these parameters:"
echo "     • api_token: <your-api-token>"
echo "     • login_path: api/2.26/login"
echo "     • token_header: x-auth-token"
echo "     • api_token_header: api-token"
echo ""
echo "3. Configure Device Connection:"
echo "   - Go to device Edit > REST API tab"
echo "   - Edit your PureStorage connection"
echo "   - Check 'Enable Connection'"
echo "   - Check 'Disable SSL Verification' (if self-signed cert)"
echo "   - Apply the 'PureStorage API Token' credential"
echo ""
echo "4. Test Polling:"
echo "   php lnms device:poll <device-id> -m rest-api -vv"
echo ""
echo "For detailed documentation, see the setup guide artifact."
echo ""
