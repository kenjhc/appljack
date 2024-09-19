// require("dotenv").config();

const mysql = require("mysql");
const fs = require("fs");
const moment = require("moment-timezone"); // Import moment-timezone
const { logMessage, logToDatabase } = require("./utils/helpers");
const config = require("./config");

// Create a connection pool
const pool = mysql.createPool({
  host: config.host,
  user: config.username,
  password: config.password,
  database: config.database,
  charset: config.charset,
});

const logFilePath = "applcleanevents.log";

// Function to generate the correct time range in EDT timezone
const generateTimeRange = () => {
  // Convert current time to EDT
  const currentTimestamp = moment().tz("America/New_York");
  const endTime = currentTimestamp.format("YYYY-MM-DD HH:mm:ss");
  const startTime = currentTimestamp
    .subtract(30, "minutes")
    .format("YYYY-MM-DD HH:mm:ss");

  return { startTime, endTime };
};

const moveDuplicateRecordsInBatches = (batchSize) => {
  logMessage(`Script started processing.`, logFilePath);

  let batchNumber = 1;

  const processBatch = () => {
    logMessage(`Starting batch ${batchNumber}...`, logFilePath);

    const { startTime, endTime } = generateTimeRange();
    logMessage(`Start Time: ${startTime}, End Time: ${endTime}`, logFilePath);

    logToDatabase(
      "warning",
      "applcleaneventstime.js",
      `Start Time: ${startTime}, End Time: ${endTime}`
    );

    const duplicateQuery = `
      SELECT t1.id, t1.eventid, t1.timestamp, t1.ipaddress, t1.jobid, t1.feedid
      FROM applevents t1
      INNER JOIN applevents t2
        ON t1.ipaddress = t2.ipaddress
        AND t1.jobid = t2.jobid
        AND t1.feedid = t2.feedid
        AND t1.id > t2.id
        AND t1.id <= t2.id + 10
        AND TIMESTAMPDIFF(SECOND, t2.timestamp, t1.timestamp) < 30
      WHERE t1.timestamp >= '${startTime}'
      AND t1.timestamp < '${endTime}'
      LIMIT ${batchSize};
    `;

    logMessage(
      `Executing duplicate query at ${new Date().toISOString()}...`,
      logFilePath
    );
    logToDatabase(
      "warning",
      "applcleaneventstime.js",
      `Executing duplicate query at ${new Date().toISOString()}...`
    );

    pool.query(duplicateQuery, (error, results) => {
      if (error) {
        logMessage(
          `Error executing duplicate query: ${error.message}`,
          logFilePath
        );
        logToDatabase(
          "error",
          "applcleaneventstime.js",
          `Error executing duplicate query: ${error.message}`
        );

        pool.end(() => {
          process.exit(1); // Exit with error code
        });
        return;
      }

      if (results.length === 0) {
        logMessage(
          `No more duplicate records found. Completed processing ${
            batchNumber - 1
          } batches.`,
          logFilePath
        );
        logToDatabase(
          "success",
          "applcleaneventstime.js",
          `No more duplicate records found. Completed processing ${
            batchNumber - 1
          } batches.`
        );

        pool.end(() => {
          process.exit(1); // Exit with error code
        });
        return;
      }

      logMessage(
        `Batch ${batchNumber}: Found ${results.length} duplicate records.`,
        logFilePath
      );
      logToDatabase(
        "warning",
        "applcleaneventstime.js",
        `Batch ${batchNumber}: Found ${results.length} duplicate records.`
      );

      const duplicateIds = results.map((row) => row.id).join(",");
      logMessage(
        `Batch ${batchNumber}: Duplicate IDs: ${duplicateIds}`,
        logFilePath
      );
      logToDatabase(
        "warning",
        "applcleaneventstime.js",
        `Batch ${batchNumber}: Duplicate IDs: ${duplicateIds}`
      );

      pool.getConnection((err, connection) => {
        if (err) {
          logMessage(`Error getting connection: ${err.message}`, logFilePath);
          logToDatabase(
            "error",
            "applcleaneventstime.js",
            `Error getting connection: ${err.message}`
          );

          pool.end(() => {
            process.exit(1); // Exit with error code
          });
          return;
        }

        connection.beginTransaction((transactionError) => {
          if (transactionError) {
            logMessage(
              `Error starting transaction: ${transactionError.message}`,
              logFilePath
            );
            logToDatabase(
              "error",
              "applcleaneventstime.js",
              `Error starting transaction: ${transactionError.message}`
            );
            connection.release();
            pool.end(() => {
              process.exit(1); // Exit with error code
            });
            return;
          }

          const insertQuery = `
            INSERT INTO appleventsdel (eventid, timestamp, eventtype, custid, jobid, refurl, useragent, ipaddress, cpc, cpa, feedid, deletecode)
            SELECT eventid, timestamp, eventtype, custid, jobid, refurl, useragent, ipaddress, cpc, cpa, feedid, 'time'
            FROM applevents
            WHERE id IN (${duplicateIds})
          `;

          connection.query(insertQuery, (insertError, insertResults) => {
            if (insertError) {
              logMessage(
                `Error executing insert query: ${insertError.message}`,
                logFilePath
              );
              logToDatabase(
                "error",
                "applcleaneventstime.js",
                `Error executing insert query: ${insertError.message}`
              );
              return connection.rollback(() => {
                connection.release();
                pool.end(() => {
                  process.exit(1); // Exit with error code
                });
              });
            }

            logMessage(
              `Batch ${batchNumber}: Inserted ${insertResults.affectedRows} rows into appleventsdel.`,
              logFilePath
            );
            logToDatabase(
              "success",
              "applcleaneventstime.js",
              `Batch ${batchNumber}: Inserted ${insertResults.affectedRows} rows into appleventsdel`
            );
            const deleteQuery = `DELETE FROM applevents WHERE id IN (${duplicateIds})`;

            connection.query(deleteQuery, (deleteError, deleteResults) => {
              if (deleteError) {
                logMessage(
                  `Error executing delete query: ${deleteError.message}`,
                  logFilePath
                );
                logToDatabase(
                  "error",
                  "applcleaneventstime.js",
                  `Error executing delete query: ${deleteError.message}`
                );
                return connection.rollback(() => {
                  connection.release();
                  pool.end(() => {
                    process.exit(1); // Exit with error code
                  });
                });
              }

              logMessage(
                `Batch ${batchNumber}: Deleted ${deleteResults.affectedRows} rows from applevents`,
                logFilePath
              );
              logToDatabase(
                "success",
                "applcleaneventstime.js",
                `Batch ${batchNumber}: Deleted ${deleteResults.affectedRows} rows from applevents`
              );
              connection.commit((commitError) => {
                if (commitError) {
                  logMessage(
                    `Error committing transaction: ${commitError.message}`,
                    logFilePath
                  );
                  logToDatabase(
                    "success",
                    "applcleaneventstime.js",
                    `Error committing transaction: ${commitError.message}`
                  );
                  return connection.rollback(() => {
                    connection.release();
                    pool.end(() => {
                      process.exit(1); // Exit with error code
                    });
                  });
                }

                connection.release();
                batchNumber++;
                processBatch();
              });
            });
          });
        });
      });
    });
  };

  processBatch();
};

const batchSize = 200;

console.log("Running applcleaneventstime.js script...");
moveDuplicateRecordsInBatches(batchSize);