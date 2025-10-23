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

// File paths
const queueFilePath = path.join(__dirname, "applpass_cpa_queue.json");
const processingFilePath = path.join(__dirname, "applpass_cpa_processing.json");
const backupFilePath = path.join(__dirname, "applpass_cpa_backup.json");
const logFilePath = path.join(__dirname, "applpass_cpa.log");

console.log("====================================");
console.log(`Queue File Path: ${queueFilePath}`);
console.log(`Processing File Path: ${processingFilePath}`);
console.log(`Backup File Path: ${backupFilePath}`);
console.log(`Log File Path: ${logFilePath}`);
console.log("====================================");

// Function to process CPA events
async function processCPAEvents() {
  let connection;
  try {
    console.log(`Checking the file exists: ${queueFilePath}`);

    // Move contents of the queue file to the processing file
    if (fs.existsSync(queueFilePath)) {
      fs.renameSync(queueFilePath, processingFilePath);
    }

    // Create an empty queue file to continue receiving new events
    fs.writeFileSync(queueFilePath, "", "utf8");

    // Connect to the database
    connection = await mysql.createConnection(dbConfig);

    // Create a read stream for the processing file
    const fileStream = fs.createReadStream(processingFilePath);
    const rl = readline.createInterface({
      input: fileStream,
      crlfDelay: Infinity,
    });

    // Create a write stream for the backup file
    const backupStream = fs.createWriteStream(backupFilePath, { flags: "a" });
    console.log(`Reading file`);
    console.log(`Length of applpass_cpa_processing.json: ${rl.length}`);

    for await (const line of rl) {
      console.log(`line: ${line}`);

      if (line.trim()) {
        let eventData;

        try {
          eventData = JSON.parse(line);

          console.log(`ipaddress from file json: ${eventData.ipaddress}`);
        } catch (jsonError) {
          logMessage(
            `JSON Parsing Error: ${jsonError.message} - Skipping this line`,
            logFilePath
          );
          logToDatabase(
            "error",
            "applpass_cpa_putevent.js",
            `JSON Parsing Error: ${jsonError.message} - Skipping this line`
          );
          continue; // Skip this line if JSON is invalid
        }

        try {
          // Query and process the CPA event
          const [rows] = await connection.execute(
            `SELECT custid, jobid, feedid, timestamp, publisherid FROM applevents
                         WHERE useragent = ? AND ipaddress = ?
                         ORDER BY timestamp DESC LIMIT 1`,
            [eventData.userAgent, eventData.ipaddress]
          );
          const data = rows[0];
          console.log(
            `Length of rows from query and process the cpa event: ${rows.length}`
          );

          if (rows.length === 0) {
            logMessage(
              `No matching event found for eventID: ${eventData.eventid}. Adding to appleventsdel.`,
              logFilePath
            );
            logToDatabase(
              "warning",
              "applpass_cpa_putevent.js",
              `No matching event found for eventID: ${eventData.eventid}. Adding to appleventsdel.`
            );
            await connection.execute(
              `INSERT INTO appleventsdel (eventid, timestamp, eventtype, custid, jobid, refurl, ipaddress, cpc, cpa, feedid, useragent, deletecode, publisherid)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
              [
                eventData.eventid,
                eventData.timestamp,
                "cpa",
                "0000000000", // Default value instead of null
                "0000000000", // Default value instead of null
                eventData.domain,
                eventData.ipaddress,
                "0.0",
                "0.0", // Default value instead of null
                "0000000000", // Default value instead of null
                eventData.userAgent,
                "nomatch",
                data.publisherid,
              ]
            );
            continue;
          }

         

          // Retrieve jobpoolid using custid from the applcust table
          const [custRows] = await connection.execute(
            `SELECT jobpoolid FROM applcust WHERE custid = ?`,
            [data.custid]
          );

          if (custRows.length === 0) {
            logMessage(
              `No jobpoolid found for custid: ${data.custid}`,
              logFilePath
            );
            logToDatabase(
              "warning",
              "applpass_cpa_putevent.js",
              `No jobpoolid found for custid: ${data.custid}`
            );
            continue;
          }

          const jobpoolid = custRows[0].jobpoolid;

          // Check if the event is within 48 hours
          const checkHours = check48Hours(data.timestamp);

          if (!checkHours) {
            continue;
          }

          // Get CPA value from applcustfeeds or appljobs tables and insert CPA event
          const [feedRows] = await connection.execute(
            `SELECT cpa FROM applcustfeeds WHERE feedid = ? AND status = 'active'`,
            [data.feedid]
          );

          let cpa = feedRows.length > 0 ? feedRows[0].cpa : 0.0;

          if (cpa === 0.0) {
            const [jobRows] = await connection.execute(
              `SELECT cpa FROM appljobs WHERE job_reference = ? AND jobpoolid = ?`,
              [data.jobid, jobpoolid]
            );
            cpa = jobRows.length > 0 ? jobRows[0].cpa : 0.0;
          }

          // Insert CPA event into applevents table
          await connection.execute(
            `INSERT INTO applevents (eventid, timestamp, eventtype, custid, jobid, refurl, ipaddress, cpc, cpa, feedid, useragent, publisherid)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,  // Now 12 placeholders
            [
              eventData.eventid,
              eventData.timestamp,
              "cpa",
              data.custid,
              data.jobid,
              eventData.domain,
              eventData.ipaddress,
              "0.0",
              cpa,
              data.feedid,
              eventData.userAgent,
              data.publisherid,
            ]
          );

          // After successful insertion, write each event to the backup file on a new line
          backupStream.write(JSON.stringify(eventData) + "\n");
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
      }
    }

    // Close the backup stream
    backupStream.end();

    // Empty the processing file after processing
    fs.writeFileSync(processingFilePath, "", "utf8");

    // Graceful exit
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

// Function to check if the timestamp is within 48 hours
function check48Hours(dbTimestamp) {
  const dbUnixTimestamp = new Date(dbTimestamp).getTime();
  const currentUnixTimestamp = Date.now();
  const timeDiffHours = (currentUnixTimestamp - dbUnixTimestamp) / 3600 / 1000;

  return timeDiffHours <= 48;
}

// Run the CPA event processing function
processCPAEvents();
