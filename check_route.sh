#!/bin/bash
# Check if REST API template test route exists

cd /opt/librenms
php artisan route:list --name=devices.rest-api.templates.test
