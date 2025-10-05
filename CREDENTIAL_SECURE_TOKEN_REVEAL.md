# Credential Edit Screen - Secure Token Reveal

## ✅ **API Token Security Feature**

All credential fields are now visible by default, EXCEPT the API token which is hidden for security. Click the token field to reveal it for 5 seconds with a countdown timer.

---

## 🔒 **How It Works**

### **Field Visibility:**

#### **Always Visible (All Other Fields):**
- ✅ Header Name
- ✅ API Token Header  
- ✅ Session Token Header
- ✅ Login Path
- ✅ Login Method
- ✅ All other configuration fields

#### **Hidden by Default (Security):**
- 🔒 API Token
- 🔒 Token (for Token auth type)

---

## 🎯 **Token Reveal Feature**

### **User Experience:**

```
Initial State:
┌─────────────────────────────┐
│ API Token *                 │
│ [••••••••••••••••••••]      │ ← Hidden (password dots)
│ ℹ️ Click to reveal for 5s   │
└─────────────────────────────┘

After Click:
┌─────────────────────────────────┐
│ API Token *                     │
│ [abc123def456ghi789] [🕐 5s]    │ ← Visible + Timer
│ ℹ️ Click to reveal for 5s       │
└─────────────────────────────────┘

Countdown:
[🕐 5s] → [🕐 4s] → [🕐 3s] → [🕐 2s] → [🕐 1s] → Hidden

After 5 Seconds:
┌─────────────────────────────┐
│ API Token *                 │
│ [••••••••••••••••••••]      │ ← Hidden again
│ ℹ️ Click to reveal for 5s   │
└─────────────────────────────┘
```

---

## 💡 **Features**

### **1. Click to Reveal**
- Click on the token field
- Token becomes visible immediately
- Timer appears showing countdown

### **2. 5-Second Countdown**
- Visual timer: `🕐 5s, 4s, 3s, 2s, 1s`
- Updates every second
- Shows time remaining

### **3. Auto-Hide**
- Automatically hides after 5 seconds
- Returns to password (dots) display
- Timer disappears

### **4. Multiple Clicks**
- Each click resets the timer
- Can extend viewing time by clicking again
- Previous timer is cleared

### **5. Security Benefits**
- ✅ Prevents shoulder surfing
- ✅ Limits exposure time
- ✅ Visual feedback on reveal state
- ✅ Can't accidentally leave exposed

---

## 🎨 **UI Elements**

### **Token Field with Timer:**

```html
<div class="input-group">
    <input type="password" 
           onclick="revealToken(this)"
           value="secret-token">
    <span class="input-group-text" id="token-timer">
        <i class="fas fa-clock"></i> 
        <span id="timer-seconds">5</span>s
    </span>
</div>
```

### **Help Text:**
```
ℹ️ Click the field to reveal the API token for 5 seconds.
```

---

## 🔧 **Technical Implementation**

### **JavaScript Logic:**

```javascript
function revealToken(input) {
    // 1. Clear existing timers
    if (tokenTimer) clearTimeout(tokenTimer);
    if (countdownInterval) clearInterval(countdownInterval);
    
    // 2. Show token
    input.type = 'text';
    
    // 3. Show timer
    const timerDisplay = document.getElementById('token-timer');
    timerDisplay.style.display = 'flex';
    
    // 4. Countdown from 5 to 0
    let seconds = 5;
    countdownInterval = setInterval(() => {
        seconds--;
        document.getElementById('timer-seconds').textContent = seconds;
    }, 1000);
    
    // 5. Hide after 5 seconds
    tokenTimer = setTimeout(() => {
        input.type = 'password';
        timerDisplay.style.display = 'none';
    }, 5000);
}
```

### **Field Attributes:**

- `type="password"` - Hidden by default
- `onclick="revealToken(this)"` - Reveals on click
- `readonly` - Prevents accidental typing during initial click
- `onfocus="this.removeAttribute('readonly')"` - Allows editing after focus

---

## 📋 **Credential Types Updated**

### **1. Session Token Credential**
File: `session-token.blade.php`

**API Token Field:**
- 🔒 Hidden by default (password type)
- 🕐 5-second reveal on click
- ⏱️ Countdown timer visible

**All Other Fields:**
- ✅ Login Path (visible)
- ✅ Session Token Header (visible)
- ✅ API Token Header (visible)
- ✅ Login Method (visible)

### **2. Token Credential**
File: `token.blade.php`

**Token Field:**
- 🔒 Hidden by default (password type)
- 🕐 5-second reveal on click
- ⏱️ Countdown timer visible

**All Other Fields:**
- ✅ Header Name (visible)
- ✅ Scheme (visible)

### **3. Basic Auth Credential**
File: `basic-auth.blade.php`

**Password Field:**
- Already using password type (unchanged)
- Username is visible

---

## 🎯 **Use Cases**

### **Use Case 1: View Token to Verify**
1. User opens credential edit screen
2. Token is hidden: `••••••••••••`
3. Click token field
4. Token reveals: `abc123def456`
5. Verify it's correct
6. After 5 seconds, auto-hides
7. ✅ Security maintained

### **Use Case 2: Copy Token**
1. Click token field to reveal
2. Timer starts: `🕐 5s`
3. Select and copy token text
4. If need more time, click again (resets timer)
5. Token auto-hides after 5 seconds
6. ✅ Copied successfully

### **Use Case 3: Edit Token**
1. Click to reveal token
2. Focus on field (removes readonly)
3. Edit token value
4. Timer still counting down
5. Click again to reset if needed
6. Save changes
7. ✅ Token updated

### **Use Case 4: Prevent Exposure**
1. User opens screen in shared space
2. Token hidden by default
3. Others can't see sensitive value
4. User can quickly reveal when safe
5. Auto-hides to prevent accidents
6. ✅ Enhanced security

---

## 🔐 **Security Benefits**

### **1. Time-Limited Exposure**
- Token only visible for 5 seconds
- Reduces risk of observation
- Auto-hides without user action

### **2. Intentional Reveal**
- Must click to reveal
- No accidental exposure
- Clear user intent required

### **3. Visual Feedback**
- Timer shows exposure time
- User knows when it will hide
- Countdown creates urgency

### **4. Multiple Security Layers**
- Hidden by default
- Time-limited reveal
- Visual indicators
- Auto-hide mechanism

---

## 📊 **Field Summary**

| Field Type | Visibility | Click Behavior | Timer |
|------------|-----------|----------------|-------|
| **API Token** | 🔒 Hidden | Reveals for 5s | ✅ Yes |
| **Token** | 🔒 Hidden | Reveals for 5s | ✅ Yes |
| **Header Name** | ✅ Visible | N/A | ❌ No |
| **API Token Header** | ✅ Visible | N/A | ❌ No |
| **Session Token Header** | ✅ Visible | N/A | ❌ No |
| **Login Path** | ✅ Visible | N/A | ❌ No |
| **Login Method** | ✅ Visible | N/A | ❌ No |
| **Scheme** | ✅ Visible | N/A | ❌ No |

---

## 💻 **Files Modified**

1. **`/resources/views/settings/rest-api/credentials/partials/session-token.blade.php`**
   - API Token field with reveal functionality
   - 5-second timer with countdown
   - All other fields visible

2. **`/resources/views/settings/rest-api/credentials/partials/token.blade.php`**
   - Token field with reveal functionality
   - 5-second timer with countdown
   - Header fields visible

---

## ✅ **Result**

### **Security + Usability:**
- ✅ **Secure**: Tokens hidden by default
- ✅ **Accessible**: Easy to reveal when needed
- ✅ **Time-limited**: Auto-hides after 5 seconds
- ✅ **Visual feedback**: Countdown timer
- ✅ **All other fields visible**: Easy configuration
- ✅ **No accidental exposure**: Intentional click required

### **User Experience:**
```
Default State:
  All fields visible EXCEPT tokens
  ↓
Click Token Field:
  Token reveals + Timer starts (5s)
  ↓
5 Seconds Later:
  Token auto-hides
  ↓
Result:
  Secure + Convenient!
```

**Perfect balance between security and usability!** 🔒✨
