// require("dotenv").config();

const fs = require("fs");
const readline = require("readline");
const mysql = require("mysql2");
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
const originalFilePath = path.join(__dirname, "applpass_queue.json");
const toBeProcessedFilePath = path.join(
  __dirname,
  "applpass_tobeprocessed.json"
);
const backupFilePath = path.join(__dirname, "applpass_queue_backup.json");
const logFilePath = path.join(__dirname, "applpass_putevents_test.log"); // Log file path

logMessage(`I'm testing....`, logFilePath);
return;
// Function to check for missing or null values in required fields
function checkForRequiredFields(eventData) {
  const requiredFields = [
    "eventid",
    "timestamp",
    "custid",
    "job_reference",
    "refurl",
    "userAgent",
    "ipaddress",
    "feedid",
  ];
  const missingFields = [];

  requiredFields.forEach((field) => {
    if (!eventData[field]) {
      missingFields.push(field);
    }
  });

  return missingFields;
}

// Function to get cpc value from applcustfeeds and appljobs tables
async function getCPCValue(connection, feedid, job_reference, jobpoolid) {
  try {
    // First Query: Check applcustfeeds for active feedid
    const [feedRows] = await connection.execute(
      "SELECT cpc FROM applcustfeeds WHERE feedid = ? AND status = 'active'",
      [feedid]
    );

    // If a result is found and cpc is not 0.0, return this cpc value
    if (feedRows.length > 0 && feedRows[0].cpc !== 0.0) {
      return feedRows[0].cpc;
    }

    // Fallback Query: Check appljobs for job_reference and jobpoolid
    const [jobRows] = await connection.execute(
      "SELECT cpc FROM appljobs WHERE job_reference = ? AND jobpoolid = ?",
      [job_reference, jobpoolid]
    );

    // If a result is found, return this cpc value
    if (jobRows.length > 0) {
      return jobRows[0].cpc;
    }

    // If both queries fail, return 0.0
    return 0.0;
  } catch (err) {
    logMessage(`Error fetching CPC value: ${err.message}`, logFilePath);
    logToDatabase(
      "error",
      "applpass_putevents2.js",
      `Error fetching CPC value: ${err.message}`
    );
    return 0.0;
  }
}

// Function to process events
async function processEvents() {
  let connection;
  let successfulInserts = 0; // Initialize counter for successful inserts
  let totalRecords = 0; // Initialize counter for total records in to-be-processed file

  try {
    // Move contents of the original file to the to-be-processed file
    if (fs.existsSync(originalFilePath)) {
      fs.renameSync(originalFilePath, toBeProcessedFilePath);
    }

    // Create an empty original file to continue receiving new events
    fs.writeFileSync(originalFilePath, "", "utf8");

    // Count the number of records in the to-be-processed file
    const lines = fs
      .readFileSync(toBeProcessedFilePath, "utf8")
      .split("\n")
      .filter(Boolean);
    totalRecords = lines.length;

    // Log the total number of records
    logMessage(`Total records to be processed: ${totalRecords}`, logFilePath);

    // Connect to the database
    connection = await mysql.createConnection(dbConfig);

    // Create a read stream for the to-be-processed file
    const fileStream = fs.createReadStream(toBeProcessedFilePath);
    const rl = readline.createInterface({
      input: fileStream,
      crlfDelay: Infinity,
    });

    // Create a write stream for the backup file
    const backupStream = fs.createWriteStream(backupFilePath, { flags: "a" }); 

    for await (const line of rl) {
      if (line.trim()) {
        const eventData = JSON.parse(line);

        // Check for missing or null required fields
        const missingFields = checkForRequiredFields(eventData);
        if (missingFields.length > 0) {
          const message = `Missing or null required fields [${missingFields.join(
            ", "
          )}] for eventID: ${eventData.eventid || "UNKNOWN"} - Skipped`;

          logMessage(`${message}`, logFilePath);

          logToDatabase("warning", "applpass_putevents2.js", message);
          continue; // Skip this event
        }

        try {
          // Get the cpc value from applcustfeeds and appljobs tables
          const cpcValue = await getCPCValue(
            connection,
            eventData.feedid,
            eventData.job_reference,
            eventData.jobpoolid
          );

          // Insert data into applevents table
          const query = `
                        INSERT INTO applevents (eventid, timestamp, eventtype, custid, jobid, refurl, useragent, ipaddress, cpc, cpa, feedid)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    `;

          const values = [
            eventData.eventid,
            eventData.timestamp,
            "cpc", // Setting eventtype to "cpc" by default
            eventData.custid,
            eventData.job_reference, // Using job_reference from JSON
            eventData.refurl,
            eventData.userAgent, // Using userAgent from JSON
            eventData.ipaddress,
            cpcValue, // Use fetched cpc value
            eventData.cpa ?? null,
            eventData.feedid,
          ];

          await connection.execute(query, values);

          // After successful insertion, write the line to the backup file
          backupStream.write(line + "\n");
          successfulInserts++; // Increment counter
        } catch (dbError) {
          logMessage(
            `Database Insertion Error: ${dbError.message}`,
            logFilePath
          );
          logToDatabase("warning", "applpass_putevents2.js", dbError.message);
        }
      }
    }

    // Close the streams
    backupStream.end();
    fileStream.close();

    // Empty the to-be-processed file after processing
    fs.writeFileSync(toBeProcessedFilePath, "", "utf8");

    // Log the total number of successful inserts and compare with the total records
    logMessage(`Total successful inserts: ${successfulInserts}`, logFilePath);
    if (totalRecords === successfulInserts) {
      logMessage(`All records processed successfully.`, logFilePath);
      logToDatabase(
        "success",
        "applpass_putevents2.js",
        "All records processed successfully"
      );
    } else {
      const discrepancyMessage = `Discrepancy found: ${
        totalRecords - successfulInserts
      } records were not processed.`;

      logMessage(discrepancyMessage, logFilePath);

      logToDatabase("warning", "applpass_putevents2.js", discrepancyMessage);
    }
  } catch (err) {
    const message = `Processing Error: ${err.message}`;
    logMessage(message, logFilePath);
    logToDatabase("error", "applpass_putevents2.js", message);
  } finally {
    if (connection) {
      await connection.end();
    }
  }
}

// Run the event processing function
processEvents();
