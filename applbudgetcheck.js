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
  host: "69.167.170.128",
  port: 587,
  auth: {
    user: "budgets@appljack.com",
    pass: "@pp13budg3t$01$",
  },
  tls: {
    rejectUnauthorized: false,
  },
});

const updateFeedStatus = async () => {
  let connection;
  try {
    const currentDate = new Date();
    const yearMonthDay = currentDate.toISOString().slice(0, 10); // Daily check date format
    const yearMonth = currentDate.toISOString().slice(0, 7); // Monthly check date format

    connection = await pool.getConnection();

    const [feeds] = await connection.query("SELECT * FROM applcustfeeds");

    // const [feeds] = await connection.query(
    //   "SELECT * FROM applcustfeeds"
    // );

    for (const feed of feeds) {
      // Skip checking if the feed status is 'stopped'
      if (feed.status === "stopped") {
        console.log(
          `Feed ID ${feed.feedid} is stopped. No budget checks performed.`
        );
        logToDatabase(
          "warning",
          "applbudgetcheck.js",
          `Feed ID ${feed.feedid} is stopped. No budget checks performed.`
        );
        continue;
      }
      // Get customer email and publisher details
      const [customerData] = await connection.query(
        `SELECT c.acctemail, CONCAT(c.acctfname, ' ', c.acctlname) AS customerFullName FROM applacct c WHERE c.acctnum = ?`,
        [feed.acctnum]
      );

      feed.customerEmail = customerData[0]?.acctemail;
      feed.customerName = customerData[0]?.customerFullName;
      feed.customerID = feed.acctnum;
 
      const [publisherData] = await connection.query(
        `SELECT p.publisher_contact_email, p.publishername FROM applpubs p WHERE p.publisherid = ?`,
        [feed.activepubs]
      );
      feed.publisherEmail = publisherData[0]?.publisher_contact_email;
      feed.publisherName = publisherData[0]?.publishername;

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
      await sendEmail(feed, "90%");

      if (monthlyBudget) {
        if (monthlyTotal >= monthlyBudget * 0.95) {
          await connection.query(
            "UPDATE applcustfeeds SET status = ? WHERE feedid = ?",
            ["capped", feed.feedid]
          );
          console.log(
            `Monthly status changed to 'capped' for feed ID ${feed.feedid} at 95% budget`
          );
          logToDatabase(
            "warning",
            "applbudgetcheck.js",
            `Monthly status changed to 'capped' for feed ID ${feed.feedid} at 95% budget`
          );
        } else {
          await connection.query(
            "UPDATE applcustfeeds SET status = ? WHERE feedid = ?",
            ["active", feed.feedid]
          );
          console.log(
            `Monthly status changed to 'active' for feed ID ${feed.feedid}`
          );
          logToDatabase(
            "warning",
            "applbudgetcheck.js",
            `Monthly status changed to 'active' for feed ID ${feed.feedid}`
          );
        }

        if (monthlyTotal >= monthlyBudget * 0.9) {
          await sendEmail(feed, "90%");
        } else if (monthlyTotal >= monthlyBudget * 0.75) {
          await sendEmail(feed, "75%");
        }
        console.log(
          `Feed ID ${
            feed.feedid
          }: Monthly total is ${monthlyTotal}, 95% budget cap is ${
            monthlyBudget * 0.95
          }`
        );
        logToDatabase(
          "success",
          "applbudgetcheck.js",
          `Feed ID ${
            feed.feedid
          }: Monthly total is ${monthlyTotal}, 95% budget cap is ${
            monthlyBudget * 0.95
          }`
        );
      }

      // Daily budget check (regardless of monthly budget status)
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
          console.log(
            `Daily status changed to 'capped' for feed ID ${feed.feedid}`
          );
        } else if (feed.status !== "capped") {
          await connection.query(
            "UPDATE applcustfeeds SET status = ? WHERE feedid = ?",
            ["active", feed.feedid]
          );
          console.log(
            `Daily status changed to 'active' for feed ID ${feed.feedid}`
          );
        }
        console.log(
          `Feed ID ${feed.feedid}: Daily total is ${dailyTotal}, daily budget is ${feed.dailybudget}`
        );
      } else {
        console.log(
          `No valid daily budget set for feed ID ${feed.feedid}; daily status check skipped.`
        );
      }
    }

    console.log("Feed statuses updated successfully.");
    process.exit(0); // Exit successfully
  } catch (error) {
    console.error("An error occurred:", error.message);
    logToDatabase(
      "error",
      "applbudgetcheck.js",
      "An error occurred:",
      error.message
    );
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
      // "mannananxari@gmail.com",
      // "odit33959@gmail.com",
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
