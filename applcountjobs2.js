// require("dotenv").config();

const fs = require("fs");
const path = require("path");
const mysql = require("mysql");
const sax = require("sax");
const { logMessage, logToDatabase } = require("./utils/helpers");
const config = require("./config");
const { envSuffix } = require("./config");

// Define the folder where XML files are stored
const xmlFolderPath = `/chroot/home/appljack/appljack.com/html${envSuffix}/applfeeds`;

// Setup MySQL connection pool
const pool = mysql.createPool({
  connectionLimit: 10,
  host: config.host,
  user: config.username,
  password: config.password,
  database: config.database,
  charset: config.charset,
});

// Function to process XML files, count <job> nodes, and update numjobs
async function processXmlFiles() {
  try {
    // Read all files from the XML folder
    const files = fs.readdirSync(xmlFolderPath);

    // Loop through each file
    for (const file of files) {
      // Check if the file matches the format [custid]-[feedid].xml
      const match = file.match(/^(\d+)-([a-zA-Z0-9]+)\.xml$/);
      if (match) {
        const custid = match[1];
        const feedid = match[2];
        console.log(
          `Processing file: ${file} (custid: ${custid}, feedid: ${feedid})`
        );

        logToDatabase(
          "success",
          "applcountjobs2.js",
          `Processing file: ${file} (custid: ${custid}, feedid: ${feedid})`
        );

        // Use a SAX parser to count <job> nodes in the stream
        const numJobs = await countJobNodesSax(path.join(xmlFolderPath, file));

        // Update the numjobs field in the applcustfeeds table
        await updateNumJobs(feedid, numJobs);
      } else {
        console.log(
          `Skipping file: ${file} (does not match custid-feedid format)`
        );

        logToDatabase(
          "warning",
          "applcountjobs2.js",
          `Skipping file: ${file} (does not match custid-feedid format)`
        );
      }
    }
    console.log("All XML files processed.");
    process.exit(0); // Exit the process once all files have been processed
  } catch (error) {
    console.error("Error processing XML files:", error);

    logToDatabase(
      "error",
      "applcountjobs2.js",
      `Error processing XML files: ${error}`
    );
    process.exit(1); // Exit with an error code in case of failure
  } finally {
    pool.end();
  }
}

// Function to count <job> nodes using a SAX parser
function countJobNodesSax(filePath) {
  return new Promise((resolve, reject) => {
    let jobCount = 0;
    const saxStream = sax.createStream(true);

    saxStream.on("opentag", (node) => {
      // Every time we encounter a <job> tag, we increment the counter
      if (node.name === "job") {
        jobCount++;
      }
    });

    saxStream.on("end", () => {
      resolve(jobCount); // Resolve the promise with the total job count
    });

    saxStream.on("error", (err) => {
      console.error(`Error parsing XML file: ${filePath}`, err);
      reject(err);
    });

    // Pipe the file stream to the SAX parser
    const readStream = fs.createReadStream(filePath, { encoding: "utf8" });
    readStream.pipe(saxStream);
  });
}

// Function to update the numjobs field in the database
async function updateNumJobs(feedid, numJobs) {
  return new Promise((resolve, reject) => {
    const updateQuery = "UPDATE applcustfeeds SET numjobs = ? WHERE feedid = ?";
    pool.query(updateQuery, [numJobs, feedid], (error, results) => {
      if (error) {
        console.error(`Error updating numjobs for feedid: ${feedid}`, error);
        logToDatabase(
          "success",
          "applcountjobs2.js",
          `Error updating numjobs for feedid: ${feedid}, Error: ${error}`
        );
        return reject(error);
      }
      console.log(
        `Updated numjobs for feedid: ${feedid} with count: ${numJobs}`
      );

      logToDatabase(
        "success",
        "applcountjobs2.js",
        `Updated numjobs for feedid: ${feedid} with count: ${numJobs}`
      );
      resolve(results);
    });
  });
}

// Start processing the XML files
processXmlFiles().catch((err) => {
  console.error(err);
  process.exit(1); // Exit with an error code in case of a fatal error
});
