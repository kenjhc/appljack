const fs = require("fs");
const mysql = require("mysql");
const path = require("path");
const config = require("./config");
const { envSuffix } = require("./config");


// Create MySQL connection pool
const poolXmlFeeds = mysql.createPool({
  connectionLimit: 10,
  host: config.host,
  user: config.username,
  password: config.password,
  database: config.database,
  charset: config.charset,
});

const outputXmlFolderPath = `/chroot/home/appljack/appljack.com/html${envSuffix}/applfeeds`;
// const outputXmlFolderPath = `applfeeds`;

// Enhanced logging function
function logWithTimestamp(message, type = 'info', data = null) {
  const timestamp = new Date().toISOString();
  const logMessage = `[${timestamp}] [${type}] ${message}`;
  console.log(logMessage);
  if (data) {
    console.log('Additional data:', data);
  }
}

// Function to fetch all custid values from the applcust table
async function fetchAllCustIds() {
  return new Promise((resolve, reject) => {
    const startTime = Date.now();
    poolXmlFeeds.query("SELECT custid FROM applcust", (error, results) => {
      if (error) {
        logWithTimestamp("Error fetching custid values", 'error', error);
        reject(error);
      } else {
        const custids = results.map((row) => row.custid);
        logWithTimestamp(`Fetched ${custids.length} custids in ${Date.now() - startTime}ms`, 'info');
        resolve(custids);
      }
    });
  });
}

async function fetchFeedsWithCriteria(custid) {
  return new Promise((resolve, reject) => {
    const startTime = Date.now();
    let query =
      "SELECT acf.*, ac.jobpoolid, ac.arbcustcpc, ac.arbcustcpa, acf.cpc as feed_cpc, acf.cpa as feed_cpa, acf.arbcampcpc, acf.arbcampcpa " +
      'FROM applcustfeeds acf ' +
      'JOIN applcust ac ON ac.custid = acf.custid WHERE acf.status = "active" AND ac.custid = ?';
    
    logWithTimestamp(`Executing query for custid ${custid}`, 'debug', query);
    
    poolXmlFeeds.query(query, [custid], (error, results) => {
      if (error) {
        logWithTimestamp(`Error fetching feed criteria for custid ${custid}`, 'error', error);
        reject(error);
      } else {
        logWithTimestamp(`Fetched ${results.length} feeds for custid ${custid} in ${Date.now() - startTime}ms`, 'info');
        resolve(results);
      }
    });
  });
}

async function fetchCustomFields(jobpoolid) {
  return new Promise((resolve, reject) => {
    const startTime = Date.now();
    poolXmlFeeds.query(
      "SELECT fieldname, staticvalue, appljobsmap FROM applcustomfields WHERE jobpoolid = ?",
      [jobpoolid],
      (error, results) => {
        if (error) {
          logWithTimestamp(`Error fetching custom fields for jobpoolid ${jobpoolid}`, 'error', error);
          reject(error);
        } else {
          logWithTimestamp(`Fetched ${results.length} custom fields for jobpoolid ${jobpoolid} in ${Date.now() - startTime}ms`, 'info');
          resolve(results);
        }
      }
    );
  });
}

function buildQueryFromCriteria(criteria) {
  const startTime = Date.now();
  let conditions = [];
  let query = `SELECT aj.*, COALESCE(NULLIF('${criteria.feed_cpc}', 'null'), aj.cpc) AS effective_cpc, COALESCE(NULLIF('${criteria.feed_cpa}', 'null'), aj.cpa) AS effective_cpa FROM appljobs aj WHERE aj.jobpoolid = '${criteria.jobpoolid}'`;

  // Handle keywords include/exclude
  if (criteria.custquerykws) {
    const keywords = criteria.custquerykws.split(",");
    const includes = keywords
      .filter((k) => !k.trim().startsWith("NOT "))
      .map((k) => `LOWER(aj.title) LIKE LOWER('%${k.trim()}%')`);
    const excludes = keywords
      .filter((k) => k.trim().startsWith("NOT "))
      .map((k) => `LOWER(aj.title) NOT LIKE LOWER('%${k.trim().substring(4)}%')`);
    
    if (includes.length) conditions.push(`(${includes.join(" OR ")})`);
    if (excludes.length) conditions.push(`(${excludes.join(" AND ")})`);
  }

  // Handle companies include/exclude
  if (criteria.custqueryco) {
    const companies = criteria.custqueryco.split(",");
    const coIncludes = companies
      .filter((c) => !c.trim().startsWith("NOT "))
      .map((c) => `LOWER(aj.company) LIKE LOWER('%${c.trim()}%')`);
    const coExcludes = companies
      .filter((c) => c.trim().startsWith("NOT "))
      .map((c) => `LOWER(aj.company) NOT LIKE LOWER('%${c.trim().substring(4)}%')`);
    
    if (coIncludes.length) conditions.push(`(${coIncludes.join(" OR ")})`);
    if (coExcludes.length) conditions.push(`(${coExcludes.join(" AND ")})`);
  }

  // Additional fields for industry, city, and state
  ["industry", "city", "state"].forEach((field) => {
    if (criteria[`custquery${field}`]) {
      const elements = criteria[`custquery${field}`].split(",");
      const fieldIncludes = elements
        .filter((el) => !el.trim().startsWith("NOT "))
        .map((el) => `LOWER(aj.${field}) LIKE LOWER('%${el.trim()}%')`);
      const fieldExcludes = elements
        .filter((el) => el.trim().startsWith("NOT "))
        .map((el) => `LOWER(aj.${field}) NOT LIKE LOWER('%${el.trim().substring(4)}%')`);
      
      if (fieldIncludes.length) conditions.push(`(${fieldIncludes.join(" OR ")})`);
      if (fieldExcludes.length) conditions.push(`(${fieldExcludes.join(" AND ")})`);
    }
  });

  // Handle custom fields
  for (let i = 1; i <= 5; i++) {
    if (criteria[`custquerycustom${i}`]) {
      const customField = criteria[`custquerycustom${i}`].split(",");
      const customIncludes = customField
        .filter((cf) => !cf.trim().startsWith("NOT "))
        .map((cf) => `aj.custom${i} LIKE '%${cf.trim()}%'`);
      const customExcludes = customField
        .filter((cf) => cf.trim().startsWith("NOT "))
        .map((cf) => `aj.custom${i} NOT LIKE '%${cf.trim().substring(4)}%'`);

      if (customIncludes.length) conditions.push(`(${customIncludes.join(" OR ")})`);
      if (customExcludes.length) conditions.push(`(${customExcludes.join(" AND ")})`);
    }
  }

  if (conditions.length) {
    query += " AND " + conditions.join(" AND ");
  }

  logWithTimestamp(`Query built in ${Date.now() - startTime}ms`, 'debug', query);
  return query;
}

async function streamResultsToXml(fileStream, query, criteria, customFields) {
  return new Promise((resolve, reject) => {
    let jobCount = 0;
    let hasError = false;
    const startTime = Date.now();

    const queryStream = poolXmlFeeds.query(query).stream();

    // Handle stream errors
    queryStream.on("error", (error) => {
      hasError = true;
      logWithTimestamp(`Stream error for custid ${criteria.custid}`, 'error', error);
      reject(error);
    });

    queryStream
      .on("data", (job) => {
        try {
          jobCount++;
          fileStream.write(`  <job>\n`);
          
          // Write standard fields
          Object.keys(job).forEach((key) => {
            if (["id", "feedId", "url", "cpc", "effective_cpc", "cpa", "effective_cpa", 
                 "custom1", "custom2", "custom3", "custom4", "custom5", "jobpoolid", 
                 "acctnum", "custid"].includes(key)) return;
            
            let value = job[key] ? job[key].toString() : "";
            value = value
              .replace(/&/g, "&amp;")
              .replace(/</g, "&lt;")
              .replace(/>/g, "&gt;")
              .replace(/"/g, "&quot;")
              .replace(/'/g, "&apos;");
            fileStream.write(`    <${key}>${value}</${key}>\n`);
          });

          // Write custom fields
          customFields.forEach((customField) => {
            let customValue = customField.staticvalue;
            if (!customValue) {
              if (customField.appljobsmap === "cpc") {
                customValue = job.effective_cpc;
              } else if (customField.appljobsmap === "cpa") {
                customValue = job.effective_cpa;
              } else if (customField.appljobsmap && job[customField.appljobsmap]) {
                customValue = job[customField.appljobsmap];
              }
            }
            if (customValue) {
              customValue = customValue
                .toString()
                .replace(/&/g, "&amp;")
                .replace(/</g, "&lt;")
                .replace(/>/g, "&gt;")
                .replace(/"/g, "&quot;")
                .replace(/'/g, "&apos;");
              fileStream.write(`    <${customField.fieldname}>${customValue}</${customField.fieldname}>\n`);
            }
          });

          // Write URL and pricing
          let customUrl = `https://appljack.com${config.envPath}applpass.php?c=${encodeURIComponent(criteria.custid)}&f=${encodeURIComponent(criteria.feedid)}&j=${encodeURIComponent(job.job_reference)}&jpid=${encodeURIComponent(criteria.jobpoolid)}`;
          customUrl = customUrl.replace(/&/g, "&amp;");
          fileStream.write(`    <url>${customUrl}</url>\n`);

          // Apply arbitrage adjustments
          const adjustedCpc = applyArbitrageAdjustment(job.effective_cpc, criteria.arbcampcpc || criteria.arbcustcpc);
          const adjustedCpa = applyArbitrageAdjustment(job.effective_cpa, criteria.arbcampcpa || criteria.arbcustcpa);

          fileStream.write(`    <cpc>${adjustedCpc}</cpc>\n`);
          fileStream.write(`    <cpa>${adjustedCpa}</cpa>\n`);
          fileStream.write(`  </job>\n`);

        } catch (error) {
          hasError = true;
          logWithTimestamp(`Error processing job for custid ${criteria.custid}`, 'error', error);
          reject(error);
        }
      })
      .on("end", () => {
        if (!hasError) {
          logWithTimestamp(`Processed ${jobCount} jobs for custid ${criteria.custid} in ${Date.now() - startTime}ms`, 'info');
          resolve();
        }
      });
  });
}

function applyArbitrageAdjustment(value, adjustmentPercentage) {
  if (!adjustmentPercentage) return value;
  const adjustment = 1 - (parseFloat(adjustmentPercentage) / 100);
  return (parseFloat(value) * adjustment).toFixed(2);
}

async function processQueriesSequentially() {
  logWithTimestamp("Starting to process queries", 'info');
  const startTime = Date.now();
  const custFileHandles = {};

  // Handle process termination
  process.on('SIGTERM', async () => {
    logWithTimestamp("Process termination signal received", 'warn');
    await cleanup(custFileHandles);
    process.exit(0);
  });

  try {
    const allCustIds = await fetchAllCustIds();

    for (let custid of allCustIds) {
      logWithTimestamp(`Processing feeds for custid ${custid}`, 'info');
      const feedsCriteria = await fetchFeedsWithCriteria(custid);

      if (feedsCriteria.length === 0) {
        logWithTimestamp(`No active feeds found for custid ${custid}`, 'info');
        writeEmptyXmlFile(custid);
        continue;
      }

      try {
        // Initialize file handle for this custid
        const filePath = path.join(outputXmlFolderPath, `${custid}.xml`);
        custFileHandles[custid] = fs.createWriteStream(filePath, { flags: "w" });
        custFileHandles[custid].write('<?xml version="1.0" encoding="UTF-8" standalone="yes"?>\n<jobs>\n');

        for (let criteria of feedsCriteria) {
          try {
            logWithTimestamp(`Processing feed ${criteria.feedid} for custid ${custid}`, 'info');
            
            if (!criteria.jobpoolid) {
              logWithTimestamp(`Missing jobpoolid for feedid ${criteria.feedid}`, 'error');
              continue;
            }

            const customFields = await fetchCustomFields(criteria.jobpoolid);
            const query = buildQueryFromCriteria(criteria);
            
            await streamResultsToXml(custFileHandles[custid], query, criteria, customFields);
          } catch (error) {
            logWithTimestamp(`Error processing feed ${criteria.feedid}`, 'error', error);
          }
        }

        // Properly close the file for this custid
        if (custFileHandles[custid]) {
          custFileHandles[custid].write("</jobs>\n");
          await closeFileHandle(custFileHandles[custid]);
          delete custFileHandles[custid];
        }

      } catch (error) {
        logWithTimestamp(`Error processing custid ${custid}`, 'error', error);
      }
    }

  } catch (error) {
    logWithTimestamp("Fatal error during processing", 'error', error);
  } finally {
    await cleanup(custFileHandles);
    logWithTimestamp(`Total processing time: ${Date.now() - startTime}ms`, 'info');
  }
}

async function cleanup(fileHandles) {
  logWithTimestamp("Starting cleanup process", 'info');
  
  // Close any remaining file handles
  for (const [custid, handle] of Object.entries(fileHandles)) {
    try {
      if (handle && !handle.closed) {
        handle.write("</jobs>\n");
        await closeFileHandle(handle);
      }
    } catch (error) {
      logWithTimestamp(`Error closing file handle for custid ${custid}`, 'error', error);
    }
  }

  // Close database pool
  try {
    await closePool();
  } catch (error) {
    logWithTimestamp("Error closing database pool", 'error', error);
  }
}

function writeEmptyXmlFile(custid) {
  const filePath = path.join(outputXmlFolderPath, `${custid}.xml`);
  const content = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>\n<jobs>\n</jobs>\n';
  fs.writeFileSync(filePath, content);
  logWithTimestamp(`Created empty XML file for custid ${custid}`, 'info');
}

function closeFileHandle(fileHandle) {
  return new Promise((resolve) => {
    if (!fileHandle.closed) {
      fileHandle.end(() => resolve());
    } else {
      resolve();
    }
  });
}

async function closePool() {
  return new Promise((resolve, reject) => {
    poolXmlFeeds.end((err) => {
      if (err) {
        logWithTimestamp("Failed to close the pool", 'error', err);
        reject(err);
      } else {
        logWithTimestamp("Pool closed successfully", 'info');
        resolve();
      }
    });
  });
}

// Start the process with comprehensive error handling
processQueriesSequentially().catch(error => {
  logWithTimestamp("Fatal error in main process", 'error', error);
  process.exit(1);
});