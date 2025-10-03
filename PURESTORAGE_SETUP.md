# PureStorage REST API Integration for LibreNMS

## Quick Start

All code changes have been applied. To complete the setup, run:

```bash
cd /Users/justinwasden/Documents/GitHub/librenms
chmod +x setup_purestorage_api.sh
./setup_purestorage_api.sh
```

## What Was Changed

### 1. Core Polling Logic (`app/Pollers/Api.php`)
- ✅ Added session token caching mechanism
- ✅ Implemented `getSessionToken()` method for two-step authentication
- ✅ Added support for "Session Token" authentication type
- ✅ SSL verification now applies to both login and endpoint requests

### 2. Connection Model (`app/Models/RestApiConnection.php`)
- ✅ Added `disable_ssl_verify` to fillable fields
- ✅ Added `enabled` to fillable fields  
- ✅ Added proper boolean casting

### 3. Authentication Types (`database/seeders/RestApiAuthenticationTypeSeeder.php`)
- ✅ Added "Session Token" authentication type

### 4. Configuration (`resources/definitions/config_definitions.json`)
- ✅ Fixed JSON syntax (missing comma after rest-api module)
- ✅ Added rest-api poller module configuration

## How It Works

### PureStorage Authentication Flow

```
1. Device polling starts
   ↓
2. Check for "Session Token" authentication type
   ↓
3. Check cache for existing session token
   ↓ (if not found)
4. POST to https://<array>/api/2.26/login
   Headers: { "api-token": "<your-token>" }
   ↓
5. Extract "x-auth-token" from response headers
   ↓
6. Cache token for 1 hour
   ↓
7. Use session token for all endpoint calls
   Headers: { "x-auth-token": "<session-token>" }
```

## Configuration Steps

### 1. Run Setup Script

```bash
./setup_purestorage_api.sh
```

This will:
- Validate JSON configuration
- Add "Session Token" authentication type to database
- Clear caches

### 2. Create PureStorage API Token

On your FlashArray:
1. Log into web management interface
2. Navigate to **Settings > Access > Users**
3. Click **Create API Token** for your user
4. Save the token (e.g., `a1b2c3d4-1234-5678-abcd-1234567890ab`)

### 3. Create Credential in LibreNMS

1. Navigate to **Settings > REST API > Credentials**
2. Click **Create New Credential**
3. Fill in:
   - **Name**: `PureStorage FA-X90R2` (or your model)
   - **Authentication Type**: `Session Token`
4. Add Parameters (click Add Parameter for each):

| Key | Value | Description |
|-----|-------|-------------|
| `api_token` | `<your-token-here>` | From step 2 |
| `login_path` | `api/2.26/login` | Login endpoint |
| `token_header` | `x-auth-token` | Response header name |
| `api_token_header` | `api-token` | Request header name |

Optional parameters:
- `login_method`: `POST` (default)
- `session_ttl`: `3600` (1 hour, default)

### 4. Configure Device Connection

1. Go to your device's **Edit > REST API** tab
2. Click **Edit Connection** on your PureStorage connection
3. Set:
   - **Base URL**: `https://172.16.7.5` (your array IP)
   - **Rate Limit**: `60`
   - ☑️ **Enable Connection**
   - ☑️ **Disable SSL Verification** (if self-signed cert)
4. Click **Update Connection**
5. Click **Apply Creds** or **Edit Creds**
6. Select your **PureStorage FA-X90R2** credential
7. Click **Save Credentials**

### 5. Test

```bash
# Test polling with verbose output
php lnms device:poll 1 -m rest-api -vv

# You should see:
# Obtaining session token from https://172.16.7.5/api/2.26/login...
# Successfully obtained session token...
# Polling URL: https://172.16.7.5/api/2.26/arrays...
# Storing metric for 172.16.7.5...
```

## Troubleshooting

### Issue: "Required Authorization header invalid"

**Solution**: 
1. Verify credential type is "Session Token"
2. Check `api_token` parameter is correct
3. Ensure `login_path` matches your API version

### Issue: SSL Certificate Errors

**Solution**: Check **Disable SSL Verification** in connection settings

### Issue: No x-auth-token in response

**Solution**: 
1. Verify `token_header` parameter is `x-auth-token` (lowercase)
2. Check API version in `login_path` (e.g., `api/2.26/login`)

### Check Logs

```bash
# View polling logs
tail -f /opt/librenms/logs/librenms.log | grep -i "rest.*api"

# Or Laravel logs  
tail -f /opt/librenms/storage/logs/laravel.log
```

### Manual Testing

Test your API token directly with curl:

```bash
# Get session token
curl -k -X POST \
  -H "api-token: your-api-token-here" \
  -H "Content-Type: application/json" \
  https://172.16.7.5/api/2.26/login \
  -D - 2>&1 | grep -i x-auth-token

# Use session token (replace TOKEN)
curl -k -X GET \
  -H "x-auth-token: TOKEN" \
  https://172.16.7.5/api/2.26/arrays
```

## Features

### Session Token Caching
- Tokens cached for 1 hour (configurable via `session_ttl`)
- Reduces login API calls
- Automatic renewal when expired

### SSL Verification
- Configurable per connection
- Applies to both login and endpoint requests
- Supports self-signed certificates

### Rate Limiting
- Respects connection `rate_limit` setting
- Tracks requests per minute
- Prevents API throttling

### Error Handling
- Exponential backoff for failed endpoints
- Detailed error logging
- Failure count tracking

## Files Modified

```
app/Pollers/Api.php
app/Models/RestApiConnection.php
database/seeders/RestApiAuthenticationTypeSeeder.php
resources/definitions/config_definitions.json
```

## Support

For issues or questions:
1. Check logs: `/opt/librenms/logs/librenms.log`
2. Review PureStorage API documentation
3. Verify API version matches your FlashArray version

## References

- [PureStorage REST API Documentation](https://support.purestorage.com/bundle/m_purityfa_rest_api)
- [PureStorage REST API Blog](https://blog.purestorage.com/purely-technical/try-the-rest-api-using-a-rest-client/)
