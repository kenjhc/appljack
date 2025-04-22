// stats_updater.js
const mysql = require('mysql2/promise');
const config = require("./config");

const dbConfig = {
  host: config.host,
  user: config.username,
  password: config.password,
  database: config.database,
};

async function updateCustomerStats() {
  let connection;

  try {
    // Create database connection
    connection = await mysql.createConnection(dbConfig);

    console.log("Connected to database. Starting stats update...");

    // Define the date ranges we want to calculate
    const now = new Date();
    const dateRanges = [
      {
        period: 'today',
        startDate: new Date(now.getFullYear(), now.getMonth(), now.getDate(), 0, 0, 0),
        endDate: new Date(now.getFullYear(), now.getMonth(), now.getDate(), 23, 59, 59)
      },
      {
        period: 'yesterday',
        startDate: new Date(now.getFullYear(), now.getMonth(), now.getDate() - 1, 0, 0, 0),
        endDate: new Date(now.getFullYear(), now.getMonth(), now.getDate() - 1, 23, 59, 59)
      },
      {
        period: 'last7days',
        startDate: new Date(now.getFullYear(), now.getMonth(), now.getDate() - 6, 0, 0, 0),
        endDate: new Date(now.getFullYear(), now.getMonth(), now.getDate(), 23, 59, 59)
      },
      {
        period: 'thismonth',
        startDate: new Date(now.getFullYear(), now.getMonth(), 1, 0, 0, 0),
        endDate: new Date(now.getFullYear(), now.getMonth() + 1, 0, 23, 59, 59)
      },
      {
        period: 'lastmonth',
        startDate: new Date(now.getFullYear(), now.getMonth() - 1, 1, 0, 0, 0),
        endDate: new Date(now.getFullYear(), now.getMonth(), 0, 23, 59, 59)
      }
    ];

    // Get all customers
    const [customers] = await connection.execute(
      "SELECT custid, custcompany, acctnum FROM applcust ORDER BY custcompany ASC"
    );

    console.log(`Processing stats for ${customers.length} customers across ${dateRanges.length} date ranges...`);

    // Process each date range
    for (const dateRange of dateRanges) {
      console.log(`Processing date range: ${dateRange.period}`);

      const startDateStr = dateRange.startDate.toISOString().slice(0, 10) + " 00:00:00";
      const endDateStr = dateRange.endDate.toISOString().slice(0, 10) + " 23:59:59";

      // Process each customer for this date range
      for (const customer of customers) {
        try {
          const custId = customer.custid;
          const custCompany = customer.custcompany || '[No Name]';

          // 1. Calculate Status
          const [statusResult] = await connection.execute(
            "SELECT COUNT(*) as count FROM applcustfeeds WHERE custid = ? AND status = 'active'",
            [custId]
          );
          const status = statusResult[0].count > 0 ? 'Active' : 'Inactive';

          // 2. Calculate Budget
          const [budgetResult] = await connection.execute(
            "SELECT SUM(budget) as total FROM applcustfeeds WHERE custid = ?",
            [custId]
          );
          const budget = Number(budgetResult[0].total) || 0;

          // 3. Calculate Spend, Clicks, and Applies
          const [eventResult] = await connection.execute(
            `SELECT
              SUM(CASE WHEN eventtype = 'cpc' THEN cpc ELSE 0 END) AS total_cpc,
              SUM(CASE WHEN eventtype = 'cpa' THEN cpa ELSE 0 END) AS total_cpa,
              COUNT(CASE WHEN eventtype = 'cpc' THEN 1 ELSE NULL END) AS clicks,
              COUNT(CASE WHEN eventtype = 'cpa' THEN 1 ELSE NULL END) AS applies
            FROM applevents
            WHERE custid = ? AND timestamp BETWEEN ? AND ?`,
            [custId, startDateStr, endDateStr]
          );

          const total_cpc = Number(eventResult[0].total_cpc) || 0;
          const total_cpa = Number(eventResult[0].total_cpa) || 0;
          const clicks = Number(eventResult[0].clicks) || 0;
          const applies = Number(eventResult[0].applies) || 0;
          const spend = total_cpc + total_cpa;

          // 4. Calculate CPA and CPC
          const cpa = applies > 0 ? spend / applies : 0;
          const cpc = clicks > 0 ? spend / clicks : 0;

          // 5. Calculate Conversion Rate
          const conversion_rate = clicks > 0 ? (applies / clicks) * 100 : 0;

          // 6. Calculate Number of Jobs
          const [jobsResult] = await connection.execute(
            "SELECT SUM(numjobs) as total FROM applcustfeeds WHERE custid = ?",
            [custId]
          );
          const numJobs = Number(jobsResult[0].total) || 0;

          // 7. Insert or update the stats record
          await connection.execute(
            `INSERT INTO applcust_stats
              (custid, date_period, status, budget, spend, clicks, applies, cpa, cpc, conversion_rate, numjobs)
            VALUES
              (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
              status = VALUES(status),
              budget = VALUES(budget),
              spend = VALUES(spend),
              clicks = VALUES(clicks),
              applies = VALUES(applies),
              cpa = VALUES(cpa),
              cpc = VALUES(cpc),
              conversion_rate = VALUES(conversion_rate),
              numjobs = VALUES(numjobs),
              last_updated = CURRENT_TIMESTAMP`,
            [
              custId,
              dateRange.period,
              status,
              budget,
              spend,
              clicks,
              applies,
              cpa,
              cpc,
              conversion_rate,
              numJobs
            ]
          );
        } catch (customerError) {
          console.error(`Error processing customer ${customer.custcompany || '[No Name]'} (ID: ${customer.custid}) for period ${dateRange.period}:`, customerError.message);
        }
      }

      console.log(`Completed date range: ${dateRange.period}`);
    }

    console.log(`Stats update process completed. Timestamp: ${new Date().toISOString()}`);
  } catch (error) {
    console.error("Error updating customer stats:", error);
  } finally {
    if (connection) {
      await connection.end();
      console.log("Database connection closed.");
    }
  }
}

// Execute the update function
updateCustomerStats();
