const mysql = require("mysql2/promise");
const config = require("./config");
const nodemailer = require("nodemailer");
const emailTemplates = require("./emailTemplates");
const { logToDatabase } = require("./utils/helpers");
// const emailTemplates = require("./emailTemplates");

// Configure your database connection here
const pool = mysql.createPool({
  host: config.host,
  user: config.username,
  password: config.password,
  database: config.database,
  charset: config.charset,
});

const transporter = nodemailer.createTransport({
  // service: 'cloudvpsserver.host.jobhubcentral.com',
  host: "67.225.189.14",
  port: 587,
  auth: {
    user: "budgets@appljack.com",
    pass: "@pp13budg3t$01$",
  },
  tls: {
    rejectUnauthorized: false,
  },
});

const checkStartAndEndDates = async (connection, feeds) => {
  const currentTimestamp = new Date();

  for (const feed of feeds) {
    // Log the feed being processed
    console.log(`Processing feed ID ${feed.feedid}...`);
    logToDatabase("info", "applbudgetcheck.js", `Processing feed ID ${feed.feedid}...`);
  
    // Skip if both date_start and date_end are missing or null
    if (!feed.date_start && !feed.date_end) {
      console.log(`Feed ID ${feed.feedid} skipped: Both date_start and date_end are null.`);
      logToDatabase("info", "applbudgetcheck.js", `Feed ID ${feed.feedid} skipped: Both date_start and date_end are null.`);
      continue;
    }
  
    // Handle date_start logic
    if (feed.date_start) {
      const startDate = new Date(feed.date_start);
      console.log(`Feed ID ${feed.feedid}: date_start = ${startDate}, currentTimestamp = ${currentTimestamp}`);
      logToDatabase("info", "applbudgetcheck.js", `Feed ID ${feed.feedid}: date_start = ${startDate}, currentTimestamp = ${currentTimestamp}`);
  
      if (startDate <= currentTimestamp && feed.status == "date stopped") {
        await connection.query(
          "UPDATE applcustfeeds SET status = ? WHERE feedid = ?",
          ["active", feed.feedid]
        );
        console.log(`Feed ID ${feed.feedid} status changed to 'active' (start date condition).`);
        logToDatabase("info", "applbudgetcheck.js", `Feed ID ${feed.feedid} status changed to 'active' (start date condition).`);
      } else {
        console.log(`Feed ID ${feed.feedid}: No status change (start date condition).`);
        logToDatabase("info", "applbudgetcheck.js", `Feed ID ${feed.feedid}: No status change (start date condition).`);
      }
    }
  
    // Handle date_end logic
    if (feed.date_end) {
      const endDate = new Date(feed.date_end);
      console.log(`Feed ID ${feed.feedid}: date_end = ${endDate}, currentTimestamp = ${currentTimestamp}`);
      logToDatabase("info", "applbudgetcheck.js", `Feed ID ${feed.feedid}: date_end = ${endDate}, currentTimestamp = ${currentTimestamp}`);
  
      if (
        endDate <= currentTimestamp &&
        feed.status !== "capped" &&
        feed.status !== "stopped"
      ) {
        await connection.query(
          "UPDATE applcustfeeds SET status = ? WHERE feedid = ?",
          ["date stopped", feed.feedid]
        );
        console.log(`Feed ID ${feed.feedid} status changed to 'date stopped' (end date condition).`);
        logToDatabase("info", "applbudgetcheck.js", `Feed ID ${feed.feedid} status changed to 'date stopped' (end date condition).`);
      } else {
        console.log(`Feed ID ${feed.feedid}: No status change (end date condition).`);
        logToDatabase("info", "applbudgetcheck.js", `Feed ID ${feed.feedid}: No status change (end date condition).`);
      }
    }
  
    console.log(`Finished processing feed ID ${feed.feedid}.`);
    logToDatabase("info", "applbudgetcheck.js", `Finished processing feed ID ${feed.feedid}.`);
  }
  
};



const updateFeedStatus = async () => {
  let connection;
  try {
    const currentDate = new Date();
    const yearMonthDay = currentDate.toISOString().slice(0, 10); // Daily check date format
    const yearMonth = currentDate.toISOString().slice(0, 7); // Monthly check date format

    connection = await pool.getConnection();

    const [feeds] = await connection.query("SELECT * FROM applcustfeeds");

    // Prioritize start and end date checks
    await checkStartAndEndDates(connection, feeds);

    for (const feed of feeds) {
      const startDate = feed.date_start ? new Date(feed.date_start) : null;
      const currentTimestamp = new Date();

      // Ensure budget checks only apply if start_date is valid
      if (startDate && startDate > currentTimestamp) {
        console.log(`Feed ID ${feed.feedid} skipped: start_date (${startDate.toISOString()}) is in the future.`);
        logToDatabase(
          "info",
          "applbudgetcheck.js",
          `Feed ID ${feed.feedid} skipped: start_date (${startDate.toISOString()}) is in the future.`
        );
        continue;
      }

      // Skip checking if the feed status is 'stopped'
      if (feed.status === "stopped") {
        console.log(`Feed ID ${feed.feedid} is stopped. No budget checks performed.`);
        logToDatabase("warning", "applbudgetcheck.js", `Feed ID ${feed.feedid} is stopped. No budget checks performed.`);
        continue;
      }

      // Monthly budget check
      const [monthlySumResult] = await connection.query(
        `
        SELECT SUM(cpc + cpa) AS total
        FROM applevents
        WHERE feedid = ?
          AND timestamp >= ?
          AND timestamp < LAST_DAY(?) + INTERVAL 1 DAY
        `,
        [feed.feedid, `${yearMonth}-01`, `${yearMonth}-01`]
      );
      const monthlyTotal = parseFloat(monthlySumResult[0].total) || 0;
      const monthlyBudget = parseFloat(feed.budget);

      if (monthlyBudget) {
        if (monthlyTotal >= monthlyBudget * 0.95) {
          await connection.query(
            "UPDATE applcustfeeds SET status = ? WHERE feedid = ?",
            ["capped", feed.feedid]
          );
          console.log(`Monthly status changed to 'capped' for feed ID ${feed.feedid} at 95% budget.`);
        } else if (feed.status !== "capped") {
          await connection.query(
            "UPDATE applcustfeeds SET status = ? WHERE feedid = ?",
            ["active", feed.feedid]
          );
          console.log(`Monthly status changed to 'active' for feed ID ${feed.feedid}.`);
        }
      }

      // Daily budget check
      if (
        feed.dailybudget !== null &&
        feed.dailybudget !== "" &&
        !isNaN(parseFloat(feed.dailybudget))
      ) {
        const [dailySumResult] = await connection.query(
          `SELECT SUM(cpc + cpa) AS total FROM applevents WHERE feedid = ? AND DATE(timestamp) = ?`,
          [feed.feedid, yearMonthDay]
        );
        const dailyTotal = parseFloat(dailySumResult[0].total) || 0;

        if (dailyTotal >= parseFloat(feed.dailybudget)) {
          await connection.query(
            "UPDATE applcustfeeds SET status = ? WHERE feedid = ?",
            ["capped", feed.feedid]
          );
          console.log(`Daily status changed to 'capped' for feed ID ${feed.feedid}.`);
        } else if (feed.status !== "capped") {
          await connection.query(
            "UPDATE applcustfeeds SET status = ? WHERE feedid = ?",
            ["active", feed.feedid]
          );
          console.log(`Daily status changed to 'active' for feed ID ${feed.feedid}.`);
        }
        console.log(`Feed ID ${feed.feedid}: Daily total is ${dailyTotal}, daily budget is ${feed.dailybudget}.`);
      } else {
        console.log(`No valid daily budget set for feed ID ${feed.feedid}; daily status check skipped.`);
      }
    }

    console.log("Feed statuses updated successfully.");
    process.exit(0); // Exit successfully
  } catch (error) {
    console.error("An error occurred:", error.message);
    logToDatabase("error", "applbudgetcheck.js", `An error occurred: ${error.message}`);
    process.exit(1); // Exit with error
  } finally {
    if (connection) await connection.release();
    await pool.end();
    console.log("Database connection closed.");
    process.exit(0);
  }
};


// Function to send emails at 75% and 90% budget thresholds
const sendEmail = async (feed, percentage) => {
  const emailOptions = {
    from: "budgets@appljack.com",
    to: [
      feed.customerEmail,
      feed.publisherEmail || null,
      "budgets@appljack.com",
    ]
      .filter(Boolean)
      .join(", "),
    subject: `Appljack Reminder: One of Your Campaigns is at ${percentage} of Budget`,
    html: emailTemplates.generateEmailContent(feed, percentage),
  };

  try {
    await transporter.sendMail(emailOptions);
    console.log(
      `Email sent for feed ID ${feed.feedid} at ${percentage} budget.`
    );
    logToDatabase(
      "success",
      "applbudgetcheck.js",
      `Email sent for feed ID ${feed.feedid} at ${percentage} budget.`
    );
  } catch (error) {
    console.error(
      `Failed to send email for feed ID ${feed.feedid} at ${percentage} budget:`,
      error.message
    );
    logToDatabase(
      "error",
      "applbudgetcheck.js",
      `Failed to send email for feed ID ${feed.feedid} at ${percentage} budget: ` +
        error.message
    );
  }
};

console.log("Running applbudgetcheck.js script...");

updateFeedStatus();
