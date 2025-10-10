#!/bin/bash
# Remove old custom model files

echo "Removing old Storage Array models..."

# Backup first (just in case)
mkdir -p /tmp/librenms_old_models_backup
cp /Users/justinwasden/Documents/GitHub/librenms/app/Models/StorageArray*.php /tmp/librenms_old_models_backup/ 2>/dev/null
cp /Users/justinwasden/Documents/GitHub/librenms/app/Models/StorageController.php /tmp/librenms_old_models_backup/ 2>/dev/null

# Remove the old models
rm -f /Users/justinwasden/Documents/GitHub/librenms/app/Models/StorageArray.php
rm -f /Users/justinwasden/Documents/GitHub/librenms/app/Models/StorageArrayHost.php
rm -f /Users/justinwasden/Documents/GitHub/librenms/app/Models/StorageArrayVolume.php
rm -f /Users/justinwasden/Documents/GitHub/librenms/app/Models/StorageController.php

echo "Old models backed up to /tmp/librenms_old_models_backup/"
echo "Old models removed!"

# Remove references from DataRouter
echo "Note: You'll need to remove references to these models from DataRouter.php"
