-- ============================================
-- MIGRATION: Change CPC Filter to CPA Filter
-- ============================================
-- Date: 2025-10-03
-- Purpose: Rename min_cpc column to min_cpa per client request

-- Step 1: Rename the column from min_cpc to min_cpa
ALTER TABLE appljobseed 
CHANGE COLUMN min_cpc min_cpa DECIMAL(10,2) NULL DEFAULT NULL 
COMMENT 'Minimum CPA filter - jobs below this value will not be imported';

-- Step 2: Verify the change
SELECT 
    jobpoolid,
    jobpoolname,
    min_cpa,
    CASE 
        WHEN min_cpa IS NULL THEN 'No filter'
        ELSE CONCAT('Filter: CPA >= $', FORMAT(min_cpa, 2))
    END as filter_status
FROM appljobseed
WHERE min_cpa IS NOT NULL
LIMIT 10;

-- If column doesn't exist (fresh install), create it:
-- ALTER TABLE appljobseed 
-- ADD COLUMN min_cpa DECIMAL(10,2) NULL DEFAULT NULL 
-- COMMENT 'Minimum CPA filter - jobs below this value will not be imported';

