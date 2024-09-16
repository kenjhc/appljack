require("dotenv").config();

const fs = require("fs");
const path = require("path");
const sax = require("sax");
const mysql = require("mysql");
const moment = require("moment");
const config = require("./config");

process.on("unhandledRejection", (reason, promise) => {
  console.error("Unhandled rejection:", reason);
});

process.on("uncaughtException", (error) => {
  console.error("Uncaught exception:", error);
});

let jobQueue = [];
let isProcessingBatch = false;
const batchSize = 100;
let tempConnection;
let recordCount = 0;
let startTime;

const cleanDecimalValue = (value) => {
  if (typeof value === "string") {
    const cleanedValue = value.replace(/[^0-9.]/g, "");
    return cleanedValue === "" ? null : cleanedValue;
  }
  return value;
};

const loadMapping = async (jobpoolid) => {
  return new Promise((resolve, reject) => {
    const query =
      "SELECT xml_tag, db_column FROM appldbmapping WHERE jobpoolid = ?";
    tempConnection.query(query, [jobpoolid], (err, results) => {
      if (err) return reject(err);

      const mapping = {};

      results.forEach(({ xml_tag, db_column }) => {
        mapping[xml_tag] = db_column;
      });

      mapping.custid = "custid";
      mapping.jobpoolid = "jobpoolid";
      mapping.acctnum = "acctnum";

      resolve(mapping);
    });
  });
};

const getAcctNum = async (jobpoolid) => {
  return new Promise((resolve, reject) => {
    const query = "SELECT acctnum FROM appljobseed WHERE jobpoolid = ?";
    tempConnection.query(query, [jobpoolid], (err, results) => {
      if (err) {
        console.error("Error fetching acctnum:", err);
        return reject(err);
      }
      resolve(results.length > 0 ? results[0].acctnum : null);
    });
  });
};

const reconnectOnFatalError = async () => {
  return new Promise((resolve, reject) => {
    tempConnection.release(); // Release the broken connection
    pool.getConnection((err, connection) => {
      if (err) {
        return reject(err);
      }
      tempConnection = connection;
      console.log("Reconnected after fatal error.");
      resolve();
    });
  });
};

const createTempTableWithConnection = async () => {
  tempConnection = await new Promise((resolve, reject) => {
    pool.getConnection((err, connection) => {
      if (err) return reject(err);
      resolve(connection);
    });
  });

  return new Promise((resolve, reject) => {
    tempConnection.query(
      "CREATE TEMPORARY TABLE IF NOT EXISTS appljobs_temp LIKE appljobs",
      (err, result) => {
        if (err) {
          console.error("Failed to create temporary table", err);
          reject(err);
        } else {
          console.log("Temporary table created successfully");
          resolve();
        }
      }
    );
  });
};

const logFailedBatch = (batch, feedId, error) => {
  const logDir = path.join("/chroot/home/appljack/appljack.com/html/log");
  const logFile = path.join(logDir, "failed_batches.log");

  if (!fs.existsSync(logDir)) {
    fs.mkdirSync(logDir, { recursive: true });
  }

  const timestamp = new Date().toISOString();
  const logEntry = {
    timestamp,
    feedId,
    error: error.message,
    batchDetails: batch.map((item) => ({
      job_reference: item.job_reference,
      city: item.city,
    })),
  };

  fs.appendFile(logFile, JSON.stringify(logEntry) + "\n", (err) => {
    if (err) {
      console.error("Failed to log failed batch details:", err);
    }
  });
};

const pool = mysql.createPool({
  connectionLimit: 10,
  host: config.host,
  user: config.username,
  password: config.password,
  database: config.database,
  charset: config.charset,
});
 
const checkConnection = async () => {
  return new Promise((resolve, reject) => {
    tempConnection.ping((err) => {
      if (err) {
        console.error("Connection lost, reconnecting...");
        reconnectOnFatalError().then(resolve).catch(reject);
      } else {
        resolve();
      }
    });
  });
};

const logProgress = () => {
  const elapsedTime = (Date.now() - startTime) / 1000; // in seconds
  console.log(
    `Processed ${recordCount} records, Time elapsed ${elapsedTime.toFixed(
      2
    )} seconds`
  );
};

const processQueue = async (feedId) => {
  if (isProcessingBatch || jobQueue.length < batchSize) return;
  isProcessingBatch = true;
  const batch = jobQueue.slice(0, batchSize);
  jobQueue = jobQueue.slice(batchSize);

  try {
    await processBatch(batch, feedId);
  } catch (err) {
    console.error("Failed to process batch:", err);
    logFailedBatch(batch, feedId, err);
  } finally {
    isProcessingBatch = false;
    if (jobQueue.length >= batchSize) processQueue(feedId);
  }
};

const processBatch = async (batch, feedId) => {
  await checkConnection(); // Ensure the connection is alive before processing

  const values = batch.map((item) => [
    item.feedId,
    item.location,
    item.title,
    item.city,
    item.state,
    item.zip,
    item.country,
    item.job_type,
    item.posted_at,
    item.job_reference,
    item.company,
    item.mobile_friendly_apply,
    item.category,
    item.html_jobs,
    item.url,
    item.body,
    item.jobpoolid,
    item.acctnum,
    item.industry,
    cleanDecimalValue(item.cpc),
    cleanDecimalValue(item.cpa),
    item.custom1,
    item.custom2,
    item.custom3,
    item.custom4,
    item.custom5,
  ]);

  return new Promise((resolve, reject) => {
    const query = `
            INSERT INTO appljobs_temp (feedId, location, title, city, state, zip, country, job_type,
                                       posted_at, job_reference, company, mobile_friendly_apply,
                                       category, html_jobs, url, body, jobpoolid, acctnum, industry,
                                       cpc, cpa, custom1, custom2, custom3, custom4, custom5)
            VALUES ? ON DUPLICATE KEY UPDATE job_reference=VALUES(job_reference)
        `;

    tempConnection.query(query, [values], async (err, result) => {
      if (err) {
        if (err.code === "PROTOCOL_ENQUEUE_AFTER_FATAL_ERROR") {
          console.error("Fatal error occurred, reconnecting...");
          await reconnectOnFatalError(); // Reconnect before retrying
          return reject(err);
        } else {
          return reject(err);
        }
      }
      recordCount += batch.length;
      if (recordCount % 10000 === 0) {
        logProgress();
      }
      resolve();
    });
  });
};

// Define parseXmlFile function
const parseXmlFile = async (filePath) => {
  console.log(`Starting to process file: ${filePath}`);
  const feedId = path.basename(filePath, path.extname(filePath));
  const parts = feedId.split("-");
  const jobpoolid = parts[1];
  const acctnum = await getAcctNum(jobpoolid);
  const tagToPropertyMap = await loadMapping(jobpoolid);

  try {
    return new Promise((resolve, reject) => {
      const stream = fs.createReadStream(filePath);
      const parser = sax.createStream(true);
      let currentItem = {};
      let currentTag = "";
      let currentJobElement = "";

      parser.on("opentag", (node) => {
        currentTag = node.name;
        if (node.name === "job" || node.name === "doc") {
          currentJobElement = node.name;
          currentItem = { feedId: feedId };
        }
      });

      parser.on("text", (text) => {
        if (currentItem && currentTag) {
          let trimmedText = text.trim();
          if (tagToPropertyMap[currentTag]) {
            let propertyName = tagToPropertyMap[currentTag];
            currentItem[propertyName] =
              (currentItem[propertyName] || "") + trimmedText;
          } else {
            currentItem[currentTag] =
              (currentItem[currentTag] || "") + trimmedText;
          }
        }
      });

      parser.on("cdata", (cdata) => {
        if (currentItem && currentTag) {
          if (tagToPropertyMap[currentTag]) {
            let propertyName = tagToPropertyMap[currentTag];
            currentItem[propertyName] =
              (currentItem[propertyName] || "") + cdata;
          } else {
            currentItem[currentTag] = (currentItem[currentTag] || "") + cdata;
          }
        }
      });

      parser.on("closetag", (nodeName) => {
        if (nodeName === currentJobElement) {
          currentItem.jobpoolid = jobpoolid;
          currentItem.acctnum = acctnum;

          // Format date
          if (currentItem.posted_at) {
            const dateFormats = [
              "YYYY-MM-DD HH:mm:ss.SSS [UTC]",
              "YYYY-MM-DDTHH:mm:ss.SSS[Z]",
              "ddd, DD MMM YYYY HH:mm:ss [UTC]",
              "YYYY-MM-DD",
              "YYYY-MM-DDTHH:mm:ss.S",
              "YYYY-MM-DDTHH:mm:ss.SSS",
              "YYYY-MM-DDTHH:mm:ss.SS",
              "YYYY-MM-DDTHH:mm:ss",
            ];
            const date = moment(currentItem.posted_at, dateFormats, true);
            if (date.isValid()) {
              currentItem.posted_at = date.format("YYYY-MM-DD");
            } else {
              console.warn(
                `Invalid date format found: ${currentItem.posted_at}`
              );
              currentItem.posted_at = null;
            }
          }

          jobQueue.push(currentItem);
          processQueue(feedId).catch((err) =>
            console.error("Error processing queue:", err)
          );
        }
      });

      parser.on("end", async () => {
        if (jobQueue.length > 0) {
          await processQueue(feedId);
        }
        console.log("XML file processing completed.");
        resolve();
      });

      parser.on("error", (err) => {
        console.error(`Error parsing XML file ${filePath}:`, err);
        reject(err);
      });

      stream.pipe(parser);
    });
  } catch (error) {
    console.error(`Error processing XML file ${filePath}:`, error);
    throw error;
  }
};

// Define logApplJobsTempForJobReference function
const logApplJobsTempForJobReference = async (jobReference) => {
  return new Promise((resolve, reject) => {
    const query = "SELECT * FROM appljobs_temp WHERE job_reference = ?";
    tempConnection.query(query, [jobReference], (err, results) => {
      if (err) {
        console.error("Error fetching job reference from appljobs_temp:", err);
        return reject(err);
      }
      console.log(
        `Entries in appljobs_temp for job_reference ${jobReference}:`,
        results
      );
      resolve(results);
    });
  });
};

// Define processRemainingJobs function
const processRemainingJobs = async () => {
  while (jobQueue.length > 0) {
    const job = jobQueue.shift();
    await processBatch([job], job.feedId);
  }
};

// Define updateApplJobsTable function (missing previously)
const updateApplJobsTable = async () => {
  return new Promise((resolve, reject) => {
    tempConnection.beginTransaction((err) => {
      if (err) {
        console.error(
          "Failed to start transaction for updating appljobs:",
          err
        );
        return reject(err);
      }

      const updateExisting = `
                UPDATE appljobs aj
                JOIN appljobs_temp ajt ON aj.job_reference = ajt.job_reference AND aj.jobpoolid = ajt.jobpoolid
                SET aj.feedId = ajt.feedId,
                    aj.location = ajt.location,
                    aj.title = ajt.title,
                    aj.city = ajt.city,
                    aj.state = ajt.state,
                    aj.zip = ajt.zip,
                    aj.country = ajt.country,
                    aj.job_type = ajt.job_type,
                    aj.posted_at = ajt.posted_at,
                    aj.company = ajt.company,
                    aj.mobile_friendly_apply = ajt.mobile_friendly_apply,
                    aj.category = ajt.category,
                    aj.html_jobs = ajt.html_jobs,
                    aj.url = ajt.url,
                    aj.body = ajt.body,
                    aj.jobpoolid = ajt.jobpoolid,
                    aj.acctnum = ajt.acctnum,
                    aj.industry = ajt.industry,
                    aj.cpc = ajt.cpc,
                    aj.cpa = ajt.cpa,
                    aj.custom1 = ajt.custom1,
                    aj.custom2 = ajt.custom2,
                    aj.custom3 = ajt.custom3,
                    aj.custom4 = ajt.custom4,
                    aj.custom5 = ajt.custom5
                WHERE aj.job_reference IS NOT NULL AND aj.jobpoolid IS NOT NULL;
            `;

      tempConnection.query(updateExisting, (err, result) => {
        if (err) {
          console.error("Failed to update existing records in appljobs:", err);
          tempConnection.rollback();
          reject(err);
          return;
        }
        console.log(
          `Updated existing records: ${result.affectedRows} rows updated.`
        );

        const insertNew = `
                    INSERT INTO appljobs (feedId, location, title, city, state, zip, country, job_type,
                                          posted_at, job_reference, company, mobile_friendly_apply,
                                          category, html_jobs, url, body, jobpoolid, acctnum, industry,
                                          cpc, cpa, custom1, custom2, custom3, custom4, custom5)
                    SELECT ajt.feedId, ajt.location, ajt.title, ajt.city, ajt.state, ajt.zip, ajt.country,
                           ajt.job_type, ajt.posted_at, ajt.job_reference, ajt.company, ajt.mobile_friendly_apply,
                           ajt.category, ajt.html_jobs, ajt.url, ajt.body, ajt.jobpoolid, ajt.acctnum, ajt.industry,
                           ajt.cpc, ajt.cpa, ajt.custom1, ajt.custom2, ajt.custom3, ajt.custom4, ajt.custom5
                    FROM appljobs_temp ajt
                    WHERE NOT EXISTS (
                        SELECT 1 FROM appljobs aj WHERE aj.job_reference = ajt.job_reference AND aj.jobpoolid = ajt.jobpoolid
                    ) AND ajt.job_reference IS NOT NULL;
                `;

        tempConnection.query(insertNew, (err, result) => {
          if (err) {
            console.error("Failed to insert new records into appljobs:", err);
            tempConnection.rollback(() => reject(err));
            return;
          }
          console.log(
            `Inserted new records: ${result.affectedRows} rows inserted.`
          );

          const deleteOld = `
                        DELETE aj
                        FROM appljobs aj
                        LEFT JOIN appljobs_temp ajt ON aj.job_reference = ajt.job_reference AND aj.jobpoolid = ajt.jobpoolid
                        WHERE ajt.job_reference IS NULL AND ajt.jobpoolid IS NULL;
                    `;

          tempConnection.query(deleteOld, (err, result) => {
            if (err) {
              console.error("Failed to delete old records from appljobs:", err);
              tempConnection.rollback(() => reject(err));
              return;
            }
            console.log(
              `Deleted old records: ${result.affectedRows} rows deleted.`
            );

            tempConnection.commit((err) => {
              if (err) {
                console.error("Failed to commit transaction:", err);
                tempConnection.rollback(() => reject(err));
                return;
              }
              console.log(
                "Transaction completed successfully, appljobs table updated."
              );
              resolve();
            });
          });
        });
      });
    });
  });
};

const closeConnectionPool = () => {
  if (tempConnection) {
    tempConnection.release(); // Release the connection back to the pool
  }
  pool.end((err) => {
    if (err) console.error("Failed to close the connection pool:", err);
    else console.log("Connection pool closed successfully.");
  });
};

const processFiles = async () => {
  const directoryPath = "/chroot/home/appljack/appljack.com/html/feedsclean/";

  try {
    await createTempTableWithConnection();
    const filePaths = fs
      .readdirSync(directoryPath)
      .filter((file) => path.extname(file) === ".xml")
      .map((file) => path.join(directoryPath, file));

    for (const filePath of filePaths) {
      try {
        // Reset record count for each file
        recordCount = 0;
        startTime = Date.now(); // Start timer for each file

        await parseXmlFile(filePath);
        await processRemainingJobs();

        // Log progress for files with fewer than 10,000 records
        if (recordCount > 0 && recordCount % 10000 !== 0) {
          logProgress(); // Log whatever number of records was processed
        }
      } catch (err) {
        console.error(`Error processing XML file ${filePath}:`, err);
      }
    }

    await logApplJobsTempForJobReference("72195333");
    await updateApplJobsTable();
    console.log("appljobs table synchronized successfully.");
  } catch (err) {
    console.error("An error occurred:", err);
  } finally {
    closeConnectionPool();
  }
};

processFiles()
  .then(() => {
    console.log("All processing complete.");
  })
  .catch((error) => {
    console.error("An error occurred during processing:", error);
  });
