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

const logFilePath = "appljobs_delete.log";

// Function to delete jobs that are older than the last upload
const deleteOldJobs = async () => {
  logMessage("Starting deleteOldJobs process...", logFilePath);
  logToDatabase("success", "appljobs_delete.js", "Starting deleteOldJobs process...");

  return new Promise((resolve, reject) => {
    const query = `
      DELETE FROM appljobs
      WHERE last_seen < (
        SELECT last_upload
        FROM upload_metadata
        WHERE id = 1
      ) OR last_seen IS NULL;
    `;

    pool.query(query, (err, result) => {
      if (err) {
        console.error("Error during deleteOldJobs query:", err);
        logMessage(`Error during deleteOldJobs query: ${err}`, logFilePath);
        logToDatabase("error", "appljobs_delete.js", `Error during deleteOldJobs query: ${err}`);
        return reject(err);
      }

      console.log(`Deleted ${result.affectedRows} jobs from appljobs.`);
      logMessage(`Deleted ${result.affectedRows} jobs from appljobs.`, logFilePath);
      logToDatabase("success", "appljobs_delete.js", `Deleted ${result.affectedRows} jobs from appljobs.`);

      resolve(result.affectedRows);
    });
  });
};

// Start the deletion process
deleteOldJobs()
  .then((deletedRows) => {
    console.log(`Process completed. Total jobs deleted: ${deletedRows}`);
    logMessage(`Process completed. Total jobs deleted: ${deletedRows}`, logFilePath);
    logToDatabase("warning", "appljobs_delete.js", `Process completed. Total jobs deleted: ${deletedRows}`);
    pool.end((err) => {
      if (err) {
        console.error("Error closing the pool:", err);
        process.exit(1); // Exit with failure if there's an error closing the pool
      } else {
        console.log("Connection pool closed.");
        process.exit(0); // Exit successfully
      }
    });
  })
  .catch((err) => {
    console.error("Error during the deletion process:", err);
    logMessage(`Error during the deletion process: ${err}`, logFilePath);
    logToDatabase("error", "appljobs_delete.js", `Error during the deletion process: ${err}`);
    pool.end(() => {
      process.exit(1); // Exit with failure if an error occurs
    });
  });