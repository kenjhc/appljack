const fs = require("fs"); // Regular fs for async methods
const mysql = require("mysql2");
const config = require("./config");
const { logMessage, logToDatabase } = require("./utils/helpers");

// Create MySQL pool
const pool = mysql.createPool({
  connectionLimit: 20,
  host: config.host,
  user: config.username,
  password: config.password,
  database: config.database,
  charset: config.charset,
  connectTimeout: 1500000,
  waitForConnections: true,
  queueLimit: 0,
});

let activeQueries = 0;

// Function to log messages asynchronously to a file
const isTesting = false; // Set to false when you're ready to enable logging

// Function to log messages asynchronously to a file
const logMessageAsync = async (message, filePath, overwrite = false) => {
  if (isTesting) {
    // Skip log writing during testing without any console output
    return;
  }

  try {
    if (overwrite) {
      // If `overwrite` is true, clear the file first
      await fs.promises.writeFile(filePath, ""); // This clears the file content
    }
    // Now append the message to the log file
    await fs.promises.appendFile(filePath, message + "\n");
  } catch (err) {
    console.error("Error writing to log file:", err);
  }
};

// Log query start and end
const logQueryStart = () => {
  activeQueries++;
  console.log(`Starting query. Active queries: ${activeQueries}`);
};

const logQueryEnd = () => {
  activeQueries--;
  console.log(`Query finished. Active queries: ${activeQueries}`);
};

// Query function with retry on failure
const query = async (sql, params) => {
  logQueryStart();
  try {
    const [rows] = await pool.promise().query(sql, params);
    logQueryEnd();
    return rows;
  } catch (err) {
    logQueryEnd();
    console.error("Error during query:", err);
    await logMessageAsync(`Error during query: ${err}`, "appljobs_delete.log");
    await logToDatabase(
      "error",
      "appljobs_delete.js",
      `Error during query: ${err}`
    );
    throw err; // Rethrow error after logging
  }
};

// Log pool events
const logPoolEvents = () => {
  pool.on("connection", (connection) =>
    console.log("New connection:", connection.threadId)
  );
  pool.on("acquire", (connection) =>
    console.log("Connection acquired:", connection.threadId)
  );
  pool.on("release", (connection) =>
    console.log("Connection released:", connection.threadId)
  );
  pool.on("error", (err) => {
    if (err.code === "ETIMEDOUT") {
      console.error("Connection pool timeout occurred:", err);
      console.log("Active queries:", activeQueries);
    }
  });
};

// Function to log jobs before deletion, grouped by jobpoolid
const logDeletions = async () => {
  await logMessageAsync(
    "Logging jobs before deletion...",
    "appljobs_delete.log"
  );
  await logToDatabase(
    "success",
    "appljobs_delete.js",
    "Logging jobs before deletion..."
  );

  const queryStr = `
    SELECT jobpoolid, id, last_seen,
      CASE
        WHEN last_seen IS NULL THEN 'last_seen is NULL'
        WHEN last_seen < (SELECT last_upload FROM upload_metadata WHERE id = 1) THEN 'last_seen is older than last_upload'
      END as reason
    FROM appljobs
    WHERE last_seen < (SELECT last_upload FROM upload_metadata WHERE id = 1)
    OR last_seen IS NULL;
  `;

  const results = await query(queryStr);

  if (results.length > 0) {
    const groupedByJobpoolid = results.reduce((acc, row) => {
      acc[row.jobpoolid] = acc[row.jobpoolid] || [];
      acc[row.jobpoolid].push(row);
      return acc;
    }, {});

    for (const jobpoolid in groupedByJobpoolid) {
      await logMessageAsync(`Jobpool ID: ${jobpoolid}`, "appljobs_delete.log");
      await logToDatabase(
        "info",
        "appljobs_delete.js",
        `Jobpool ID: ${jobpoolid}`
      );

      for (const row of groupedByJobpoolid[jobpoolid]) {
        const logMsg = `  Job ID: ${row.id}, last_seen: ${row.last_seen}, Reason: ${row.reason}`;
        await logMessageAsync(logMsg, "appljobs_delete.log");
        await logToDatabase("info", "appljobs_delete.js", logMsg);
      }
    }
  } else {
    await logMessageAsync("No jobs found to delete.", "appljobs_delete.log");
    await logToDatabase(
      "info",
      "appljobs_delete.js",
      "No jobs found to delete."
    );
  }
};

// Function to delete jobs after logging
const deleteOldJobs = async () => {
  try {
    await logMessageAsync(
      "Starting deleteOldJobs process...",
      "appljobs_delete.log"
    );
    await logToDatabase(
      "success",
      "appljobs_delete.js",
      "Starting deleteOldJobs process..."
    );

    const delay = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
    const batchSize = 1000;
    let totalDeletedRows = 0;

    const getLastUploadQuery = `SELECT last_upload FROM upload_metadata WHERE id = 1 LIMIT 1`;
    const [result] = await query(getLastUploadQuery);

    console.log("Query result:", result); // Log the query result

    const lastUpload = result?.last_upload; // Directly access `last_upload`
    console.log("Last Upload:", lastUpload); // Log the last_upload value

    if (!lastUpload) {
      console.error("No last_upload value found");
      await logMessageAsync(
        "No last_upload value found",
        "appljobs_delete.log"
      );
      await logToDatabase(
        "error",
        "appljobs_delete.js",
        "No last_upload value found"
      );
      return; // Exit early if no last_upload value
    }

    const lastUploadDate = new Date(lastUpload);

    if (isNaN(lastUploadDate.getTime())) {
      console.error("Invalid last_upload value found");
      await logMessageAsync(
        "Invalid last_upload value found",
        "appljobs_delete.log"
      );
      await logToDatabase(
        "error",
        "appljobs_delete.js",
        "Invalid last_upload value found"
      );
      return;
    }

    const deleteBatch = async () => {
      const queryStr = `
        DELETE FROM appljobs
        WHERE last_seen < ? OR last_seen IS NULL
        LIMIT ${batchSize};
      `;
      try {
        const deleteResult = await query(queryStr, [lastUploadDate]);
        console.log("Delete result:", deleteResult); // Log the result for debugging

        const deletedRows = deleteResult?.affectedRows || 0; // Default to 0 if no result is returned
        totalDeletedRows += deletedRows;

        console.log(`Deleted ${deletedRows} jobs from appljobs in this batch.`);
        await logMessageAsync(
          `Deleted ${deletedRows} jobs from appljobs in this batch.`,
          "appljobs_delete.log"
        );
        await logToDatabase(
          "info",
          "appljobs_delete.js",
          `Deleted ${deletedRows} jobs from appljobs in this batch.`
        );

        // If any rows were deleted, we continue to the next batch
        if (deletedRows > 0) {
          console.log("Continuing to delete remaining rows...");
          await delay(100); // Add a slight delay before the next batch
          await deleteBatch(); // Continue with the next batch, regardless of the number deleted
        } else {
          // No rows were deleted, meaning we're done
          console.log(
            "Deletion process complete. Total deleted rows:",
            totalDeletedRows
          );
          await logMessageAsync(
            `Deletion process complete. Total deleted rows: ${totalDeletedRows}`,
            "appljobs_delete.log"
          );
          await logToDatabase(
            "info",
            "appljobs_delete.js",
            `Deletion process complete. Total deleted rows: ${totalDeletedRows}`
          );
        }
      } catch (error) {
        console.error("Error during deleteBatch:", error);
        await logMessageAsync(
          `Error during deleteBatch: ${error.message}`,
          "appljobs_delete.log"
        );
        await logToDatabase(
          "error",
          "appljobs_delete.js",
          `Error during deleteBatch: ${error.message}`
        );
      }
    };

    await deleteBatch();
    return totalDeletedRows;
  } catch (error) {
    console.error("Error during the deletion process:", error);
    await logMessageAsync(
      `Error during the deletion process: ${error.message}`,
      "appljobs_delete.log"
    );
    await logToDatabase(
      "error",
      "appljobs_delete.js",
      `Error during the deletion process: ${error.message}`
    );
    throw error;
  }
};

// Start the logging and deletion process
const runProcess = async () => {
  try {
    // Overwrite the log file at the start
    await logMessageAsync(
      "Starting process at " + new Date().toISOString(),
      "appljobs_delete.log",
      true
    );
    // await logDeletions();
    const deletedRows = await deleteOldJobs();
    console.log(`Process completed. Total jobs deleted: ${deletedRows}`);
    await logMessageAsync(
      `Process completed. Total jobs deleted: ${deletedRows}`,
      "appljobs_delete.log"
    );
    await logToDatabase(
      "warning",
      "appljobs_delete.js",
      `Process completed. Total jobs deleted: ${deletedRows}`
    );
  } catch (err) {
    console.error("Error during the process:", err);
    await logMessageAsync(
      `Error during the process: ${err}`,
      "appljobs_delete.log"
    );
    await logToDatabase(
      "error",
      "appljobs_delete.js",
      `Error during the process: ${err}`
    );
  } finally {
    pool.end((err) => {
      if (err) {
        console.error("Error closing the pool:", err);
        process.exit(1);
      } else {
        console.log("Connection pool closed.");
        process.exit(0);
      }
    });
  }
};

// Execute the process
runProcess();
