// require("dotenv").config();

const fs = require("fs");
const readline = require("readline");
const mysql = require("mysql2/promise");
const path = require("path");
const { logMessage, logToDatabase } = require("./utils/helpers");
const config = require("./config");

// Database configuration
const dbConfig = {
  host: config.host,
  user: config.username,
  password: config.password,
  database: config.database,
  charset: config.charset,
};

const logFilePath = path.join(__dirname, "applpass_dbtest.log");

console.log("===================================="); 
console.log(`Log File Path: ${logFilePath}`);
console.log("====================================");

// Function to process CPA events
async function processCPAEvents() {
  let connection;
  try { 
    // Connect to the database
    connection = await mysql.createConnection(dbConfig);

    console.log(`Reading file`);

    try {
      // Retrieve jobpoolid using custid from the applcust table
      const [custRows] = await connection.execute(
        `SELECT jobpoolid FROM applcust WHERE custid = ?`,
        ["1234567890"]
      );

      if (custRows.length === 0) {
        logMessage(`working....: ${data.custid}`, logFilePath);
        logToDatabase(
          "warning",
          "applpass_cpa_putevent.js",
          `working....: ${data.custid}`
        );
      } else {
        logMessage(`not woring...: ${data.custid}`, logFilePath);
        logToDatabase(
          "warning",
          "applpass_cpa_putevent.js",
          `not woring...: ${data.custid}`
        );
      }
    } catch (error) {
      logMessage(
        `Error processing eventID: ${eventData.eventid} - ${error.message}`,
        logFilePath
      );
      logToDatabase(
        "error",
        "applpass_cpa_putevent.js",
        `Error processing eventID: ${eventData.eventid} - ${error.message}`
      );
    }

    console.log("Processing completed successfully.");
    process.exit(0); // Exit successfully
  } catch (error) {
    logMessage(`Process failed: ${error.message}`, logFilePath);
    logToDatabase(
      "error",
      "applpass_cpa_putevent.js",
      `Process failed: ${error.message}`
    );
    process.exit(1); // Exit with error
  } finally {
    if (connection) {
      await connection.end();
    }
  }
}

// Run the CPA event processing function
processCPAEvents();
