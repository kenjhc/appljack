const fs = require("fs");
const path = require("path");
const sax = require("sax");
const mysql = require("mysql2/promise");
const moment = require("moment");
const config = require("./config");
const { logMessage, logToDatabase } = require("./utils/helpers");

const batchSize = 1000;
const tempTableThreshold = 10000;
let tempTableRecordCount = 0;
let recordCount = 0;
let totalRecordsAdded = 0;
let startTime;
let tempConnection = null;
let isProcessingBatch = false;
const logFilePath = "applupload8.log";

const MAX_RETRIES = 5;
const RETRY_DELAY = 5000; // 5 seconds

const cleanDecimalValue = (value) => {
  if (typeof value === "string") {
    const cleanedValue = value.replace(/[^0-9.]/g, "");
    return cleanedValue === "" ? null : cleanedValue;
  }
  return value;
};

logMessage("Starting applupload8.js script...", logFilePath);

const createPool = () => {
  return mysql.createPool({
    connectionLimit: 10,
    host: config.host,
    user: config.username,
    password: config.password,
    database: config.database,
    charset: config.charset,
    connectTimeout: 900000,
  });
};

let pool = createPool();

const retryOperation = async (operation, retries = MAX_RETRIES) => {
  try {
    return await operation();
  } catch (error) {
    if (retries > 0) {
      console.log(`Retrying operation. Attempts left: ${retries}`);
      logMessage(`Retrying operation. Attempts left: ${retries}`, logFilePath);
      await new Promise((resolve) => setTimeout(resolve, RETRY_DELAY));
      return retryOperation(operation, retries - 1);
    } else {
      throw error;
    }
  }
};

const updateLastUpload = async () => {
  const query = `
    INSERT INTO upload_metadata (id, last_upload)
    VALUES (1, CURRENT_TIMESTAMP())
    ON DUPLICATE KEY UPDATE last_upload = CURRENT_TIMESTAMP();
  `;

  await retryOperation(async () => {
    const [result] = await pool.query(query);
    console.log("last_upload field updated or entry created.");
    logMessage("last_upload field updated or entry created.", logFilePath);
    logToDatabase(
      "success",
      "applupload8.js",
      "last_upload field updated or entry created."
    );
    return result;
  });
};

const createTempTableOnce = async () => {
  await retryOperation(async () => {
    tempConnection = await pool.getConnection();
    await tempConnection.query(
      "CREATE TEMPORARY TABLE IF NOT EXISTS appljobs_temp LIKE appljobs"
    );
    console.log("Temporary table created successfully");
    logMessage("Temporary table created successfully", logFilePath);
    logToDatabase(
      "success",
      "applupload8.js",
      "Temporary table created successfully"
    );
  });
};

const truncateTempTable = async () => {
  await retryOperation(async () => {
    await tempConnection.query("TRUNCATE TABLE appljobs_temp");
    console.log("Temp table truncated.");
    logMessage("Temp table truncated.", logFilePath);
    logToDatabase("success", "applupload8.js", "Temp table truncated.");
    tempTableRecordCount = 0;
  });
};

const insertIntoApplJobsFresh = async (batch) => {
  const values = batch.map((item) => [item.job_reference, item.jobpoolid]);
  const query = `
    INSERT INTO appljobsfresh (job_reference, jobpoolid)
    VALUES ? ON DUPLICATE KEY UPDATE job_reference=VALUES(job_reference)
  `;

  await retryOperation(async () => {
    await tempConnection.query(query, [values]);
  });
};

const getAcctNum = async (jobpoolid) => {
  const query = "SELECT acctnum FROM appljobseed WHERE jobpoolid = ?";
  const [results] = await retryOperation(async () => {
    return tempConnection.query(query, [jobpoolid]);
  });
  return results.length > 0 ? results[0].acctnum : null;
};

const loadMapping = async (jobpoolid) => {
  const query =
    "SELECT xml_tag, db_column FROM appldbmapping WHERE jobpoolid = ?";
  const [results] = await retryOperation(async () => {
    return tempConnection.query(query, [jobpoolid]);
  });

  const mapping = {};
  results.forEach(({ xml_tag, db_column }) => {
    mapping[xml_tag] = db_column;
  });
  mapping.jobreference = "job_reference";
  mapping.custid = "custid";
  mapping.jobpoolid = "jobpoolid";
  mapping.acctnum = "acctnum";

  return mapping;
};

const insertIntoTempTable = async (allHobs) => {
  const jobs = [...allHobs];
  const values = jobs.map((item) => [
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

  const query = `
    INSERT INTO appljobs_temp (feedId, location, title, city, state, zip, country, job_type,
                               posted_at, job_reference, company, mobile_friendly_apply,
                               category, html_jobs, url, body, jobpoolid, acctnum, industry,
                               cpc, cpa, custom1, custom2, custom3, custom4, custom5)
    VALUES ? ON DUPLICATE KEY UPDATE job_reference=VALUES(job_reference)
  `;

  await retryOperation(async () => {
    const [result] = await tempConnection.query(query, [values]);
    recordCount += jobs.length;
    console.log(`${jobs.length} records inserted into temp table.`);
    logMessage(`${jobs.length} records inserted into temp table.`, logFilePath);
    logToDatabase(
      "success",
      "applupload8.js",
      `${jobs.length} records inserted into temp table.`
    );
    return result;
  });
};

const transferToApplJobsTable = async () => {
  const currentTimestamp = moment().format("YYYY-MM-DD HH:mm:ss");

  await retryOperation(async () => {
    await tempConnection.beginTransaction();

    try {
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
            aj.custom5 = ajt.custom5,
            aj.last_seen = '${currentTimestamp}';
      `;

      await tempConnection.query(updateExisting);

      const insertNew = `
        INSERT INTO appljobs (feedId, location, title, city, state, zip, country, job_type,
                              posted_at, job_reference, company, mobile_friendly_apply,
                              category, html_jobs, url, body, jobpoolid, acctnum, industry,
                              cpc, cpa, custom1, custom2, custom3, custom4, custom5, last_seen)
        SELECT ajt.feedId, ajt.location, ajt.title, ajt.city, ajt.state, ajt.zip, ajt.country,
               ajt.job_type, ajt.posted_at, ajt.job_reference, ajt.company, ajt.mobile_friendly_apply,
               ajt.category, ajt.html_jobs, ajt.url, ajt.body, ajt.jobpoolid, ajt.acctnum, ajt.industry,
               ajt.cpc, ajt.cpa, ajt.custom1, ajt.custom2, ajt.custom3, ajt.custom4, ajt.custom5,
               '${currentTimestamp}'
        FROM appljobs_temp ajt
        WHERE NOT EXISTS (
            SELECT 1 FROM appljobs aj WHERE aj.job_reference = ajt.job_reference AND aj.jobpoolid = ajt.jobpoolid
        );
      `;

      const [result] = await tempConnection.query(insertNew);

      await tempConnection.commit();

      totalRecordsAdded += result.affectedRows;
      console.log(`Total jobs added to appljobs: ${totalRecordsAdded}`);
      logMessage(
        `Total jobs added to appljobs: ${totalRecordsAdded}`,
        logFilePath
      );
      logToDatabase(
        "success",
        "applupload8.js",
        `Total jobs added to appljobs: ${totalRecordsAdded}`
      );

      await truncateTempTable();

      return result;
    } catch (error) {
      await tempConnection.rollback();
      throw error;
    }
  });
};

const processQueue = async (feedId) => {
  if (isProcessingBatch) return;
  isProcessingBatch = true;

  try {
    await retryOperation(async () => {
      let batch = jobQueue.slice(0, batchSize);
      jobQueue = jobQueue.slice(batchSize);

      await processBatch(batch, feedId);

      if (tempTableRecordCount >= tempTableThreshold) {
        console.log("Transferring records from temp table to appljobs...");
        logMessage(
          "Transferring records from temp table to appljobs...",
          logFilePath
        );
        logToDatabase(
          "success",
          "applupload8.js",
          "Transferring records from temp table to appljobs..."
        );
        await transferToApplJobsTable();
      }
    });
  } catch (err) {
    console.error("Error processing queue:", err);
    logMessage(`Error processing queue: ${err}`, logFilePath);
    logToDatabase("error", "applupload8.js", `Error processing queue: ${err}`);
  } finally {
    isProcessingBatch = false;
    if (jobQueue.length > 0) processQueue(feedId);
  }
};

const processBatch = async (batch, feedId) => {
  try {
    console.log(
      `Processing a batch of ${batch.length} records for feedId ${feedId}`
    );
    logMessage(
      `Processing a batch of ${batch.length} records for feedId ${feedId}`,
      logFilePath
    );
    logToDatabase(
      "warning",
      "applupload8.js",
      `Processing a batch of ${batch.length} records for feedId ${feedId}`
    );

    await insertIntoTempTable(batch);
    await insertIntoApplJobsFresh(batch);

    console.log("Transferring records from temp table to appljobs...");
    logMessage(
      "Transferring records from temp table to appljobs...",
      logFilePath
    );
    logToDatabase(
      "success",
      "applupload8.js",
      "Transferring records from temp table to appljobs..."
    );
    await transferToApplJobsTable();
    await truncateTempTable();

    batch = null;

    if (global.gc) {
      global.gc();
      console.log("Garbage collection triggered.");
      logMessage("Garbage collection triggered.", logFilePath);
      logToDatabase(
        "warning",
        "applupload8.js",
        "Garbage collection triggered."
      );
    }

    const usedMemory = process.memoryUsage();
    console.log(
      `Memory Usage: Heap Used: ${(usedMemory.heapUsed / 1024 / 1024).toFixed(
        2
      )} MB, RSS: ${(usedMemory.rss / 1024 / 1024).toFixed(2)} MB`
    );
    logMessage(
      `Memory Usage: Heap Used: ${(usedMemory.heapUsed / 1024 / 1024).toFixed(
        2
      )} MB, RSS: ${(usedMemory.rss / 1024 / 1024).toFixed(2)} MB`,
      logFilePath
    );
    logToDatabase(
      "warning",
      "applupload8.js",
      `Memory Usage: Heap Used: ${(usedMemory.heapUsed / 1024 / 1024).toFixed(
        2
      )} MB, RSS: ${(usedMemory.rss / 1024 / 1024).toFixed(2)} MB`
    );
  } catch (err) {
    console.error("Error processing batch:", err);
    logMessage(`Error processing batch: ${err}`, logFilePath);
    logToDatabase("error", "applupload8.js", `Error processing batch: ${err}`);
  }
};

const countTags = (filePath) => {
  return new Promise((resolve, reject) => {
    const stream = fs.createReadStream(filePath);
    const parser = sax.createStream(true);
    let tagCount = 0;

    parser.on("opentag", (node) => {
      if (node.name === "job" || node.name === "doc") {
        tagCount++;
      }
    });

    parser.on("end", () => {
      resolve(tagCount);
    });

    parser.on("error", (err) => {
      reject(err);
    });

    stream.pipe(parser);
  });
};

const parseXmlFile = async (filePath) => {
  console.log(`Starting to process file: ${filePath}`);
  logMessage(`Starting to process file: ${filePath}`, logFilePath);
  logToDatabase(
    "success",
    "applupload8.js",
    `Starting to process file: ${filePath}`
  );

  const feedId = path.basename(filePath, path.extname(filePath));
  const parts = feedId.split("-");
  const jobpoolid = parts[1];
  const acctnum = await getAcctNum(jobpoolid);
  const tagToPropertyMap = await loadMapping(jobpoolid);
  const totalTags = await countTags(filePath);
  let processedTags = 0;
  let totalProcessedJobs = 0;

  return new Promise((resolve, reject) => {
    const stream = fs.createReadStream(filePath);
    const parser = sax.createStream(true);
    let currentItem = {};
    let currentTag = "";
    let currentJobElement = "";
    let jobs = [];
    const CHUNK_SIZE = 1000;

    parser.on("opentag", (node) => {
      currentTag = node.name;
      if (node.name === "job" || node.name === "doc") {
        console.log({node: node.name});
        
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
        let trimmedCdata = cdata.trim();
        if (tagToPropertyMap[currentTag]) {
          let propertyName = tagToPropertyMap[currentTag];
          currentItem[propertyName] =
            (currentItem[propertyName] || "") + trimmedCdata;
        } else {
          currentItem[currentTag] =
            (currentItem[currentTag] || "") + trimmedCdata;
        }
      }
    });

    parser.on("closetag", async (nodeName) => {
      if (nodeName === currentJobElement) {
        processedTags++;

        currentItem.jobpoolid = jobpoolid;
        currentItem.acctnum = acctnum;

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
            console.warn(`Invalid date format found: ${currentItem.posted_at}`);
            logMessage(
              `Invalid date format found: ${currentItem.posted_at}`,
              logFilePath
            );
            logToDatabase(
              "warning",
              "applupload8.js",
              `Invalid date format found: ${currentItem.posted_at}`
            );
            currentItem.posted_at = null;
          }
        }

        jobs.push(currentItem);
        if (jobs.length === CHUNK_SIZE || processedTags === totalTags) {
          try {
            insertIntoTempTable(jobs);
            totalProcessedJobs += jobs.length;
            jobs = [];
          } catch (err) {
            parser.emit("error", err);
          }
        }
      }
    });

    parser.on("end", async () => {
      try {
        if (jobs.length > 0) {
          await insertIntoTempTable(jobs);
          totalProcessedJobs += jobs.length;
        }
        console.log(`Total jobs processed: ${totalProcessedJobs}`);
        await transferToApplJobsTable();
        resolve();
      } catch (err) {
        reject(err);
      }
    });

    parser.on("error", (err) => {
      console.error(`Error parsing XML file ${filePath}:`, err);
      logMessage(`Error parsing XML file ${filePath}: ${err}`, logFilePath);
      logToDatabase(
        "error",
        "applupload8.js",
        `Error parsing XML file ${filePath}: ${err}`
      );
      reject(err);
    });

    stream.pipe(parser);
  });
};

const processFiles = async () => {
  // const directoryPath = "feedsclean/";
  const directoryPath = "/chroot/home/appljack/appljack.com/html/feedsclean/";

  try {
    await updateLastUpload();
    await createTempTableOnce();

    const filePaths = fs
      .readdirSync(directoryPath)
      .filter((file) => path.extname(file) === ".xml")
      .map((file) => path.join(directoryPath, file));

    const _filePaths = fs
      .readdirSync(directoryPath)
      .filter((file) => file === '8215880437-9849416351.xml') // Check for the exact file name
      .map((file) => path.join(directoryPath, file));

    for (const filePath of filePaths) {
      try {
        recordCount = 0;
        startTime = Date.now();

        await parseXmlFile(filePath);

        logProgress();
      } catch (err) {
        console.error(`Error processing XML file ${filePath}:`, err);
        logMessage(
          `Error processing XML file ${filePath}: ${err}`,
          logFilePath
        );
        logToDatabase(
          "error",
          "applupload8.js",
          `Error processing XML file ${filePath}: ${err}`
        );
      }
    }
  } catch (err) {
    console.error("An error occurred:", err);
    logMessage(`An error occurred: ${err}`, logFilePath);
    logToDatabase("error", "applupload8.js", `An error occurred: ${err}`);
  } finally {
    await closeConnectionPool();
  }
};

const logProgress = () => {
  const elapsedTime = (Date.now() - startTime) / 1000;
  console.log(
    `Processed ${recordCount} records, Time elapsed ${elapsedTime.toFixed(
      2
    )} seconds`
  );
  logMessage(
    `Processed ${recordCount} records, Time elapsed ${elapsedTime.toFixed(
      2
    )} seconds`,
    logFilePath
  );
  logToDatabase(
    "warning",
    "applupload8.js",
    `Processed ${recordCount} records, Time elapsed ${elapsedTime.toFixed(
      2
    )} seconds`
  );
};

const closeConnectionPool = async () => {
  if (tempConnection) {
    await tempConnection.release();
  }
  await pool.end();
  console.log("Connection pool closed successfully.");
  logMessage("Connection pool closed successfully.", logFilePath);
  logToDatabase(
    "success",
    "applupload8.js",
    "Connection pool closed successfully."
  );
};

processFiles()
  .then(() => {
    console.log("All processing complete.");
    logMessage("All processing complete.", logFilePath);
    logToDatabase("success", "applupload8.js", "All processing complete.");
    process.exit(0);
  })
  .catch((error) => {
    console.error("An error occurred during processing:", error);
    logMessage(`An error occurred during processing: ${error}`, logFilePath);
    logToDatabase(
      "error",
      "applupload8.js",
      `An error occurred during processing: ${error}`
    );
    process.exit(1);
  });
