# 🔧 FIX: CPC/CPA Value Retrieval Issue

## The Problem

When `budget_type = "CPC"`, the code calls `getJobWiseCPCValue()` which looks in the `appljobs` table.
But your CPC value ($0.75) is in the `applcustfeeds` table!

## Current Logic (INCORRECT)

In `applpass_putevents2.js`:
```javascript
if (budgetType === "CPA") {
    // CPA campaigns don't charge for clicks
    cpcValue = 0.0;
} else {
    // This looks in appljobs table - WRONG!
    cpcValue = await getJobWiseCPCValue(...);
}
```

## What It Should Be

```javascript
if (budgetType === "CPA") {
    // CPA campaigns don't charge for clicks
    cpcValue = 0.0;
} else {
    // CPC campaigns - get value from feed first
    cpcValue = await getCPCValue(...);  // This checks applcustfeeds
}
```

## The Fix

In `applpass_putevents2.js`, around line 240, change:

```javascript
// WRONG - looks in appljobs
cpcValue = await getJobWiseCPCValue(
    connection,
    eventData.feedid,
    eventData.job_reference,
    eventData.jobpoolid
);
```

To:

```javascript
// CORRECT - looks in applcustfeeds
cpcValue = await getCPCValue(
    connection,
    eventData.feedid,
    eventData.job_reference,
    eventData.jobpoolid
);
```

## Similarly for CPA

In `applpass_cpa_putevent.js`, around line 180, change:

```javascript
// WRONG - looks in appljobs
cpa = await getJobWiseCPAValue(...);
```

To:

```javascript
// CORRECT - looks in applcustfeeds
cpa = await getCPAValue(...);
```

## Summary

The functions should be:
- `getCPCValue()` - Gets from `applcustfeeds` table (where your $0.75 is)
- `getJobWiseCPCValue()` - Gets from `appljobs` table (fallback)

Currently it's using the wrong function!