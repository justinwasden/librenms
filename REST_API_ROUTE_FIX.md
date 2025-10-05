# REST API Template Test Route Error - Fixed

## ❌ Error
```
Route [devices.rest-api.templates.test] not defined
```

## ✅ Solution

The route IS defined in `/routes/web.php` at line 170, but Laravel's route cache needs to be cleared.

### Quick Fix:

```bash
cd /opt/librenms

# Clear route cache
php artisan route:clear

# Clear config cache (just in case)
php artisan config:clear

# Clear view cache
php artisan view:clear

# Clear all caches
php artisan cache:clear

# Rebuild route cache (optional - only in production)
# php artisan route:cache
```

---

## 🔍 Verify Route Exists

After clearing cache, verify the route:

```bash
php artisan route:list | grep "rest-api.templates.test"
```

**Expected output:**
```
POST  devices/rest-api-templates/{template}/test  devices.rest-api.templates.test
```

---

## 📍 Route Definition

**File**: `/routes/web.php`  
**Line**: ~170

```php
// Test template endpoint
Route::post('rest-api-templates/{template}/test', [\App\Http\Controllers\Settings\RestApiTemplateController::class, 'test'])
     ->name('rest-api.templates.test');
```

---

## 🧪 Test the Route

After clearing cache:

1. Navigate to: `/devices/rest-api-templates/{id}/edit`
2. Click the "Preview" tab
3. Select a device
4. Click "Run Test"

The test should now work!

---

## 🔄 Alternative: Restart Services

If clearing cache doesn't work:

```bash
# Restart PHP-FPM
sudo systemctl restart php8.1-fpm
# or
sudo systemctl restart php-fpm

# Restart web server
sudo systemctl restart nginx
# or
sudo systemctl restart apache2
```

---

## 📝 Why This Happens

When routes are added or modified, Laravel may have a cached version of the routes. The cache needs to be cleared for new routes to be recognized.

**Common causes:**
- Route cache was built in production (`php artisan route:cache`)
- Config cache includes old route list
- View cache has old route references

---

## ✅ Checklist

Run these commands in order:

```bash
cd /opt/librenms

# 1. Clear all caches
php artisan route:clear
php artisan config:clear  
php artisan view:clear
php artisan cache:clear

# 2. Verify route exists
php artisan route:list | grep "rest-api.templates.test"

# 3. Test in browser
# Navigate to template edit page and try the preview tab
```

---

## 🚨 If Still Not Working

If the route still isn't found after clearing cache:

### Check 1: Verify File Saved
```bash
grep -n "rest-api.templates.test" /opt/librenms/routes/web.php
```

Should show line number with the route definition.

### Check 2: Check PHP Syntax
```bash
php -l /opt/librenms/routes/web.php
```

Should show: "No syntax errors detected"

### Check 3: Check Controller Method Exists
```bash
grep -n "public function test" /opt/librenms/app/Http/Controllers/Settings/RestApiTemplateController.php
```

Should show the `test()` method.

### Check 4: Verify Middleware
The route must be inside the `Route::middleware('can:admin')` group, which it is.

---

## 💡 Prevention

To avoid this in the future:

1. **Development**: Never run `php artisan route:cache` in development
2. **After route changes**: Always run `php artisan route:clear`
3. **Production deployments**: Clear cache as part of deployment script

---

## 🎯 Final Command

Just run this one-liner:

```bash
cd /opt/librenms && php artisan route:clear && php artisan config:clear && php artisan view:clear && php artisan cache:clear
```

Then refresh your browser and test again!

---

**The route is defined correctly - it just needs cache clearing!** 🚀
