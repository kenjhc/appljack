require("dotenv").config();
const mysql = require("mysql");

// Create MySQL pool
const pool = mysql.createPool({
  connectionLimit: 10,
  host: "localhost",
  user: "appljack_johnny",
  password: "app1j0hnny01$",
  database: "appljack_core",
  charset: "utf8mb4",
  connectTimeout: 900000,
  acquireTimeout: 900000,
});

let totalDeleted = 0;
const batchSize = 10000;

// Function to get the latest upload timestamp
const getLastUploadTimestamp = async () => {
  return new Promise((resolve, reject) => {
    const query = "SELECT last_upload FROM upload_metadata ORDER BY id DESC LIMIT 1";
    pool.query(query, (err, result) => {
      if (err) return reject(err);
      if (result.length > 0) {
        resolve(result[0].last_upload);
      } else {
        reject(new Error("No upload timestamp found in upload_metadata table."));
      }
    });
  });
};

// Function to select jobs that need to be deleted in batches
const selectStaleJobs = async (lastUploadTimestamp, offset) => {
  console.log(`Selecting stale jobs with OFFSET ${offset}`);
  return new Promise((resolve, reject) => {
    const query = `
      SELECT job_reference, jobpoolid
      FROM appljobs
      WHERE last_seen < ?
      LIMIT ${batchSize} OFFSET ${offset}
    `;

    pool.query(query, [lastUploadTimestamp], (err, result) => {
      if (err) {
        console.error("Error during selectStaleJobs query:", err);
        return reject(err);
      }
      console.log(`Selected ${result.length} stale jobs to delete.`);
      resolve(result);
    });
  });
};

// Function to delete stale jobs
const deleteStaleJobs = async (lastUploadTimestamp) => {
  return new Promise((resolve, reject) => {
    const query = `
      DELETE FROM appljobs
      WHERE last_seen < ?
      LIMIT ${batchSize};
    `;

    pool.query(query, [lastUploadTimestamp], (err, result) => {
      if (err) {
        console.error('Error during deletion process:', err);
        return reject(err);
      }

      console.log(`Deleted ${result.affectedRows} stale jobs from appljobs.`);
      resolve(result.affectedRows);
    });
  });
};

// Function to process deletions in batches
const processDeletions = async () => {
  try {
    console.log("Starting the deletion process...");

    // Get the latest upload timestamp
    const lastUploadTimestamp = await getLastUploadTimestamp();
    console.log(`Using last upload timestamp: ${lastUploadTimestamp}`);

    let offset = 0;
    let batchSizeReached = true;

    while (batchSizeReached) {
      console.log(`Processing batch with offset ${offset}...`);
      const staleJobs = await selectStaleJobs(lastUploadTimestamp, offset);
      batchSizeReached = staleJobs.length > 0;

      if (!batchSizeReached) break; // If no more jobs are selected, break the loop

      const deletedRows = await deleteStaleJobs(lastUploadTimestamp);
      totalDeleted += deletedRows;

      console.log(`Deleted ${deletedRows} jobs in this batch. Total deleted so far: ${totalDeleted}.`);

      offset += batchSize;
    }

    console.log(`Deletion process complete. Total jobs deleted: ${totalDeleted}`);
  } catch (err) {
    console.error("Error during deletion process:", err);
  } finally {
    pool.end();  // Ensure the pool closes only after all operations complete
  }
};

// Start the deletion process
processDeletions().then(() => {
  console.log("All deletions and truncations complete.");
});
