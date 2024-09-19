// require('dotenv').config();
const mysql = require("mysql2/promise");
// const config = require("./config");
const dotenv = require("dotenv");
const path = require("path");

// Load .env file from the current directory
dotenv.config({ path: path.resolve(process.cwd(), ".env") });

// Configure your database connection here
const pool = mysql.createPool({
  host: process.env.DB_HOST,
  database: process.env.DB_DATABASE,
  username: process.env.DB_USERNAME,
  password: process.env.DB_PASSWORD,
  charset: process.env.DB_CHARSET,
});

console.log("uss: ", process.env.DB_USERNAME);

const updateFeedStatus = async () => {
  let connection;
  try {
    const currentDate = new Date();
    const yearMonthDay = currentDate.toISOString().slice(0, 10); // Daily check date format
    const yearMonth = currentDate.toISOString().slice(0, 7); // Monthly check date format
    console.log("I'm inn.. 1");
    connection = await pool.getConnection();
    console.log("I'm inn.. 2");

    const [feeds] = await connection.query("SELECT * FROM applcustfeeds");
    console.log("I'm inn.. 3");

    for (const feed of feeds) {
      // Skip checking if the feed status is 'stopped'
      if (feed.status === "stopped") {
        console.log(
          `Feed ID ${feed.feedid} is stopped. No budget checks performed.`
        );
        continue; // Skip to the next iteration of the loop
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

      if (monthlyTotal >= parseFloat(feed.budget)) {
        await connection.query(
          "UPDATE applcustfeeds SET status = ? WHERE feedid = ?",
          ["capped", feed.feedid]
        );
        console.log(
          `Monthly status changed to 'capped' for feed ID ${feed.feedid}`
        );
      } else {
        await connection.query(
          "UPDATE applcustfeeds SET status = ? WHERE feedid = ?",
          ["active", feed.feedid]
        );
        console.log(
          `Monthly status changed to 'active' for feed ID ${feed.feedid}`
        );
      }
      console.log(
        `Feed ID ${feed.feedid}: Monthly total is ${monthlyTotal}, budget is ${feed.budget}`
      );

      // Daily budget check (regardless of monthly budget status)
      if (
        feed.dailybudget !== null &&
        feed.dailybudget !== "" &&
        !isNaN(parseFloat(feed.dailybudget))
      ) {
        const [dailySumResult] = await connection.query(
          `
          SELECT SUM(cpc + cpa) AS total
          FROM applevents
          WHERE feedid = ?
            AND DATE(timestamp) = ?
        `,
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
  } catch (error) {
    console.error("An error occurred:", error.message);
  } finally {
    if (connection) await connection.release();
    await pool.end();
    console.log("Database connection closed.");
  }
};

console.log("Running applbudgetcheck.js script...");

updateFeedStatus();
