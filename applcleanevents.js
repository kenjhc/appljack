// require("dotenv").config();

const mysql = require("mysql");
const fs = require("fs");
const { logMessage, logToDatabase } = require("./utils/helpers");
const config = require("./config");

// Create a connection to the database
const connection = mysql.createConnection({
  host: config.host,
  user: config.username,
  password: config.password,
  database: config.database,
  charset: config.charset,
});

const logFilePath = "applcleanevents.log";

// Function to move records based on criteria
const moveRecords = (criteria) => {
  logMessage(`Script started processing.`, logFilePath);

  // Insert the matching rows into the appleventsdel table with deletecode set to 'ip'
  const insertQuery = `
    INSERT INTO appleventsdel (eventid, timestamp, eventtype, custid, jobid, refurl, useragent, ipaddress, cpc, cpa, feedid, deletecode)
    SELECT eventid, timestamp, eventtype, custid, jobid, refurl, useragent, ipaddress, cpc, cpa, feedid, 'ip' FROM applevents WHERE ${criteria}
  `;

  connection.query(insertQuery, (error, results) => {
    if (error) {
      logMessage(`Error executing insert query: ${error.message}`, logFilePath);
      logToDatabase(
        "error",
        "applcleanevents.js",
        `Error executing insert query: ${error.message}`
      );

      console.error("Error executing insert query:", error);
      connection.end(); // Close the connection in case of an error
      return;
    }

    logMessage(
      `Inserted ${results.affectedRows} rows into appleventsdel 1 `,
      logFilePath
    );

    logToDatabase(
      "success",
      "applcleanevents.js",
      `Inserted ${results.affectedRows} rows into appleventsdel`
    );

    console.log(`Inserted ${results.affectedRows} rows into appleventsdel`);

    // After inserting, delete the matching rows from the applevents table
    const deleteQuery = `DELETE FROM applevents WHERE ${criteria}`;

    connection.query(deleteQuery, (error, results) => {
      if (error) {
        logMessage(
          `Error executing delete query: ${error.message}`,
          logFilePath
        );
        logToDatabase(
          "error",
          "applcleanevents.js",
          `Error executing delete query: ${error.message}`
        );

        console.error("Error executing delete query:", error);
      } else {
        logMessage(
          `Deleted ${results.affectedRows} rows from applevents 2`,
          logFilePath
        );
        logToDatabase(
          "warning",
          "applcleanevents.js",
          `Deleted ${results.affectedRows} rows from applevents 3 `
        );

        console.log(`Deleted ${results.affectedRows} rows from applevents 4`);
      }

      logMessage(`Script completed successfully.`, logFilePath);
      logToDatabase(
        "success",
        "applcleanevents.js",
        `Script completed successfully.`
      );

      console.log("Script completed successfully.");

      connection.end(); // Close the connection only after all operations are completed
    });
  });
};

// Define the criteria for moving records
const criteria = `
  ipaddress = '52.2.111.1'
  OR ipaddress = '54.162.173.161'
  OR ipaddress = '43.239.198.189'
  OR ipaddress = '14.99.136.186'
  OR ipaddress = '180.211.111.126'
  OR ipaddress = '66.249.69.164'
  OR ipaddress = '66.249.64.96'
  OR ipaddress = '66.249.64.97'
  OR ipaddress = '66.249.64.98'
  OR ipaddress = '66.249.64.232'
  OR ipaddress = '66.249.87.132'
  OR ipaddress = '66.249.83.6'
  OR ipaddress = '66.249.83.5'
  OR ipaddress = '66.249.83.4'
  OR ipaddress LIKE '66.249.69.%'
  OR ipaddress LIKE '66.249.73.%'
  OR ipaddress LIKE '66.249%'
  OR ipaddress LIKE '103%'
  OR ipaddress LIKE '27.%'
  OR ipaddress LIKE '183.82.%'
  OR ipaddress LIKE '182.156%'
  OR ipaddress LIKE '14.97.%'
  OR ipaddress LIKE '20.%'
  OR ipaddress LIKE '202%'
  OR ipaddress LIKE '117.195%'
  OR ipaddress LIKE '49.205%'
`;

// Execute the move function
moveRecords(criteria);
