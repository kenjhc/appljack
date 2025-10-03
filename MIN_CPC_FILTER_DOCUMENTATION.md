# Minimum CPC Filter Feature - Complete Documentation

## 📋 Overview

**Feature Name:** Job Pool Level Minimum CPC Filter  
**Version:** 1.0  
**Date Implemented:** October 3, 2025  
**Purpose:** Filter out low-value jobs at the job pool level before they are imported into the database

---

## 🎯 What This Feature Does

This feature allows you to set a **minimum CPC threshold** for each job pool. During the job import process, any job with a CPC value below the threshold will be **automatically rejected** and will NOT be imported into the database.

### Key Benefits:
- **Reduces Database Storage** - Don't store jobs you'll never use
- **Improves Campaign Quality** - Only high-value jobs make it through
- **Saves Processing Time** - Fewer jobs to process in feed generation
- **Better ROI** - Focus resources on valuable job postings

---

## 🗄️ Database Changes

### Table Modified: `appljobseed`

**Column Added:**

```sql
ALTER TABLE appljobseed 
ADD COLUMN min_cpc DECIMAL(10,2) NULL DEFAULT NULL 
COMMENT 'Minimum CPC filter - jobs below this value will not be imported';
```

| Column | Type | Null | Default | Description |
|--------|------|------|---------|-------------|
| `min_cpc` | DECIMAL(10,2) | YES | NULL | Minimum CPC threshold for importing jobs |

### How It Works:

- **NULL value** = No filter (import all jobs) - **DEFAULT BEHAVIOR**
- **Numeric value** (e.g., 2.50) = Only import jobs with CPC ≥ $2.50
- **Empty/NULL CPC on job** = Job is imported (filter bypassed)

---

## 📁 Files Modified

### 1. **applcreatepool.php** - Create Job Pool Page

**What Changed:**
- Added "Minimum CPC Filter" input field to the create form

**Location:** Lines 81-85

**Code Added:**
```html
<div class="mt-3">
    <label for="min_cpc">Minimum CPC Filter (optional)</label>
    <input type="number" id="min_cpc" name="min_cpc" step="0.01" min="0" 
           placeholder="e.g., 2.50" class="light-input">
    <small class="form-text text-muted">
        Jobs with CPC below this value will not be imported. Leave blank to import all jobs.
    </small>
</div>
```

### 2. **applputpool.php** - Save Job Pool Data

**What Changed:**
- Captures `min_cpc` value from form POST
- Includes it in database INSERT statement

**Location:** Lines 18, 27-28

**Code Added:**
```php
$min_cpc = !empty($_POST['min_cpc']) ? floatval($_POST['min_cpc']) : null;

$stmt = $conn->prepare("INSERT INTO appljobseed (jobpoolid, acctnum, jobpoolname, 
    jobpoolurl, jobpoolfiletype, arbitrage, min_cpc) VALUES (?, ?, ?, ?, ?, ?, ?)");
$stmt->execute([$jobpoolid, $acctnum, $jobpoolname, $jobpoolurl, 
    $jobpoolfiletype, $arbitrage, $min_cpc]);
```

### 3. **appleditjobpool.php** - Edit Job Pool Page

**What Changed:**
- Added POST handler to update `min_cpc` value
- Added query to fetch current `min_cpc` value
- Added UI form card to edit the filter

**Locations:** Lines 101-112, 230-236, 309-318

**POST Handler:**
```php
if (isset($_POST['min_cpc'])) {
    $min_cpc = !empty($_POST['min_cpc']) ? floatval($_POST['min_cpc']) : null;
    $stmt = $conn->prepare("UPDATE appljobseed SET min_cpc = ? WHERE jobpoolid = ?");
    if (!$stmt->execute([$min_cpc, $jobpoolid])) {
        error_log("Database update error: " . implode(", ", $stmt->errorInfo()));
    }
    $error = 'Minimum CPC Filter updated successfully.';
    header("Location: {$_SERVER['PHP_SELF']}?jobpoolid=$jobpoolid");
    exit();
}
```

**Fetch Current Value:**
```php
$stmt = $conn->prepare("SELECT arbitrage, jobpoolname, jobpoolurl, min_cpc 
    FROM appljobseed WHERE jobpoolid = ?");
$stmt->execute([$jobpoolid]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
$currentMinCpc = $row['min_cpc'] ?? '';
```

**UI Form:**
```html
<div class="col-sm-12 col-md-6">
    <div class="job_card">
        <form action="" method="post">
            <p class="job_title">Edit Minimum CPC Filter</p>
            <input type="number" class="job_input" name="min_cpc" 
                   placeholder="e.g., 2.50" step="0.01" min="0" 
                   value="<?= htmlspecialchars($currentMinCpc) ?>">
            <small class="form-text text-muted">
                Jobs with CPC below this value will not be imported. 
                Leave blank to import all jobs.
            </small>
            <button class="update_btn" type="submit">Update Minimum CPC Filter</button>
        </form>
    </div>
</div>
```

### 4. **applupload13.js** - Job Import Script

**What Changed:**
- Fetches `min_cpc` filter setting for each job pool
- Applies filter during XML parsing
- Logs filtered jobs count

**Locations:** Lines 524-544, 630-640, 673-682

**Fetch Filter Setting:**
```javascript
let filteredJobsCount = 0;

// Fetch min_cpc filter for this job pool
let minCpcFilter = null;
try {
    await ensureValidConnection();
    const [minCpcResult] = await tempConnection.query(
        "SELECT min_cpc FROM appljobseed WHERE jobpoolid = ?",
        [jobpoolid]
    );
    if (minCpcResult.length > 0 && minCpcResult[0].min_cpc !== null) {
        minCpcFilter = parseFloat(minCpcResult[0].min_cpc);
        console.log(`Job pool ${jobpoolid}: Minimum CPC filter enabled - $${minCpcFilter}`);
        logMessage(`Job pool ${jobpoolid}: Minimum CPC filter enabled - $${minCpcFilter}`, logFilePath);
    } else {
        console.log(`Job pool ${jobpoolid}: No minimum CPC filter set`);
    }
} catch (err) {
    console.warn(`Could not fetch min_cpc for jobpoolid ${jobpoolid}: ${err}`);
    logMessage(`Could not fetch min_cpc for jobpoolid ${jobpoolid}: ${err}`, logFilePath);
}
```

**Apply Filter Logic:**
```javascript
// Apply minimum CPC filter if configured
if (minCpcFilter !== null) {
    const jobCpc = parseFloat(currentItem.cpc);
    // If CPC is empty/null/NaN, import the job (skip filter check)
    // Otherwise, check if it meets the minimum threshold
    if (!isNaN(jobCpc) && jobCpc < minCpcFilter) {
        filteredJobsCount++;
        currentItem = {}; // Clear current item and skip this job
        return; // Don't add to jobs array
    }
}
```

**Log Results:**
```javascript
// Log CPC filtering results
if (minCpcFilter !== null && filteredJobsCount > 0) {
    console.log(`Jobs filtered out due to CPC < $${minCpcFilter}: ${filteredJobsCount}`);
    logMessage(`Jobs filtered out due to CPC < $${minCpcFilter}: ${filteredJobsCount}`, logFilePath);
    logToDatabase(
        "info",
        "applupload8.js",
        `Job pool ${jobpoolid}: Filtered ${filteredJobsCount} jobs below minimum CPC of $${minCpcFilter}`
    );
}
```

---

## 🔄 Complete Workflow

### 1. **User Sets Filter** (via Web UI)

**Create New Pool:**
```
Navigate to: applcreatepool.php
→ Enter job pool details
→ Set "Minimum CPC Filter" = 2.50
→ Click "Create Job Pool"
→ Filter saved to database
```

**Edit Existing Pool:**
```
Navigate to: appleditjobpool.php?jobpoolid=123456
→ Find "Edit Minimum CPC Filter" card
→ Enter value (e.g., 3.00)
→ Click "Update Minimum CPC Filter"
→ Filter updated in database
```

### 2. **Cron Job Runs** (Every 4 Hours)

**Script Execution Flow:**
```bash
/usr/bin/node applupload13.js
```

**What Happens:**
1. Script reads all XML files from `/feedsclean/` directory
2. For each file (format: `{acctnum}-{jobpoolid}.xml`):
   - Extracts `jobpoolid` from filename
   - Queries database: `SELECT min_cpc FROM appljobseed WHERE jobpoolid = ?`
   - Stores filter value (or NULL if not set)

3. For each `<job>` element in XML:
   - Parse job data (title, location, cpc, etc.)
   - **Apply filter check:**
     ```
     IF min_cpc IS NULL:
         → Import job
     
     IF job.cpc is empty/null:
         → Import job (bypass filter)
     
     IF job.cpc >= min_cpc:
         → Import job
     
     IF job.cpc < min_cpc:
         → Skip job (increment filteredJobsCount)
     ```

4. Insert qualifying jobs into `appljobs` table
5. Log results:
   ```
   Job pool 123456: Minimum CPC filter enabled - $2.50
   Total jobs processed: 453
   Jobs filtered out due to CPC < $2.50: 127
   ```

---

## 📊 Filter Logic Examples

### Example 1: No Filter Set (Default)

**Database:**
```sql
min_cpc = NULL
```

**Results:**
| Job CPC | Imported? | Reason |
|---------|-----------|--------|
| $0.50 | ✅ YES | No filter active |
| $1.00 | ✅ YES | No filter active |
| $5.00 | ✅ YES | No filter active |
| NULL | ✅ YES | No filter active |

### Example 2: Filter Set to $2.50

**Database:**
```sql
min_cpc = 2.50
```

**Results:**
| Job CPC | Imported? | Reason |
|---------|-----------|--------|
| $0.50 | ❌ NO | Below threshold ($0.50 < $2.50) |
| $2.00 | ❌ NO | Below threshold ($2.00 < $2.50) |
| $2.49 | ❌ NO | Below threshold ($2.49 < $2.50) |
| $2.50 | ✅ YES | Meets threshold ($2.50 >= $2.50) |
| $3.00 | ✅ YES | Above threshold ($3.00 >= $2.50) |
| NULL | ✅ YES | Empty CPC bypasses filter |

### Example 3: Filter Set to $0.00

**Database:**
```sql
min_cpc = 0.00
```

**Results:**
| Job CPC | Imported? | Reason |
|---------|-----------|--------|
| $0.00 | ✅ YES | Meets threshold ($0.00 >= $0.00) |
| $0.10 | ✅ YES | Above threshold |
| $5.00 | ✅ YES | Above threshold |
| NULL | ✅ YES | Empty CPC bypasses filter |

---

## 🛠️ Testing Guide

### Manual Testing Steps

#### Test 1: Create Job Pool with Filter

1. Navigate to: `https://appljack.com/admin/applcreatepool.php`
2. Fill in:
   - Job Pool Name: `Test Pool with Filter`
   - File Type: Select `XML` or `CSV`
   - Job Pool URL: `https://example.com/jobs.xml`
   - **Minimum CPC Filter: `2.50`**
3. Click "Create Job Pool"
4. Verify success message

**Verification Query:**
```sql
SELECT jobpoolid, jobpoolname, min_cpc 
FROM appljobseed 
WHERE jobpoolname = 'Test Pool with Filter';
```

Expected: `min_cpc = 2.50`

#### Test 2: Edit Existing Pool to Add Filter

1. Navigate to: `https://appljack.com/admin/jobinventorypool.php`
2. Click "Edit" on any job pool
3. Find "Edit Minimum CPC Filter" card
4. Enter: `3.00`
5. Click "Update Minimum CPC Filter"
6. Verify success message

**Verification Query:**
```sql
SELECT jobpoolid, jobpoolname, min_cpc 
FROM appljobseed 
WHERE jobpoolid = 'YOUR_POOL_ID';
```

Expected: `min_cpc = 3.00`

#### Test 3: Remove Filter (Set to Blank)

1. Edit job pool again
2. Clear the "Minimum CPC Filter" field (leave blank)
3. Click "Update Minimum CPC Filter"

**Verification Query:**
```sql
SELECT min_cpc FROM appljobseed WHERE jobpoolid = 'YOUR_POOL_ID';
```

Expected: `min_cpc = NULL`

#### Test 4: Verify Import Filtering

1. Set a filter on a job pool (e.g., `min_cpc = 2.50`)
2. Run import script manually:
   ```bash
   cd /chroot/home/appljack/appljack.com/html/admin
   /usr/bin/node applupload13.js
   ```
3. Check log file: `applupload8.log`

**Expected Log Output:**
```
Job pool 123456: Minimum CPC filter enabled - $2.50
Total jobs processed: 453
Jobs filtered out due to CPC < $2.50: 127
```

4. Verify in database:
   ```sql
   -- Check that no jobs below threshold were imported
   SELECT COUNT(*) as low_cpc_jobs
   FROM appljobs 
   WHERE jobpoolid = 'YOUR_POOL_ID' 
     AND cpc < 2.50 
     AND cpc IS NOT NULL;
   ```
   Expected: `0` (zero jobs below threshold)

---

## 📈 Useful SQL Queries

### View All Job Pools with Filters

```sql
SELECT 
    jobpoolid,
    jobpoolname,
    min_cpc,
    CASE 
        WHEN min_cpc IS NULL THEN 'No filter'
        ELSE CONCAT('Import only CPC >= $', FORMAT(min_cpc, 2))
    END as filter_description
FROM appljobseed
ORDER BY jobpoolname;
```

### Find Pools with Active Filters

```sql
SELECT 
    jobpoolid,
    jobpoolname,
    min_cpc as threshold,
    CONCAT('$', FORMAT(min_cpc, 2)) as formatted_threshold
FROM appljobseed
WHERE min_cpc IS NOT NULL
ORDER BY min_cpc DESC;
```

### Analyze Current Job CPC Distribution

```sql
SELECT 
    CASE 
        WHEN cpc IS NULL THEN 'No CPC'
        WHEN cpc < 1.00 THEN 'Under $1.00'
        WHEN cpc >= 1.00 AND cpc < 2.00 THEN '$1.00 - $1.99'
        WHEN cpc >= 2.00 AND cpc < 3.00 THEN '$2.00 - $2.99'
        WHEN cpc >= 3.00 AND cpc < 5.00 THEN '$3.00 - $4.99'
        WHEN cpc >= 5.00 THEN '$5.00+'
    END as cpc_range,
    COUNT(*) as job_count
FROM appljobs
GROUP BY cpc_range;
```

### Set Filter for Specific Pool

```sql
-- Enable filter
UPDATE appljobseed SET min_cpc = 2.50 WHERE jobpoolid = 'YOUR_POOL_ID';

-- Remove filter
UPDATE appljobseed SET min_cpc = NULL WHERE jobpoolid = 'YOUR_POOL_ID';
```

### Check Job Stats by Pool

```sql
SELECT 
    js.jobpoolname,
    js.min_cpc as filter_threshold,
    COUNT(aj.id) as total_jobs,
    MIN(aj.cpc) as lowest_cpc,
    MAX(aj.cpc) as highest_cpc,
    AVG(aj.cpc) as avg_cpc
FROM appljobseed js
LEFT JOIN appljobs aj ON js.jobpoolid = aj.jobpoolid
WHERE js.jobpoolid = 'YOUR_POOL_ID'
GROUP BY js.jobpoolname, js.min_cpc;
```

---

## 🔍 Troubleshooting

### Issue: Filter not working

**Check 1: Is min_cpc set correctly?**
```sql
SELECT min_cpc FROM appljobseed WHERE jobpoolid = 'YOUR_POOL_ID';
```

**Check 2: Check import logs**
```bash
tail -n 100 /chroot/home/appljack/appljack.com/html/admin/applupload8.log
```

Look for: `Job pool XXX: Minimum CPC filter enabled - $X.XX`

**Check 3: Verify jobs have CPC values in source XML**
- Open the XML file for the job pool
- Verify `<cpc>` tags exist and have numeric values

### Issue: All jobs being imported despite filter

**Possible Causes:**
1. `min_cpc` is NULL in database
2. Jobs in XML don't have `<cpc>` tags (empty CPC bypasses filter)
3. Import script hasn't run since filter was set

**Solution:**
```sql
-- Verify filter is set
SELECT min_cpc FROM appljobseed WHERE jobpoolid = 'YOUR_POOL_ID';

-- If NULL, set it:
UPDATE appljobseed SET min_cpc = 2.50 WHERE jobpoolid = 'YOUR_POOL_ID';

-- Then wait for next cron run or trigger manually
```

### Issue: No jobs being imported at all

**Check 1: Is threshold too high?**
```sql
-- Check what CPC values are in the feed
SELECT MIN(cpc), MAX(cpc), AVG(cpc) 
FROM appljobs 
WHERE jobpoolid = 'YOUR_POOL_ID';
```

**Check 2: Lower or remove filter**
```sql
-- Remove filter to import all jobs
UPDATE appljobseed SET min_cpc = NULL WHERE jobpoolid = 'YOUR_POOL_ID';
```

---

## 📝 Important Notes

### Behavior Details

1. **Empty/NULL CPC Values:**
   - Jobs without CPC values are ALWAYS imported
   - Filter only applies to jobs with numeric CPC values
   - This prevents losing jobs from feeds that don't provide CPC

2. **Threshold Comparison:**
   - Uses `>=` (greater than or equal)
   - Job with CPC exactly equal to threshold IS imported
   - Example: If threshold = $2.50, job with CPC = $2.50 is imported

3. **Backward Compatibility:**
   - Existing job pools have `min_cpc = NULL`
   - NULL means no filter (import all jobs)
   - This maintains existing behavior

4. **Performance:**
   - Filter is applied during XML parsing
   - Rejected jobs never touch the database
   - No performance impact on existing pools without filters

5. **Logging:**
   - Filter activity logged in `applupload8.log`
   - Also logged to `appl_logs` table
   - Shows count of filtered jobs per pool

---

## 🔄 Rollback Procedure

If you need to remove this feature completely:

### Step 1: Backup Current Settings

```sql
-- Save filter settings to file
SELECT jobpoolid, jobpoolname, min_cpc 
FROM appljobseed 
WHERE min_cpc IS NOT NULL
INTO OUTFILE '/tmp/mincpc_backup.csv'
FIELDS TERMINATED BY ',' 
ENCLOSED BY '"'
LINES TERMINATED BY '\n';
```

### Step 2: Remove Database Column

```sql
ALTER TABLE appljobseed DROP COLUMN min_cpc;
```

### Step 3: Revert Code Changes

1. Restore previous versions of modified files
2. Or manually remove the min_cpc related code sections

---

## 📞 Support Information

### Log Files to Check

- **Import Logs:** `/chroot/home/appljack/appljack.com/html/admin/applupload8.log`
- **Database Logs:** `appl_logs` table
- **Error Logs:** `appl_logs_errors.log`

### Key Search Terms in Logs

- `Minimum CPC filter enabled`
- `Jobs filtered out due to CPC`
- `No minimum CPC filter set`

### Database Tables Involved

- `appljobseed` - Stores the min_cpc filter setting
- `appljobs` - Contains imported jobs (filtered by min_cpc)
- `appl_logs` - Contains filter activity logs

---

## ✅ Summary

This feature adds job pool level CPC filtering to prevent low-value jobs from being imported. It's:

- **Simple:** Just set a dollar threshold
- **Safe:** NULL value = no filter (default behavior)
- **Efficient:** Filters before database import
- **Flexible:** Can be enabled/disabled per pool
- **Logged:** Full activity tracking
- **Backward Compatible:** No impact on existing pools

**Implementation Date:** October 3, 2025  
**Status:** Production Ready ✅

