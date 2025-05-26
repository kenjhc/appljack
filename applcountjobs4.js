// require("dotenv").config();

const fs = require("fs");
const path = require("path");
const mysql = require("mysql");
const sax = require("sax");
const { logMessage, logToDatabase } = require("./utils/helpers");
const config = require("./config");
const {
  getSingleFile, 
  cronQueue,
  cronQueueLog
} = require('./applecronqueuesystem');

// Define the folder where XML files are stored
const xmlFolderPath = "/chroot/home/appljack/appljack.com/html/applfeeds";
// const xmlFolderPath = "C:/laragon/www/applfeeds";

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
  let file = {};

  try {
    // Read all files from the XML folder
    const files = fs.readdirSync(xmlFolderPath);

    await cronQueue(pool, files);

    // get the single file which have status 0, that means it is a un processed file
    file = await getSingleFile(pool); 
    if(!file) process.exit(0);
    
    const match = file?.data?.match(/^(\d+)-([a-zA-Z0-9]+)\.xml$/);
    const custid = match[1];
    const feedid = match[2];

    // queue has been started
    await cronQueueLog(pool, file?.id, { status: '1' });

    // Use a SAX parser to count <job> nodes in the stream
    const numJobs = await countJobNodesSax(path.join(xmlFolderPath, file?.data));

    // await cronQueueLog(pool, file?.id, { process: numJobs });

    // Update the numjobs field in the applcustfeeds table
    // await updateNumJobs(feedid, numJobs);
    await cronQueueLog(pool, file?.id, { process: numJobs });

    // queue has been ended
    await cronQueueLog(pool, file?.id, { status: '2' });

    process.exit(0);
        
    /*
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
    */
  } catch (error) {
    console.error("Error processing XML files:", error);

    // queue has been ended
    await cronQueueLog(pool, file?.id, { status: '3', log: String(error) });
    
    // logToDatabase(
    //   "error",
    //   "applcountjobs2.js",
    //   `Error processing XML files: ${error}`
    // );
    process.exit(1); // Exit with an error code in case of failure
  } finally {
    pool.end();
  }
}


// Modified version of countJobNodesSax function that counts complete jobs
function countJobNodesSax(filePath) {
  return new Promise((resolve, reject) => {
    let openJobTags = 0;  // Count of open <job> tags
    let closedJobTags = 0; // Count of closed </job> tags
    let validJobCount = 0; // Count of complete jobs (with matching open/close tags)
    let jobDepth = 0;     // Track nesting of job tags

    const saxStream = sax.createStream(true);

    saxStream.on("opentag", (node) => {
      // Every time we encounter a <job> tag
      if (node.name === "job") {
        openJobTags++;
        jobDepth++;
      }
    });

    saxStream.on("closetag", (name) => {
      // Every time we encounter a </job> tag
      if (name === "job") {
        closedJobTags++;
        jobDepth--;

        // Only count job if it's properly closed and not nested
        if (jobDepth === 0) {
          validJobCount++;
        }
      }
    });

    saxStream.on("end", () => {
      console.log(`File: ${filePath}`);
      console.log(`Open job tags: ${openJobTags}`);
      console.log(`Closed job tags: ${closedJobTags}`);
      console.log(`Valid complete jobs: ${validJobCount}`);

      // Resolve with the valid job count
      resolve(validJobCount);
    });

    saxStream.on("error", (err) => {
      console.error(`Error parsing XML file: ${filePath}`, err);

      // Instead of rejecting, continue parsing
      console.error("Continuing despite error...");
      saxStream._parser.error = null;
      saxStream._parser.resume();
    });

    // Pipe the file stream to the SAX parser
    const readStream = fs.createReadStream(filePath, { encoding: "utf8" });

    readStream.on("error", (err) => {
      console.error(`Error reading file: ${filePath}`, err);
      reject(err);
    });

    readStream.pipe(saxStream);
  });
}

// A diagnostic function to analyze the XML file and find issues
// function analyzeXmlFile(filePath) {
//   return new Promise((resolve, reject) => {
//     console.log(`Analyzing XML file: ${filePath}`);

//     let stats = {
//       openJobTags: 0,
//       closedJobTags: 0,
//       errorCount: 0,
//       unclosedJobs: 0,
//       nestedJobs: 0,
//       maxJobDepth: 0,
//       currentDepth: 0
//     };

//     const saxStream = sax.createStream(true);

//     saxStream.on("opentag", (node) => {
//       if (node.name === "job") {
//         stats.openJobTags++;
//         stats.currentDepth++;

//         // Track maximum nesting depth
//         if (stats.currentDepth > stats.maxJobDepth) {
//           stats.maxJobDepth = stats.currentDepth;
//         }

//         // Track nested jobs
//         if (stats.currentDepth > 1) {
//           stats.nestedJobs++;
//         }
//       }
//     });

//     saxStream.on("closetag", (name) => {
//       if (name === "job") {
//         stats.closedJobTags++;
//         stats.currentDepth--;
//       }
//     });

//     saxStream.on("error", (err) => {
//       stats.errorCount++;
//       console.error(`XML error at position ${saxStream._parser.position}: ${err.message}`);

//       // Continue parsing despite errors
//       saxStream._parser.error = null;
//       saxStream._parser.resume();
//     });

//     saxStream.on("end", () => {
//       // Calculate unclosed jobs
//       stats.unclosedJobs = stats.openJobTags - stats.closedJobTags;

//       console.log("XML Analysis Results:");
//       console.log(`- Total <job> tags: ${stats.openJobTags}`);
//       console.log(`- Total </job> tags: ${stats.closedJobTags}`);
//       console.log(`- Unclosed job tags: ${stats.unclosedJobs}`);
//       console.log(`- Nested job tags: ${stats.nestedJobs}`);
//       console.log(`- Maximum job nesting depth: ${stats.maxJobDepth}`);
//       console.log(`- XML errors encountered: ${stats.errorCount}`);

//       resolve(stats);
//     });

//     const readStream = fs.createReadStream(filePath, { encoding: "utf8" });

//     readStream.on("error", (err) => {
//       console.error(`Error reading file: ${filePath}`, err);
//       reject(err);
//     });

//     readStream.pipe(saxStream);
//   });
// }

// To use these functions in your applcountjobs2.js script:
// async function processXmlFiles() {
//   try {
//     // Read all files from the XML folder
//     const files = fs.readdirSync(xmlFolderPath);

//     // Loop through each file
//     for (const file of files) {
//       // Check if the file matches the format [custid]-[feedid].xml
//       const match = file.match(/^(\d+)-([a-zA-Z0-9]+)\.xml$/);
//       if (match) {
//         const custid = match[1];
//         const feedid = match[2];
//         console.log(
//           `Processing file: ${file} (custid: ${custid}, feedid: ${feedid})`
//         );

//         // First analyze the file to check for issues
//         if (custid === '6468623475') {
//           await analyzeXmlFile(path.join(xmlFolderPath, file));
//         }

//         // Use the improved counting method
//         const numJobs = await countJobNodesSax(path.join(xmlFolderPath, file));

//         // Update the numjobs field in the applcustfeeds table
//         await updateNumJobs(feedid, numJobs);
//       } else {
//         console.log(
//           `Skipping file: ${file} (does not match custid-feedid format)`
//         );
//       }
//     }
//     console.log("All XML files processed.");
//     process.exit(0); // Exit the process once all files have been processed
//   } catch (error) {
//     console.error("Error processing XML files:", error);
//     process.exit(1); // Exit with an error code in case of failure
//   } finally {
//     pool.end();
//   }
// }

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
processXmlFiles()
  .catch((err) => {
    console.error(err);
    process.exit(1); // Exit with an error code in case of a fatal error
  });