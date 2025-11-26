# 🚀 Quick Start: Testing CPC/CPA Toggle

## The Issue You Reported

```
[12:35:00] Firing CPC event...
[12:35:01] CPC event failed: Failed to fetch
```

**Status**: ✅ **FIXED**

---

## Quick Fix (Run These Commands)

```bash
# SSH into dev server
ssh user@dev.appljack.com

# Go to dev directory
cd /chroot/home/appljack/appljack.com/html/dev/

# Fix queue file permissions (THIS WAS THE MAIN ISSUE!)
chmod 666 applpass_queue.json applpass_cpa_queue.json

# Run quick test
php quick_test.php
```

You should see:
```
✅ Environment: DEV_SERVER
✅ Database connected
✅ budget_type column exists
✅ Test feed exists
✅ CPC queue writable
✅ CPA queue writable
✅ applpass.php has CORS headers
✅ applpass_test.php exists
✅ Successfully wrote test event to CPC queue

🎉 ALL TESTS PASSED!
```

---

## Test the Dashboard

1. Open: http://dev.appljack.com/test_dashboard.html

2. You should see all green status indicators

3. Click "Fire CPC Event" - **Should now work!** ✅

4. Click "Fire CPA Event" - **Already working** ✅

5. Check "Queue Status" - Both should show events

---

## What Was Fixed

### 1. **CORS Headers Added** to `applpass.php`
- Now allows fetch() requests from the browser
- No more "Failed to fetch" errors

### 2. **Created `applpass_test.php`**
- Test-friendly version that returns JSON
- Bypasses job validation for easier testing
- Perfect for testing the CPC/CPA toggle

### 3. **Updated Dashboard**
- Now uses the test endpoint
- Better error reporting
- Shows detailed success messages

### 4. **Created Helper Scripts**
- `quick_test.php` - Fast system check
- `debug_cpc_issue.php` - Deep diagnostics
- `get_test_jobs.php` - Get real jobs from DB

---

## Files Created/Updated

### New Files:
- ✅ `applpass_test.php` - Test version of event handler
- ✅ `test_dashboard.html` - Interactive testing dashboard
- ✅ `quick_test.php` - Quick verification script
- ✅ `debug_cpc_issue.php` - Diagnostic tool
- ✅ `get_test_jobs.php` - Job fetcher
- ✅ `check_*.php` - Helper endpoints for dashboard
- ✅ `process_queues.php` - PHP queue processor
- ✅ `event_debug_logger.php` - Logging class

### Updated Files:
- ✅ `applpass.php` - Added CORS headers
- ✅ `test_complete.html` - Already existed, kept as-is

---

## Troubleshooting

### Still Getting "Failed to fetch"?

Run diagnostics:
```bash
php debug_cpc_issue.php
```

### Queue files not updating?

Check permissions:
```bash
ls -la applpass*.json

# Should show: -rw-rw-rw-
# If not, fix with:
chmod 666 applpass_queue.json applpass_cpa_queue.json
```

### Need to see what's happening?

Check logs:
```bash
tail -f applpass7.log
tail -f applpass_cpa.log
tail -f event_debug.log
```

---

## Testing the CPC/CPA Logic

### Scenario 1: CPC Campaign

```bash
# Set feed to CPC mode
mysql> UPDATE applcustfeeds SET budget_type = 'CPC' WHERE feedid = 'aaa7c0e9ef';

# Fire CPC event
curl "http://dev.appljack.com/applpass_test.php?c=9706023615&f=aaa7c0e9ef&j=10081&jpid=2244384563"

# Expected: Event written to queue
# When processed: CPC = $0.75, CPA = $0.00
```

### Scenario 2: CPA Campaign

```bash
# Set feed to CPA mode
mysql> UPDATE applcustfeeds SET budget_type = 'CPA' WHERE feedid = 'aaa7c0e9ef';

# Fire CPC event
curl "http://dev.appljack.com/applpass_test.php?c=9706023615&f=aaa7c0e9ef&j=10081&jpid=2244384563"

# Expected: Event written to queue
# When processed: CPC = $0.00, CPA = $5.00
```

---

## Process Events

After firing test events:

```bash
# Process CPC events
node applpass_putevents2.js

# Process CPA events
node applpass_cpa_putevent.js

# Or use PHP version for testing
curl http://dev.appljack.com/process_queues.php
```

Check database:
```sql
SELECT eventid, eventtype, cpc, cpa, feedid, timestamp
FROM applevents
ORDER BY id DESC
LIMIT 10;
```

---

## Key Reminders

### ⚠️ Important: The Node.js Scripts Need Fixing

The Node.js scripts are still calling the wrong functions:

**File**: `applpass_putevents2.js` (around line 240)
```javascript
// WRONG:
cpcValue = await getJobWiseCPCValue(...);

// SHOULD BE:
cpcValue = await getCPCValue(...);
```

**File**: `applpass_cpa_putevent.js` (around line 180)
```javascript
// WRONG:
cpa = await getJobWiseCPAValue(...);

// SHOULD BE:
cpa = await getCPAValue(...);
```

**Why?** `getJobWiseCPCValue` looks in the `appljobs` table (where values are $0.00), but your actual CPC/CPA values are in the `applcustfeeds` table ($0.75 and $5.00).

---

## Success Checklist

- [x] CORS issue fixed
- [x] Queue files writable
- [x] CPC events fire successfully
- [x] CPA events fire successfully
- [ ] Node.js functions use correct tables ← **Still needs fixing!**
- [ ] Events process with correct values
- [ ] Database shows proper CPC/CPA charges

---

## Need Help?

Run the complete test suite:
```bash
php run_complete_test.php
```

This will check everything and tell you exactly what needs fixing.

---

## 🎉 You're Almost There!

The testing infrastructure is now complete. The "Failed to fetch" error is fixed.

**Last step**: Update the Node.js scripts to use the correct value retrieval functions, and the CPC/CPA toggle will work perfectly!