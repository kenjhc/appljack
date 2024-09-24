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
const originalFilePath = path.join(__dirname, "applpass_queue.json");
const toBeProcessedFilePath = path.join(
  __dirname,
  "applpass_tobeprocessed.json"
);
const backupFilePath = path.join(__dirname, "applpass_queue_backup.json");
const logFilePath = path.join(__dirname, "applpass_putevents_test.log"); // Log file path

// Function to check for missing or null values in required fields
function checkForRequiredFields(eventData) {
  // console.log("Checking required fields for eventData:", eventData); // Log event data

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

  // console.log("Missing fields:", missingFields); // Log missing fields if any
  return missingFields;
}

// Function to get cpc value from applcustfeeds and appljobs tables
async function getCPCValue(connection, feedid, job_reference, jobpoolid) {
  // console.log(`Fetching CPC for feedid: ${feedid}, job_reference: ${job_reference}, jobpoolid: ${jobpoolid}`); // Log input parameters

  try {
    // First Query: Check applcustfeeds for active feedid
    const [feedRows] = await connection.execute(
      "SELECT cpc FROM applcustfeeds WHERE feedid = ? AND status = 'active'",
      [feedid]
    );

    // console.log("Feed rows result:", feedRows); // Log query result for applcustfeeds

    // If a result is found and cpc is not 0.0, return this cpc value
    if (feedRows.length > 0 && feedRows[0].cpc !== 0.0) {
      return feedRows[0].cpc;
    }

    // Fallback Query: Check appljobs for job_reference and jobpoolid
    const [jobRows] = await connection.execute(
      "SELECT cpc FROM appljobs WHERE job_reference = ? AND jobpoolid = ?",
      [job_reference, jobpoolid]
    );

    // console.log("Job rows result:", jobRows); // Log query result for appljobs

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
      // console.log(`Moving ${originalFilePath} to ${toBeProcessedFilePath}`);
      fs.renameSync(originalFilePath, toBeProcessedFilePath);
    }

    // console.log(`Database: ${config.database}`);
    // console.log(`applpass_queue.json: ${originalFilePath}`);

    // Create an empty original file to continue receiving new events
    fs.writeFileSync(originalFilePath, "", "utf8");

    // Count the number of records in the to-be-processed file
    const lines = fs
      .readFileSync(toBeProcessedFilePath, "utf8")
      .split("\n")
      .filter(Boolean);
    totalRecords = lines.length;

    // console.log(`Total records to be processed: ${totalRecords}`); // Log total number of records
    // logMessage(`Total records to be processed: ${totalRecords}`, logFilePath);

    // Connect to the database
    console.log("Connecting to the database...");
    connection = await mysql.createConnection(dbConfig);
    console.log("Database connected successfully.");

    // Create a read stream for the to-be-processed file
    const fileStream = fs.createReadStream(toBeProcessedFilePath);
    const rl = readline.createInterface({
      input: fileStream,
      crlfDelay: Infinity,
    });

    // Create a write stream for the backup file
    const backupStream = fs.createWriteStream(backupFilePath, { flags: "a" });
    // console.log("Length of file:", rl.length); // Log current line being processed

    for await (const line of rl) {
      if (line.trim()) {
        console.log("Processing line:", line); // Log current line being processed
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

        await connection.beginTransaction();

        try {
          // Get the cpc value from applcustfeeds and appljobs tables
          console.log("Fetching CPC value...");
          const cpcValue = await getCPCValue(
            connection,
            eventData.feedid,
            eventData.job_reference,
            eventData.jobpoolid
          );
          console.log(`Fetched CPC value: ${cpcValue}`); // Log fetched CPC value

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

          // console.log("Executing insert query with values:", values); // Log the query values
          await connection.execute(query, values);
          await connection.commit();

          // After successful insertion, write the line to the backup file
          // console.log("Inserting event to backup and incrementing successful inserts");
          console.log("Inserting values into DB:", values);

          backupStream.write(line + "\n");
          successfulInserts++; // Increment counter
        } catch (dbError) {
          console.log(`Database Insertion Error: ${dbError.message}`); // Log database error
          logMessage(
            `Database Insertion Error: ${dbError.message}`,
            logFilePath
          );
          logToDatabase("warning", "applpass_putevents2.js", dbError.message);
          await connection.rollback(); // Rollback in case of error
        }
      }
    }

    // Close the streams
    // console.log("Closing streams...");
    backupStream.end();
    fileStream.close();

    // Empty the to-be-processed file after processing
    // console.log("Emptying to-be-processed file...");
    fs.writeFileSync(toBeProcessedFilePath, "", "utf8");

    // Log the total number of successful inserts and compare with the total records
    // console.log(`Total successful inserts: ${successfulInserts}`); // Log total successful inserts
    logMessage(`Total successful inserts: ${successfulInserts}`, logFilePath);

    if (totalRecords === successfulInserts) {
      // console.log("All records processed successfully."); // Log success if all records are processed
      logMessage(`All records processed successfully.`, logFilePath);
      logToDatabase(
        "success",
        "applpass_putevents2.js",
        "All records processed successfully"
      );
      process.exit(0); // Exit with success
    } else {
      const discrepancyMessage = `Discrepancy found: ${
        totalRecords - successfulInserts
      } records were not processed.`;

      // console.log(discrepancyMessage); // Log any discrepancies
      logMessage(discrepancyMessage, logFilePath);
      logToDatabase("warning", "applpass_putevents2.js", discrepancyMessage);
      process.exit(1); // Exit with failure
    }
  } catch (error) {
    // console.log("Error in processEvents:", error.message); // Log any other errors
    logMessage(`Error in processEvents: ${error.message}`, logFilePath);
    logToDatabase(
      "error",
      "applpass_putevents2.js",
      `Error in processEvents: ${error.message}`
    );
    process.exit(1); // Exit with failure
  } finally {
    if (connection) {
      // console.log("Closing the database connection...");
      connection.end(); // Close the database connection
    }
  }
}

// Call the function to process events
processEvents();
