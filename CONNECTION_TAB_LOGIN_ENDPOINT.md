# Connection Tab - Login Endpoint Configuration

## ✅ **Enhanced Connection Configuration**

The Connection tab now includes comprehensive login/authentication endpoint configuration!

---

## 🎯 **What's New**

### **Login/Authentication Endpoint Section**
A dedicated card for configuring session-based authentication endpoints (like PureStorage's `/api/2.26/login`)

---

## 📋 **New Fields Added**

### **1. Login Endpoint Configuration**

#### **Login Path** 
- Path to the login endpoint
- Appended to Base URL
- Example: `/api/2.26/login`
- **Use Case**: PureStorage FlashArray needs `/api/2.26/login`

#### **Login Method**
- HTTP method for login request
- Options: POST (default), GET, PUT
- Most APIs use POST for login

#### **Session Token Header**
- Header name where session token is returned
- Example: `x-auth-token`
- The API returns the token in this header

#### **API Token Header**
- Header name for sending API token during login
- Example: `api-token`
- Used to authenticate the login request itself

#### **Login Request Body (JSON)**
- Optional JSON body for login
- Example: `{"username": "admin", "password": "secret"}`
- Leave empty for header-based auth only

### **2. Full Login URL Preview**
- **Real-time preview** of the complete login URL
- Shows: Base URL + Login Path
- Example: `https://{device_hostname}/api/2.26/login`

### **3. Connection Settings**

#### **Rate Limit**
- Maximum requests per minute
- Default: 60
- Range: 1-1000

#### **Timeout**
- Request timeout in seconds
- Default: 30
- Range: 5-300

#### **Retry Attempts**
- Number of retry attempts on failure
- Default: 3
- Range: 0-10

### **4. SSL/TLS Settings**

#### **Disable SSL Verification**
- Checkbox to disable certificate verification
- Useful for self-signed certificates
- Shows warning about production use

### **5. Credential Selection**
- Dropdown to select authentication credential
- Shows credential name and type
- Option for "None" (no auth)

---

## 🏗️ **Form Structure**

### **Section 1: Basic Info**
```
[Connection Name*]          [Credential Dropdown]
[Base URL*]
```

### **Section 2: Login/Authentication Endpoint** 
```
╔═══════════════════════════════════════════════╗
║  🔐 Login/Authentication Endpoint             ║
║  (Optional - for session-based auth)          ║
╠═══════════════════════════════════════════════╣
║  ℹ️ Info: When to use this section            ║
║                                               ║
║  [Login Path]              [Login Method ▼]   ║
║  [Session Token Header]    [API Token Header] ║
║  [Login Request Body (JSON)]                  ║
║                                               ║
║  📋 Full Login URL Preview:                   ║
║  https://{device_hostname}/api/2.26/login     ║
╚═══════════════════════════════════════════════╝
```

### **Section 3: Connection Settings**
```
[Rate Limit]    [Timeout]    [Retry Attempts]
```

### **Section 4: SSL Settings**
```
╔═══════════════════════════════════════╗
║  🔒 SSL/TLS Settings                  ║
║  ☐ Disable SSL Certificate Verification ║
╚═══════════════════════════════════════╝
```

---

## 💡 **Example: PureStorage FlashArray**

### **Configuration:**
```
Connection Name: PureStorage FlashArray API
Credential: [Select Session Token credential]
Base URL: https://{device_hostname}

Login Endpoint:
  Login Path: /api/2.26/login
  Login Method: POST
  Session Token Header: x-auth-token
  API Token Header: api-token
  Login Request Body: (leave empty)

Connection Settings:
  Rate Limit: 60
  Timeout: 30
  Retry Attempts: 3

SSL Settings:
  ☑ Disable SSL Certificate Verification
```

### **Result:**
- Full login URL: `https://{device_hostname}/api/2.26/login`
- When polling device `10.199.1.10`:
  - Login URL becomes: `https://10.199.1.10/api/2.26/login`
  - System sends API token in `api-token` header
  - Receives session token in `x-auth-token` header
  - Uses session token for subsequent requests

---

## 🔄 **How Session Authentication Works**

### **Flow:**
```
1. LibreNMS reads template configuration
   ↓
2. If login_path is configured:
   ↓
3. Makes login request:
   - URL: base_url + login_path
   - Method: login_method (POST)
   - Headers: api_token in api_token_header
   - Body: login_body (if provided)
   ↓
4. Extracts session token from response header:
   - Looks for session_token_header (x-auth-token)
   ↓
5. Uses session token for all endpoint requests:
   - Adds token to x-auth-token header
   ↓
6. Polls configured endpoints with session token
```

---

## 📊 **Field Descriptions**

| Field | Required | Default | Description |
|-------|----------|---------|-------------|
| **Connection Name** | Yes | - | Descriptive name for API connection |
| **Credential** | No | None | Authentication credential to use |
| **Base URL** | Yes | - | Base URL with optional placeholders |
| **Login Path** | No | - | Path to login endpoint |
| **Login Method** | No | POST | HTTP method for login |
| **Session Token Header** | No | - | Where to find session token in response |
| **API Token Header** | No | - | Where to send API token in request |
| **Login Body** | No | - | JSON body for login request |
| **Rate Limit** | No | 60 | Max requests per minute |
| **Timeout** | No | 30 | Request timeout (seconds) |
| **Retry Attempts** | No | 3 | Number of retries on failure |
| **Disable SSL Verify** | No | false | Skip SSL certificate validation |

---

## 🎨 **UI Features**

### **Visual Organization**
- ✅ Grouped related fields
- ✅ Color-coded sections (cards)
- ✅ Clear section headers with icons
- ✅ Helpful inline descriptions

### **Live Preview**
- ✅ Real-time URL preview
- ✅ Shows complete login URL
- ✅ Updates as you type

### **User Guidance**
- ✅ Info alert explaining when to use login endpoint
- ✅ Placeholder examples in all fields
- ✅ Warning for SSL disable option
- ✅ Help text under each field

### **Smart Defaults**
- ✅ POST method for login (most common)
- ✅ 60 req/min rate limit
- ✅ 30 second timeout
- ✅ 3 retry attempts
- ✅ SSL verification enabled

---

## 🔍 **Common Use Cases**

### **Use Case 1: Session Token Auth (PureStorage)**
```
Base URL: https://{device_hostname}
Login Path: /api/2.26/login
Login Method: POST
Session Token Header: x-auth-token
API Token Header: api-token
```

### **Use Case 2: OAuth Token Endpoint**
```
Base URL: https://api.example.com
Login Path: /oauth/token
Login Method: POST
Login Body: {"grant_type":"client_credentials"}
Session Token Header: access_token
```

### **Use Case 3: Basic Auth (No Login Endpoint)**
```
Base URL: https://{device_hostname}
Login Path: (leave empty)
Credential: Select Basic Auth credential
```

### **Use Case 4: Self-Signed Certificate**
```
Base URL: https://{device_ip}
Disable SSL Verify: ☑ Checked
Login Path: /api/v1/auth
```

---

## 📝 **Benefits**

### **For Users:**
- ✅ **Easy to configure** session-based auth
- ✅ **Visual preview** of login URL
- ✅ **Clear guidance** on when/how to use
- ✅ **All settings in one place**

### **For Administrators:**
- ✅ **Template reusability** across devices
- ✅ **Fine-grained control** over connection behavior
- ✅ **Security options** (SSL, rate limiting)
- ✅ **Error handling** (retries, timeouts)

### **For Different APIs:**
- ✅ **Flexible** - works with various auth schemes
- ✅ **Header-based** or **body-based** login
- ✅ **Variable paths** for API versions
- ✅ **Customizable** token extraction

---

## 🚀 **What You Can Now Configure**

### **Previously:**
```
Connection Name: [input]
Base URL: [input]
Rate Limit: [input]
```

### **Now:**
```
Basic Info:
  ✅ Connection Name
  ✅ Credential Selection
  ✅ Base URL with placeholders

Login Endpoint (NEW!):
  ✅ Login Path (/api/2.26/login)
  ✅ Login Method (POST/GET/PUT)
  ✅ Session Token Header
  ✅ API Token Header
  ✅ Login Request Body
  ✅ Live URL Preview

Connection Settings:
  ✅ Rate Limit
  ✅ Timeout
  ✅ Retry Attempts

Security:
  ✅ SSL Verification Toggle
```

---

## ✅ **Summary**

The Connection tab now provides complete control over:

1. **Authentication Flow** - Configure how to log in and get session tokens
2. **Connection Behavior** - Rate limits, timeouts, retries
3. **Security Settings** - SSL verification options
4. **Visual Feedback** - Live preview of configuration

**Perfect for APIs like PureStorage that need `/api/2.26/login` for session authentication!** 🎉

---

## 📄 **File Modified**

**Path**: `/resources/views/settings/rest-api/templates/partials/connection.blade.php`

All login endpoint configuration is now editable in the Connection tab!
