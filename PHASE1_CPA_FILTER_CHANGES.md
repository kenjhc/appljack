# Phase 1: CPC to CPA Filter Migration - Complete

## 🎯 What Changed

Per client request, the minimum filter has been **changed from CPC to CPA**. This is Phase 1 of a two-phase rollout.

**Phase 1 (Current):** CPA filtering only  
**Phase 2 (Future):** Add CPC back with AND/OR logic option

---

## 📋 Summary of Changes

### Database Migration
- **Column renamed:** `min_cpc` → `min_cpa`
- **Type:** DECIMAL(10,2) NULL DEFAULT NULL
- **Comment:** "Minimum CPA filter - jobs below this value will not be imported"

### Files Modified

#### 1. **applcreatepool.php**
- Changed label from "Minimum CPC Filter" to "Minimum CPA Filter"
- Changed input field: `name="min_cpc"` → `name="min_cpa"`
- Updated helper text to reference CPA instead of CPC

#### 2. **applputpool.php**
- Changed variable: `$min_cpc` → `$min_cpa`
- Changed POST parameter: `$_POST['min_cpc']` → `$_POST['min_cpa']`
- Updated INSERT query to use `min_cpa` column

#### 3. **appleditjobpool.php**
- Changed POST handler to process `min_cpa` instead of `min_cpc`
- Updated UPDATE query to set `min_cpa` column
- Changed SELECT query to fetch `min_cpa` value
- Changed variable: `$currentMinCpc` → `$currentMinCpa`
- Updated UI form label from "Minimum CPC Filter" to "Minimum CPA Filter"
- Changed input field: `name="min_cpc"` → `name="min_cpa"`
- Updated helper text to reference CPA

#### 4. **applupload13.js** (CRITICAL - Import Logic)
- Changed variable: `minCpcFilter` → `minCpaFilter`
- Changed query: `SELECT min_cpc` → `SELECT min_cpa`
- **Changed filtering logic:** Now checks `currentItem.cpa` instead of `currentItem.cpc`
- Updated console logs to show "CPA" instead of "CPC"
- Updated database logs to reference CPA filtering

---

## 🔄 How It Works Now

### Filter Logic (Changed)
```javascript
// OLD (CPC):
const jobCpc = parseFloat(currentItem.cpc);
if (!isNaN(jobCpc) && jobCpc < minCpcFilter) {
    // Skip job
}

// NEW (CPA):
const jobCpa = parseFloat(currentItem.cpa);
if (!isNaN(jobCpa) && jobCpa < minCpaFilter) {
    // Skip job
}
```

### Example Scenarios

**Scenario 1: No Filter Set**
```
min_cpa = NULL
→ All jobs imported (no filtering)
```

**Scenario 2: Filter Set to $2.50**
```
min_cpa = 2.50

Job with cpa = $1.00  → ❌ FILTERED OUT
Job with cpa = $2.50  → ✅ IMPORTED (equal counts)
Job with cpa = $3.00  → ✅ IMPORTED
Job with cpa = NULL   → ✅ IMPORTED (empty bypasses filter)
```

---

## 🛠️ Migration Instructions

### Step 1: Run Database Migration

```sql
-- Execute this SQL on production database:
ALTER TABLE appljobseed 
CHANGE COLUMN min_cpc min_cpa DECIMAL(10,2) NULL DEFAULT NULL 
COMMENT 'Minimum CPA filter - jobs below this value will not be imported';
```

**Verification:**
```sql
-- Check that column was renamed
SHOW COLUMNS FROM appljobseed LIKE 'min_cpa';

-- Check existing filters (they should be preserved)
SELECT jobpoolid, jobpoolname, min_cpa 
FROM appljobseed 
WHERE min_cpa IS NOT NULL;
```

### Step 2: Deploy Code Changes

Deploy all modified files:
- `applcreatepool.php`
- `applputpool.php`
- `appleditjobpool.php`
- `applupload13.js`

### Step 3: Test the Changes

#### Test 1: Create Job Pool with CPA Filter
1. Navigate to: `/admin/applcreatepool.php`
2. Create a new job pool
3. Set "Minimum CPA Filter" to `2.50`
4. Verify it saves successfully

**Verification Query:**
```sql
SELECT jobpoolid, jobpoolname, min_cpa 
FROM appljobseed 
WHERE jobpoolname = 'YOUR_TEST_POOL_NAME';
```

#### Test 2: Edit Existing Pool
1. Navigate to: `/admin/appleditjobpool.php?jobpoolid=XXX`
2. Find "Edit Minimum CPA Filter" card
3. Update value to `3.00`
4. Verify success message

#### Test 3: Verify Import Filtering
1. Wait for next cron run or manually trigger:
```bash
cd /chroot/home/appljack/appljack.com/html/admin
/usr/bin/node applupload13.js
```

2. Check logs: `applupload8.log`

**Expected Log Output:**
```
Job pool 123456: Minimum CPA filter enabled - $2.50
Total jobs processed: 453
Jobs filtered out due to CPA < $2.50: 127
```

3. Verify in database:
```sql
-- No jobs below threshold should be imported
SELECT COUNT(*) as low_cpa_jobs
FROM appljobs 
WHERE jobpoolid = 'YOUR_POOL_ID' 
  AND cpa < 2.50 
  AND cpa IS NOT NULL;

-- Should return 0
```

---

## ⚠️ Important Notes

### What Changed
1. **Filter field:** CPC → CPA
2. **Database column:** min_cpc → min_cpa
3. **Job field checked:** job.cpc → job.cpa
4. **All UI labels:** "CPC" → "CPA"

### What Stayed the Same
1. **Filter logic:** Still uses >= comparison
2. **NULL handling:** Empty CPA values still bypass filter
3. **Performance:** Same efficiency
4. **Default behavior:** NULL = no filter

### Existing Filters
- Any existing `min_cpc` values will be **preserved** as `min_cpa`
- Job pools with filters will continue working (now filtering on CPA)
- No data loss during migration

---

## 📝 Client Communication

### What to Tell the Client

**Phase 1 is Complete:**
✅ Filter now works on **CPA** (Cost Per Application) instead of CPC  
✅ Same interface and functionality  
✅ Existing filter values preserved  
✅ Jobs below CPA threshold are filtered out during import  

**Example:**
"If you set Minimum CPA Filter to $2.50, only jobs with CPA of $2.50 or higher will be imported. Jobs with CPA below $2.50 will be automatically rejected and not stored in the database."

**Next Phase (Phase 2):**
We will add back CPC filtering with an option to choose:
- CPA only (current behavior)
- CPC only
- Both CPA AND CPC (must meet both thresholds)
- Either CPA OR CPC (must meet at least one threshold)

---

## 🔍 Troubleshooting

### Issue: Filter not working after migration

**Check 1: Was database migrated?**
```sql
SHOW COLUMNS FROM appljobseed LIKE 'min_%';
-- Should show min_cpa, not min_cpc
```

**Check 2: Do jobs have CPA values?**
```sql
SELECT COUNT(*) as jobs_with_cpa
FROM appljobs 
WHERE cpa IS NOT NULL;
```

**Check 3: Check import logs**
```bash
tail -n 100 /chroot/home/appljack/appljack.com/html/admin/applupload8.log | grep -i cpa
```

### Issue: Old filters disappeared

**Solution:**
If you see `min_cpc` column still exists:
```sql
-- Copy data from min_cpc to min_cpa
UPDATE appljobseed 
SET min_cpa = min_cpc 
WHERE min_cpc IS NOT NULL AND min_cpa IS NULL;

-- Then drop old column
ALTER TABLE appljobseed DROP COLUMN min_cpc;
```

---

## ✅ Deployment Checklist

- [ ] Run database migration SQL
- [ ] Verify min_cpa column exists
- [ ] Verify existing filters preserved
- [ ] Deploy updated PHP files
- [ ] Deploy updated JS file (applupload13.js)
- [ ] Test creating new pool with CPA filter
- [ ] Test editing existing pool
- [ ] Test import filtering (check logs)
- [ ] Verify jobs below threshold are NOT imported
- [ ] Verify jobs above threshold ARE imported
- [ ] Document Phase 2 requirements

---

## 🚀 Phase 2 Planning (Future)

### Requirements for Phase 2:
1. Add back `min_cpc` column to database
2. Add CPC filter input fields to UI forms
3. Add logic selector dropdown (AND/OR)
4. Update applupload13.js to check both CPC and CPA
5. Implement AND/OR logic:
   - **AND:** Job must meet BOTH thresholds
   - **OR:** Job must meet AT LEAST ONE threshold

### Proposed UI (Phase 2):
```
Minimum CPA Filter: [2.50]
Minimum CPC Filter: [0.50]
Logic: [▼ OR ] (dropdown: AND / OR)
```

### Proposed Logic (Phase 2):
```javascript
if (minCpaFilter !== null || minCpcFilter !== null) {
    const jobCpa = parseFloat(currentItem.cpa);
    const jobCpc = parseFloat(currentItem.cpc);
    
    if (filterLogic === 'AND') {
        // Must meet BOTH thresholds
        if ((minCpaFilter && jobCpa < minCpaFilter) || 
            (minCpcFilter && jobCpc < minCpcFilter)) {
            // Skip job
        }
    } else { // OR
        // Must meet AT LEAST ONE threshold
        if ((minCpaFilter && jobCpa < minCpaFilter) && 
            (minCpcFilter && jobCpc < minCpcFilter)) {
            // Skip job
        }
    }
}
```

---

**Phase 1 Status:** ✅ COMPLETE  
**Phase 2 Status:** 📋 PLANNED  
**Date:** October 3, 2025

