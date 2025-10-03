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

// Add the missing jobQueue variable
let jobQueue = [];

const MAX_RETRIES = 5;
const RETRY_DELAY = 5000; // 5 seconds

// Add maximum field length to prevent string overflow
const MAX_FIELD_LENGTH = 1000000; // 1MB limit per field

const cleanDecimalValue = (value) => {
  if (typeof value === "string") {
    const cleanedValue = value.replace(/[^0-9.]/g, "");
    return cleanedValue === "" ? null : cleanedValue;
  }
  return value;
};

// Track which fields have been truncated for each job to avoid spam logging
const truncatedFields = new Map();

// Helper function to safely concatenate strings with length limit
const safeConcatenate = (existing, newText, maxLength = MAX_FIELD_LENGTH, fieldName = 'unknown', jobRef = 'unknown') => {
  const current = existing || "";
  const fieldKey = `${jobRef}_${fieldName}`;

  if (current.length >= maxLength) {
    // Only log once per field per job when it first hits the limit
    if (!truncatedFields.has(fieldKey)) {
      console.warn(`Field '${fieldName}' hit max length (${maxLength}) for job ${jobRef}, further content will be ignored`);
      logMessage(`Field '${fieldName}' hit max length (${maxLength}) for job ${jobRef}, further content will be ignored`, logFilePath);
      logToDatabase(
        "warning",
        "applupload8.js",
        `Field '${fieldName}' hit max length for job ${jobRef}: content exceeded ${maxLength} character limit`
      );
      truncatedFields.set(fieldKey, true);
    }
    return current; // Already at max length, don't add more
  }

  const remaining = maxLength - current.length;
  if (newText.length > remaining) {
    // Log the truncation event (only once per field per job)
    if (!truncatedFields.has(fieldKey)) {
      console.warn(`Field '${fieldName}' truncated for job ${jobRef}: attempted to add ${newText.length} chars, only ${remaining} remaining. Final size: ${maxLength} chars.`);
      logMessage(`Field '${fieldName}' truncated for job ${jobRef}: attempted to add ${newText.length} chars, only ${remaining} remaining. Final size: ${maxLength} chars.`, logFilePath);
      logToDatabase(
        "warning",
        "applupload8.js",
        `Field '${fieldName}' truncated for job ${jobRef}: content exceeded ${maxLength} character limit`
      );
      truncatedFields.set(fieldKey, true);
    }
  }

  const textToAdd = newText.length > remaining ? newText.substring(0, remaining) : newText;
  return current + textToAdd;
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
    // Add these settings to help with connection stability
    waitForConnections: true,
    queueLimit: 0,
    // Enable automatic reconnection
    enableKeepAlive: true,
    keepAliveInitialDelay: 10000,
    // Actively check the connection before using it
    acquireTimeout: 30000
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
      console.error("Operation failed after all retry attempts:", error);
      logMessage(
        `Operation failed after all retry attempts: ${error}`,
        logFilePath
      );
      logToDatabase(
        "error",
        "applupload8.js",
        `Operation failed after all retry attempts: ${error}`
      );
      // Instead of throwing the error, we'll return null to indicate failure
      return null;
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
    // Release previous connection if it exists
    if (tempConnection) {
      await tempConnection.release();
      tempConnection = null;
    }

    // Get a fresh connection
    tempConnection = await pool.getConnection();
    await tempConnection.query("CREATE TEMPORARY TABLE IF NOT EXISTS appljobs_temp LIKE appljobs");
    console.log("Temporary table created successfully");
    logMessage("Temporary table created successfully", logFilePath);
    logToDatabase("success", "applupload8.js", "Temporary table created successfully");
  });
};

// Add this new function to check/refresh the connection
const ensureValidConnection = async () => {
  try {
    // Test if the connection is still valid
    await tempConnection.query("SELECT 1");
  } catch (error) {
    console.log("Database connection lost, reconnecting...");
    logMessage("Database connection lost, reconnecting...", logFilePath);

    // Release the broken connection if possible
    try {
      await tempConnection.release();
    } catch (e) {
      // Connection might already be closed, ignore this error
    }

    // Get a fresh connection
    tempConnection = await pool.getConnection();
    await tempConnection.query("CREATE TEMPORARY TABLE IF NOT EXISTS appljobs_temp LIKE appljobs");
  }
};

const truncateTempTable = async () => {
  await ensureValidConnection();
  await retryOperation(async () => {
    await tempConnection.query("TRUNCATE TABLE appljobs_temp");
    console.log("Temp table truncated.");
    logMessage("Temp table truncated.", logFilePath);
    logToDatabase("success", "applupload8.js", "Temp table truncated.");
    tempTableRecordCount = 0;
  });
};

const insertIntoApplJobsFresh = async (batch) => {
  await ensureValidConnection();
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
  await ensureValidConnection();
  const query = "SELECT acctnum FROM appljobseed WHERE jobpoolid = ?";
  const [results] = await retryOperation(async () => {
    return tempConnection.query(query, [jobpoolid]);
  });
  return results.length > 0 ? results[0].acctnum : null;
};

const loadMapping = async (jobpoolid) => {
  await ensureValidConnection();

  const query = "SELECT xml_tag, db_column FROM appldbmapping WHERE jobpoolid = ?";
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
  await ensureValidConnection();
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
  await ensureValidConnection();
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
  // const acctnum = await getAcctNum(jobpoolid);
  const acctnum = parts[0];
  const tagToPropertyMap = await loadMapping(jobpoolid);
  const totalTags = await countTags(filePath);
  let processedTags = 0;
  let totalProcessedJobs = 0;
  let filteredJobsCount = 0;

  // Fetch min_cpc filter for this job pool
  let minCpcFilter = null;
  try {
    await ensureValidConnection();
    const [minCpcResult] = await tempConnection.query(
      "SELECT min_cpc FROM appljobseed WHERE jobpoolid = ?",
      [jobpoolid]
    );
    if (minCpcResult.length > 0 && minCpcResult[0].min_cpc !== null) {
      minCpcFilter = parseFloat(minCpcResult[0].min_cpc);
      console.log(`Job pool ${jobpoolid}: Minimum CPC filter enabled - $${minCpcFilter}`);
      logMessage(`Job pool ${jobpoolid}: Minimum CPC filter enabled - $${minCpcFilter}`, logFilePath);
    } else {
      console.log(`Job pool ${jobpoolid}: No minimum CPC filter set`);
    }
  } catch (err) {
    console.warn(`Could not fetch min_cpc for jobpoolid ${jobpoolid}: ${err}`);
    logMessage(`Could not fetch min_cpc for jobpoolid ${jobpoolid}: ${err}`, logFilePath);
  }

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
        currentJobElement = node.name;
        currentItem = { feedId: feedId };
      }
    });

    parser.on("text", (text) => {
      if (currentItem && currentTag) {
        let trimmedText = text.trim();
        if (trimmedText.length > 0) {
          const jobRef = currentItem.job_reference || currentItem.jobreference || 'processing';
          if (tagToPropertyMap[currentTag]) {
            let propertyName = tagToPropertyMap[currentTag];
            currentItem[propertyName] = safeConcatenate(currentItem[propertyName], trimmedText, MAX_FIELD_LENGTH, propertyName, jobRef);
          } else {
            currentItem[currentTag] = safeConcatenate(currentItem[currentTag], trimmedText, MAX_FIELD_LENGTH, currentTag, jobRef);
          }
        }
      }
    });

    parser.on("cdata", (cdata) => {
      if (currentItem && currentTag) {
        let trimmedCdata = cdata.trim();
        if (trimmedCdata.length > 0) {
          const jobRef = currentItem.job_reference || currentItem.jobreference || 'processing';
          if (tagToPropertyMap[currentTag]) {
            let propertyName = tagToPropertyMap[currentTag];
            currentItem[propertyName] = safeConcatenate(currentItem[propertyName], trimmedCdata, MAX_FIELD_LENGTH, propertyName, jobRef);
          } else {
            currentItem[currentTag] = safeConcatenate(currentItem[currentTag], trimmedCdata, MAX_FIELD_LENGTH, currentTag, jobRef);
          }
        }
      }
    });

    parser.on("closetag", async (nodeName) => {
      if (nodeName === currentJobElement) {
        try {
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

          // Apply minimum CPC filter if configured
          if (minCpcFilter !== null) {
            const jobCpc = parseFloat(currentItem.cpc);
            // If CPC is empty/null/NaN, import the job (skip filter check)
            // Otherwise, check if it meets the minimum threshold
            if (!isNaN(jobCpc) && jobCpc < minCpcFilter) {
              filteredJobsCount++;
              currentItem = {}; // Clear current item and skip this job
              return; // Don't add to jobs array
            }
          }

          jobs.push(currentItem);
          if (jobs.length === CHUNK_SIZE || processedTags === totalTags) {
            try {
              await insertIntoTempTable(jobs);
              totalProcessedJobs += jobs.length;
              jobs = [];
            } catch (err) {
              console.error(`Error inserting batch into temp table: ${err}`);
              logMessage(`Error inserting batch into temp table: ${err}`, logFilePath);
              logToDatabase("error", "applupload8.js", `Error inserting batch into temp table: ${err}`);
              // Clear the problematic batch and continue
              jobs = [];
            }
          }
        } catch (err) {
          console.error(`Error processing job in closetag event: ${err}`);
          logMessage(`Error processing job in closetag event: ${err}`, logFilePath);
          logToDatabase("error", "applupload8.js", `Error processing job in closetag event: ${err}`);
          // Continue processing, skip this problematic job
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
        
        // Log CPC filtering results
        if (minCpcFilter !== null && filteredJobsCount > 0) {
          console.log(`Jobs filtered out due to CPC < $${minCpcFilter}: ${filteredJobsCount}`);
          logMessage(`Jobs filtered out due to CPC < $${minCpcFilter}: ${filteredJobsCount}`, logFilePath);
          logToDatabase(
            "info",
            "applupload8.js",
            `Job pool ${jobpoolid}: Filtered ${filteredJobsCount} jobs below minimum CPC of $${minCpcFilter}`
          );
        }

        // Clear all truncation tracking for this file
        truncatedFields.clear();

        await transferToApplJobsTable();
        resolve();
      } catch (err) {
        console.error(`Error in parser end event: ${err}`);
        logMessage(`Error in parser end event: ${err}`, logFilePath);
        logToDatabase("error", "applupload8.js", `Error in parser end event: ${err}`);

        // Clear truncation tracking even on error
        truncatedFields.clear();

        // Don't reject - this would stop processing other files
        // Instead, resolve with partial success
        console.log(`Partial processing completed. Total jobs processed: ${totalProcessedJobs}`);
        resolve();
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
      // Don't reject here - this would stop all file processing
      // Instead, resolve with an error indication so the main loop can continue
      console.log(`Skipping file ${filePath} due to parsing error, continuing with next file...`);
      logMessage(`Skipping file ${filePath} due to parsing error, continuing with next file...`, logFilePath);
      resolve({ error: true, message: err.message });
    });

    stream.pipe(parser);
  });
};

const processFiles = async () => {
  const directoryPath = `/chroot/home/appljack/appljack.com/html${config.envSuffix}/feedsclean/`;

  try {
    const updateResult = await retryOperation(updateLastUpload);
    if (updateResult === null) {
      console.log("Failed to update last upload, but continuing...");
      logMessage(
        "Failed to update last upload, but continuing...",
        logFilePath
      );
    }

    const createTempTableResult = await retryOperation(createTempTableOnce);
    if (createTempTableResult === null) {
      console.log("Failed to create temporary table, but continuing...");
      logMessage(
        "Failed to create temporary table, but continuing...",
        logFilePath
      );
    }

    // Get all XML files with their sizes and sort by size (smallest first)
    const xmlFiles = fs
      .readdirSync(directoryPath)
      .filter((file) => path.extname(file) === ".xml")
      .map((file) => {
        const fullPath = path.join(directoryPath, file);
        const stats = fs.statSync(fullPath);
        return {
          path: fullPath,
          name: file,
          size: stats.size
        };
      })
      .sort((a, b) => a.size - b.size); // Sort by size, smallest first

    console.log(`Found ${xmlFiles.length} XML files to process, sorted by size:`);
    logMessage(`Found ${xmlFiles.length} XML files to process, sorted by size:`, logFilePath);

    // Log the file order with sizes for visibility
    xmlFiles.forEach((file, index) => {
      const sizeMB = (file.size / (1024 * 1024)).toFixed(2);
      console.log(`  ${index + 1}. ${file.name} (${sizeMB} MB)`);
      logMessage(`  ${index + 1}. ${file.name} (${sizeMB} MB)`, logFilePath);
    });

    const filePaths = xmlFiles.map(file => file.path);

    for (const filePath of filePaths) {
      try {
        recordCount = 0;
        startTime = Date.now();

        const parseResult = await retryOperation(() => parseXmlFile(filePath));
        if (parseResult === null) {
          console.log(
            `Failed to process file ${filePath} after all retries, moving to next file...`
          );
          logMessage(
            `Failed to process file ${filePath} after all retries, moving to next file...`,
            logFilePath
          );
          continue;
        } else if (parseResult && parseResult.error) {
          console.log(
            `File ${filePath} had parsing errors but processing continued. Error: ${parseResult.message}`
          );
          logMessage(
            `File ${filePath} had parsing errors but processing continued. Error: ${parseResult.message}`,
            logFilePath
          );
          // Continue to next file even with parsing errors
        } else {
          console.log(`Successfully processed file ${filePath}`);
          logMessage(`Successfully processed file ${filePath}`, logFilePath);
        }

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
        // Continue to the next file instead of throwing an error
        continue;
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
