# Test Screen - Credential Selection

## ✅ **Credential Override for Testing**

The test/preview screen now allows you to select a credential to use for authentication during testing!

---

## 🎯 **What's New**

### **Credential Selector Dropdown**
- Select any credential from the system
- Override the template's default credential
- Test with different authentication methods
- Option to use template default

---

## 📋 **New Features**

### **1. Credential Selection Field**

**Location**: Test/Preview tab, below device selector

**Options**:
- `-- Use template default --` - Uses credential configured in template
- List of all available credentials with their auth type
- Example: `PureStorage Token (Session Token)`

### **2. Updated Test Summary**

**Now shows**:
```
Summary
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
Device:            10.199.1.10
Connection:        PureStorage API
Credential:        PureStorage Token (Override)  ← NEW!
Base URL:          https://10.199.1.10
Endpoints Tested:  3
Total Time:        245.67ms
Success Rate:      100%
```

### **3. Credential Indicator**

Shows which credential was used:
- `PureStorage Token (Override)` - You selected this credential
- `Default Credential (Template Default)` - From template
- `None` - No authentication

---

## 💡 **How It Works**

### **Testing Flow:**

```
1. Select Device
   ↓
2. Select Credential (Optional)
   ↓
3. Choose Endpoint(s)
   ↓
4. Configure Test Options
   ↓
5. Click "Run Test"
   ↓
6. System uses:
   - Override credential (if selected)
   - OR Template default credential
   - OR No authentication
   ↓
7. View Results with credential info
```

### **Backend Processing:**

```php
// Controller checks for override credential
if ($overrideCredentialId) {
    // Override all connections with test credential
    foreach ($connections as &$connection) {
        $connection['credential_id'] = $overrideCredentialId;
    }
}

// Use in authentication
$credential = RestApiCredential::find($credentialId);
// Apply auth headers, tokens, etc.
```

---

## 🎨 **UI Layout**

### **Test Configuration Section:**

```
╔═══════════════════════════════════════════╗
║  📋 Test Configuration                    ║
╠═══════════════════════════════════════════╣
║                                           ║
║  🖥️ Select Device to Test *               ║
║  [Dropdown: Devices]                      ║
║  └─ Variables will be replaced            ║
║                                           ║
║  🔑 Select Credential (Optional)          ║
║  [Dropdown: -- Use template default --]   ║
║  └─ Override template credential          ║
║                                           ║
║  🔌 Select Endpoint(s) to Test            ║
║  [Dropdown: All Endpoints / Specific]     ║
║  └─ Choose endpoints to test              ║
║                                           ║
╚═══════════════════════════════════════════╝
```

### **Results Summary:**

```
╔═══════════════════════════════════════════╗
║  ✅ Test Successful                       ║
╠═══════════════════════════════════════════╣
║  Summary                                  ║
║  ─────────────────────────────────────    ║
║  Device:            10.199.1.10           ║
║  Connection:        PureStorage API       ║
║  Credential:        MyToken (Override) ← Shows what was used
║  Base URL:          https://10.199.1.10   ║
║  Endpoints Tested:  3                     ║
║  Total Time:        245ms                 ║
║  Success Rate:      [████████] 100%       ║
╚═══════════════════════════════════════════╝
```

---

## 📊 **Use Cases**

### **Use Case 1: Test Different Credentials**
**Scenario**: Template has Basic Auth, but you want to test with Token Auth

**Steps**:
1. Select device: `10.199.1.10`
2. Select credential: `Test Token (Session Token)` ← Override
3. Run test
4. Results show: `Credential: Test Token (Override)`

**Result**: Tests using Token auth instead of template's Basic Auth ✅

---

### **Use Case 2: Verify Template Default Works**
**Scenario**: Template has credential configured, test with it

**Steps**:
1. Select device: `10.199.1.10`
2. Leave credential: `-- Use template default --`
3. Run test
4. Results show: `Credential: Production API Key (Template Default)`

**Result**: Uses template's configured credential ✅

---

### **Use Case 3: Test Without Authentication**
**Scenario**: Test endpoint that doesn't require auth

**Steps**:
1. Template has credential configured
2. Select device: `10.199.1.10`
3. Create credential "No Auth" (or select none)
4. Run test
5. Results show: `Credential: None`

**Result**: Tests without any authentication ✅

---

### **Use Case 4: Troubleshoot Auth Issues**
**Scenario**: Template failing, want to test with known working credential

**Steps**:
1. Template has `Broken Credential`
2. Select device: `10.199.1.10`
3. Select credential: `Working Test Credential` ← Override
4. Run test
5. Compare results

**Result**: Identifies if issue is credential or endpoint ✅

---

## 🔧 **API Request**

### **Request Payload (Enhanced):**

```json
{
  "device_id": 2,
  "credential_id": 5,  ← NEW! Override credential
  "test_all_endpoints": false,
  "specific_endpoint": "0-1",
  "verify_ssl": false,
  "show_headers": true,
  "verbose": true,
  "timeout": 60
}
```

### **Response Summary (Enhanced):**

```json
{
  "success": true,
  "summary": {
    "device": "10.199.1.10",
    "connection": "PureStorage API",
    "credential": "Test Token (Override)",  ← NEW!
    "base_url": "https://10.199.1.10",
    "endpoints_tested": 3,
    "total_time": 245.67,
    "success_rate": 100
  },
  "endpoint_results": [...]
}
```

---

## 💡 **Benefits**

### **For Testing:**
- ✅ **Test multiple credentials** without editing template
- ✅ **Quick credential switching** for comparison
- ✅ **Troubleshoot auth issues** efficiently
- ✅ **Verify new credentials** before applying to template

### **For Development:**
- ✅ **Separate test credentials** from production
- ✅ **Try different auth methods** easily
- ✅ **Debug authentication** problems
- ✅ **No template modification** needed

### **For Operations:**
- ✅ **Verify credential validity** before deployment
- ✅ **Test failover credentials**
- ✅ **Compare auth performance**
- ✅ **Document which credentials work**

---

## 🎯 **Credential Indicators**

The summary clearly shows which credential was used:

| Display | Meaning |
|---------|---------|
| `MyCredential (Override)` | You selected this credential for the test |
| `MyCredential (Template Default)` | Template's configured credential was used |
| `None` | No authentication was used |

---

## 📝 **Implementation Details**

### **Files Modified:**

1. **Preview Blade Template**
   - Added credential selector dropdown
   - Updated JavaScript to include credential_id
   - Enhanced summary display

2. **Test Controller**
   - Added credential_id validation
   - Override credential in template data
   - Include credential info in summary

### **Code Flow:**

```php
// 1. Receive credential override
$overrideCredentialId = $request->get('credential_id');

// 2. Override in template data
if ($overrideCredentialId) {
    foreach ($connections as &$connection) {
        $connection['credential_id'] = $overrideCredentialId;
    }
}

// 3. Add to summary
if ($overrideCredentialId) {
    $credential = RestApiCredential::find($overrideCredentialId);
    $summary['credential'] = $credential->name . ' (Override)';
} elseif (isset($template_credential)) {
    $summary['credential'] = $template_credential . ' (Template Default)';
} else {
    $summary['credential'] = 'None';
}
```

---

## ✅ **Summary**

### **What You Can Now Do:**

1. ✅ **Select any credential** from dropdown
2. ✅ **Override template default** for testing
3. ✅ **See which credential was used** in results
4. ✅ **Test multiple auth methods** quickly
5. ✅ **Troubleshoot authentication** easily

### **Perfect For:**
- Testing different credentials
- Verifying new credentials
- Debugging auth issues
- Comparing authentication methods
- Validating credentials before production

---

## 🚀 **Example Workflow**

```
Template Configuration:
  Connection: PureStorage API
  Credential: Production Token
  
Test Scenario 1:
  Device: 10.199.1.10
  Credential: -- Use template default --
  Result: Uses "Production Token (Template Default)"
  
Test Scenario 2:
  Device: 10.199.1.10  
  Credential: Test Token ← Override
  Result: Uses "Test Token (Override)"
  
Test Scenario 3:
  Device: 10.199.1.10
  Credential: No Auth
  Result: Uses "None"
```

**Now you can test with any credential without modifying the template!** 🎉

---

## 📄 **Files Modified**

1. `/resources/views/settings/rest-api/templates/partials/preview.blade.php`
   - Added credential dropdown
   - Updated test request payload
   - Enhanced results display

2. `/app/Http/Controllers/Settings/RestApiTemplateController.php`
   - Added credential_id validation
   - Override credential logic
   - Credential info in summary

**The test screen now has full credential selection capability!** 🔑
