const mysql = require("mysql");
const config = require("./config");
const { logMessage, logToDatabase } = require("./utils/helpers");

// Create MySQL pool
const pool = mysql.createPool({
  connectionLimit: 10,
  host: config.host,
  user: config.username,
  password: config.password,
  database: config.database,
  charset: config.charset,
  connectTimeout: 900000, // 15 minutes (in milliseconds)
  acquireTimeout: 900000, // 15 minutes (in milliseconds)
});

let totalDeleted = 0;
let totalRecords = 0;
const batchSize = 10000;
const logFilePath = "appljobs_delete.log";

// Function to get the count of records in appljobsfresh
const getFreshCount = async () => {
  return new Promise((resolve, reject) => {
    const query = "SELECT COUNT(*) AS count FROM appljobsfresh";
    pool.query(query, (err, result) => {
      if (err) return reject(err);
      resolve(result[0].count);
    });
  });
};

logMessage("Starting appljobs_delete.js script...", logFilePath);
// Function to select jobs that need to be deleted in batches
const selectStaleJobs = async (offset) => {
  console.log(`Selecting stale jobs with OFFSET ${offset}`);
  logMessage(`Selecting stale jobs with OFFSET ${offset}`, logFilePath);
  logToDatabase(
    "success",
    "appljobs_delete.js",
    `Selecting stale jobs with OFFSET ${offset}`
  );

  return new Promise((resolve, reject) => {
    const query = `
      SELECT aj.job_reference, aj.jobpoolid
      FROM appljobs aj
      LEFT JOIN appljobsfresh ajf ON aj.job_reference = ajf.job_reference AND aj.jobpoolid = ajf.jobpoolid
      WHERE ajf.job_reference IS NULL
      LIMIT ${batchSize} OFFSET ${offset}
    `;

    console.log(query); // Log the query for visibility
    logMessage(`Query: ${query}`, logFilePath);

    pool.query(query, (err, result) => {
      if (err) {
        console.error("Error during selectStaleJobs query:", err);
        logMessage(`Error during selectStaleJobs query: ${err}`, logFilePath);
        logToDatabase(
          "error",
          "appljobs_delete.js",
          `Error during selectStaleJobs query: ${err}`
        );

        return reject(err);
      }
      console.log(`Selected ${result.length} stale jobs to delete.`);
      logMessage(
        `Selected ${result.length} stale jobs to delete.`,
        logFilePath
      );
      logToDatabase(
        "success",
        "appljobs_delete.js",
        `Selected ${result.length} stale jobs to delete.`
      );

      resolve(result);
    });
  });
};

// Function to delete jobs that are no longer in appljobsfresh
const deleteStaleJobs = async (jobs) => {
  if (jobs.length === 0) return 0;

  const jobReferences = jobs.map((job) => [job.job_reference, job.jobpoolid]);

  console.log(`Deleting ${jobs.length} jobs from appljobs...`);
  logMessage(`Deleting ${jobs.length} jobs from appljobs...`, logFilePath);
  logToDatabase(
    "success",
    "appljobs_delete.js",
    `Deleting ${jobs.length} jobs from appljobs...`
  );

  return new Promise((resolve, reject) => {
    const query = `
      DELETE FROM appljobs WHERE (job_reference, jobpoolid) IN (?)
    `;

    pool.query(query, [jobReferences], (err, result) => {
      if (err) {
        console.error("Error during deleteStaleJobs query:", err);
        logMessage(`Error during deleteStaleJobs query: ${err}`, logFilePath);
        logToDatabase(
          "error",
          "appljobs_delete.js",
          `Error during deleteStaleJobs query: ${err}`
        );

        return reject(err);
      }
      console.log(`Deleted ${result.affectedRows} jobs from appljobs.`);
      logMessage(
        `Deleted ${result.affectedRows} jobs from appljobs.`,
        logFilePath
      );
      logToDatabase(
        "warning",
        "appljobs_delete.js",
        `Deleted ${result.affectedRows} jobs from appljobs.`
      );

      totalDeleted += result.affectedRows;
      resolve(result.affectedRows);
    });
  });
};

// Function to process deletions in batches
const processDeletions = async () => {
  try {
    console.log("Starting the deletion process...");
    logMessage(`Starting the deletion process...`, logFilePath);
    logToDatabase(
      "success",
      "appljobs_delete.js",
      `Starting the deletion process...`
    );

    const freshCount = await getFreshCount();
    console.log(`Found ${freshCount} records in appljobsfresh.`);
    logMessage(`Found ${freshCount} records in appljobsfresh.`, logFilePath);
    logToDatabase(
      "success",
      "appljobs_delete.js",
      `Found ${freshCount} records in appljobsfresh.`
    );

    // If appljobsfresh is empty, stop the deletion process
    if (freshCount === 0) {
      console.log("No records in appljobsfresh. Deletion process aborted.");
      logMessage(
        `No records in appljobsfresh. Deletion process aborted.`,
        logFilePath
      );
      logToDatabase(
        "warning",
        "appljobs_delete.js",
        `No records in appljobsfresh. Deletion process aborted.`
      );

      return;
    }

    let offset = 0;
    let batchSizeReached = true;

    while (batchSizeReached) {
      console.log(`Processing batch with offset ${offset}...`);
      logMessage(`Processing batch with offset ${offset}...`, logFilePath);
      logToDatabase(
        "success",
        "appljobs_delete.js",
        `Processing batch with offset ${offset}...`
      );

      const jobs = await selectStaleJobs(offset);
      batchSizeReached = jobs.length > 0;

      if (!batchSizeReached) break; // If no more jobs are selected, break the loop

      const deletedRows = await deleteStaleJobs(jobs);

      totalRecords += jobs.length;
      console.log(
        `Deleted ${deletedRows} jobs in this batch. Total deleted so far: ${totalDeleted}. Processed ${totalRecords} records.`
      );
      logMessage(
        `Deleted ${deletedRows} jobs in this batch. Total deleted so far: ${totalDeleted}. Processed ${totalRecords} records.`,
        logFilePath
      );
      logToDatabase(
        "success",
        "appljobs_delete.js",
        `Deleted ${deletedRows} jobs in this batch. Total deleted so far: ${totalDeleted}. Processed ${totalRecords} records.`
      );

      offset += batchSize;
    }

    console.log(
      `Deletion process complete. Total jobs deleted: ${totalDeleted}`
    );
    logMessage(
      `Deletion process complete. Total jobs deleted: ${totalDeleted}`,
      logFilePath
    );
    logToDatabase(
      "warning",
      "appljobs_delete.js",
      `Deletion process complete. Total jobs deleted: ${totalDeleted}`
    );
  } catch (err) {
    console.error("Error during deletion process:", err);
    logMessage(`Error during deletion process: ${err}`, logFilePath);
    logToDatabase(
      "error",
      "appljobs_delete.js",
      `Error during deletion process: ${err}`
    );
  } finally {
    await truncateFreshTable();
    pool.end(); // Ensure the pool closes only after all operations complete
  }
};

// Function to truncate appljobsfresh table at the end
const truncateFreshTable = async () => {
  console.log("Truncating appljobsfresh table...");
  logMessage(`Truncating appljobsfresh table...`, logFilePath);
  logToDatabase(
    "success",
    "appljobs_delete.js",
    `Truncating appljobsfresh table...`
  );

  return new Promise((resolve, reject) => {
    const query = "TRUNCATE TABLE appljobsfresh";
    pool.query(query, (err, result) => {
      if (err) {
        console.error("Error truncating appljobsfresh:", err);
        logMessage(`Error truncating appljobsfresh: ${err}`, logFilePath);
        logToDatabase(
          "error",
          "appljobs_delete.js",
          `Error truncating appljobsfresh: ${err}`
        );

        return reject(err);
      }
      console.log("appljobsfresh table truncated successfully.");
      logMessage(`appljobsfresh table truncated successfully.`, logFilePath);
      logToDatabase(
        "success",
        "appljobs_delete.js",
        `appljobsfresh table truncated successfully.`
      );

      resolve(result);
    });
  });
};

// Start the deletion process
processDeletions().then(() => {
  console.log("All deletions and truncations complete.");
  logMessage(`All deletions and truncations complete.`, logFilePath);
  logToDatabase(
    "warning",
    "appljobs_delete.js",
    `All deletions and truncations complete.`
  );
});
