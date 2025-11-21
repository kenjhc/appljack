# 🔬 CPC/CPA Toggle Testing Suite Documentation

## Overview
This comprehensive testing suite helps debug and verify the CPC/CPA budget toggle implementation. The client reported that events aren't being written to queues and values are returning $0.00. This suite provides multiple tools to diagnose and fix these issues.

---

## 🎯 The Core Problem

The main issues identified:

1. **Wrong Function Calls**: Node.js scripts are calling `getJobWiseCPCValue()` instead of `getCPCValue()`, looking in the wrong database table
2. **Queue Files Not Updating**: Events may not be written due to permission issues or environment detection problems
3. **Budget Type Logic**: The toggle logic might be inverted or not working correctly

---

## 🛠️ Testing Tools

### 1. **Interactive Dashboard** (`test_dashboard.html`)

**Access**: http://dev.appljack.com/test_dashboard.html

**Features**:
- Real-time environment status monitoring
- Feed configuration management
- Event firing interface for both CPC and CPA
- Queue status monitoring
- Database event viewer
- Live activity log with export capability
- Comprehensive test runner

**How to Use**:
1. Open the dashboard in your browser
2. Check environment status (should show DEV_SERVER for dev.appljack.com)
3. Load your test feed (default: aaa7c0e9ef)
4. Toggle between CPC/CPA modes
5. Fire test events and watch queue updates
6. Monitor the live log for detailed activity

---

### 2. **Complete Debug Script** (`TEST_DEBUG_COMPLETE.php`)

**Run**: `php TEST_DEBUG_COMPLETE.php`

**What it Does**:
- Checks all file permissions
- Verifies database configuration
- Tests write permissions
- Shows recent error logs
- Provides fix commands

**Example Output**:
```
ENVIRONMENT: DEV_SERVER
BASE PATH: /chroot/home/appljack/appljack.com/html/dev/

TEST 1: FILE SYSTEM CHECK
CPC Queue: ✅ EXISTS | Size: 1234B | Perms: 0666 | Writable: YES
CPA Queue: ✅ EXISTS | Size: 567B | Perms: 0666 | Writable: YES

TEST 2: DATABASE CONFIGURATION
✅ budget_type column exists
✅ Test Feed Found:
   Budget Type: CPC
   CPC Value: $0.75
   CPA Value: $5.00
```

---

### 3. **Value Retrieval Diagnostic** (`test_value_retrieval.php`)

**Run**: `php test_value_retrieval.php`

**What it Tests**:
- Shows exactly where CPC/CPA values are being retrieved from
- Compares appljobs table vs applcustfeeds table
- Identifies the wrong function calls
- Shows the correct queries that should be used

**Key Finding**: The code is looking in `appljobs` table but your values are in `applcustfeeds` table!

---

### 4. **Comprehensive Test Runner** (`run_complete_test.php`)

**Run**: `php run_complete_test.php`

**What it Does**:
- Tests database structure
- Verifies feed configuration
- Checks file permissions
- Simulates CPC and CPA campaigns
- Analyzes Node.js function calls
- Provides a complete pass/fail summary

---

### 5. **Debug Logger** (`event_debug_logger.php`)

**Usage**: Include in any PHP script for detailed logging

```php
include 'event_debug_logger.php';
$logger = new EventDebugLogger();
$logger->logEventReceived('CPC', $_GET);
$logger->logQueueWrite('CPC', $queueFile, true, $eventData);
```

**Log Location**: `{basePath}/event_debug.log`

---

### 6. **Debug Event Handlers**
- `applpass_debug.php` - CPC event handler with extensive logging
- Use instead of `applpass.php` for debugging

---

## 📝 Step-by-Step Testing Process

### Step 1: Initial Setup
```bash
# SSH into dev server
ssh user@dev.appljack.com

# Navigate to dev directory
cd /chroot/home/appljack/appljack.com/html/dev/

# Check file permissions
ls -la applpass*.json

# If files don't exist or aren't writable:
touch applpass_queue.json applpass_cpa_queue.json
chmod 666 applpass_queue.json applpass_cpa_queue.json
```

### Step 2: Run Diagnostic Tests
```bash
# Check environment and configuration
php TEST_DEBUG_COMPLETE.php

# Test value retrieval logic
php test_value_retrieval.php

# Run complete test suite
php run_complete_test.php
```

### Step 3: Use the Dashboard
1. Open http://dev.appljack.com/test_dashboard.html
2. Check environment status (should be green)
3. Load feed ID: aaa7c0e9ef
4. Set to CPC mode
5. Fire a CPC event
6. Check queue status (should show 1 event)
7. Switch to CPA mode
8. Fire a CPA event
9. Check queue status

### Step 4: Monitor Logs
```bash
# Watch error logs
tail -f applpass7.log
tail -f applpass_cpa.log

# Watch debug log
tail -f event_debug.log

# Check queue contents
cat applpass_queue.json
cat applpass_cpa_queue.json
```

### Step 5: Process Events
```bash
# Using Node.js (if fixed)
node applpass_putevents2.js
node applpass_cpa_putevent.js

# Or using PHP fallback
curl http://dev.appljack.com/process_queues.php
```

### Step 6: Verify in Database
```sql
-- Check recent events
SELECT eventid, eventtype, cpc, cpa, feedid, timestamp
FROM applevents
ORDER BY id DESC
LIMIT 10;

-- Check feed configuration
SELECT feedid, feedname, budget_type, cpc, cpa
FROM applcustfeeds
WHERE feedid = 'aaa7c0e9ef';
```

---

## 🔧 Common Issues & Fixes

### Issue 1: Queue files not updating
**Fix**:
```bash
chmod 666 applpass_queue.json applpass_cpa_queue.json
```

### Issue 2: CPC/CPA values are $0.00
**Fix**: In Node.js scripts, change:
- `getJobWiseCPCValue()` → `getCPCValue()`
- `getJobWiseCPAValue()` → `getCPAValue()`

### Issue 3: Wrong environment detected
**Check**: The scripts auto-detect based on domain:
- `dev.appljack.com` → DEV_SERVER
- `appljack.com` → PRODUCTION
- `localhost` → LOCAL_DEV

### Issue 4: Budget type not working
**Verify**:
```sql
-- Check if column exists
SHOW COLUMNS FROM applcustfeeds LIKE 'budget_type';

-- Update budget type
UPDATE applcustfeeds SET budget_type = 'CPC' WHERE feedid = 'aaa7c0e9ef';
```

---

## ✅ Expected Behavior

### CPC Campaign (budget_type = 'CPC'):
- **CPC Events**: Charge the CPC value ($0.75)
- **CPA Events**: Charge $0.00 (no conversion charges)

### CPA Campaign (budget_type = 'CPA'):
- **CPC Events**: Charge $0.00 (no click charges)
- **CPA Events**: Charge the CPA value ($5.00)

---

## 🚀 Quick Test URLs

### Fire CPC Event:
```
http://dev.appljack.com/applpass.php?c=9706023615&f=aaa7c0e9ef&j=10081&jpid=2244384563
```

### Fire CPA Event:
```
http://dev.appljack.com/cpa-event.php
```

### View Dashboard:
```
http://dev.appljack.com/test_dashboard.html
```

---

## 📊 Test Results Interpretation

When all tests pass, you should see:
- ✅ Environment correctly detected
- ✅ Queue files writable
- ✅ Events written to queue files
- ✅ Correct values calculated based on budget type
- ✅ Events processed into database

If any test fails, check the specific error message and refer to the fixes above.

---

## 🆘 Support

If issues persist after following this guide:

1. Run `php TEST_DEBUG_COMPLETE.php` and save output
2. Check `event_debug.log` for detailed error messages
3. Export logs from the dashboard
4. Review the function calls in Node.js scripts
5. Verify database structure matches requirements

The testing suite provides comprehensive debugging information to identify exactly where the problem occurs in the event processing pipeline.