const fs = require("fs");
const mysql = require("mysql");
const path = require("path");

require("dotenv").config();

const poolXmlFeeds = mysql.createPool({
  connectionLimit: 10,
  host: process.env.DB_HOST,
  user: process.env.DB_USERNAME,
  password: process.env.DB_PASSWORD,
  database: process.env.DB_DATABASE,
  charset: process.env.DB_CHARSET,
});

const outputXmlFolderPath = "applfeeds";

// Retrieve custid from command line arguments
const custidArg = process.argv[2];

async function fetchFeedsWithCriteria(custid) {
  return new Promise((resolve, reject) => {
    let query =
      "SELECT acf.*, ac.jobpoolid, acf.cpc as feed_cpc, acf.cpa as feed_cpa FROM applcustfeeds acf " +
      'JOIN applcust ac ON ac.custid = acf.custid WHERE acf.status = "active"';
    if (custid) {
      query += " AND ac.custid = ?";
    }
    poolXmlFeeds.query(query, [custid], (error, results) => {
      if (error) {
        console.error("Error fetching feed criteria:", error);
        reject(error);
      } else {
        resolve(results);
      }
    });
  });
}

async function fetchCustomFields(jobpoolid) {
  return new Promise((resolve, reject) => {
    poolXmlFeeds.query(
      "SELECT fieldname, staticvalue, appljobsmap FROM applcustomfields WHERE jobpoolid = ?",
      [jobpoolid],
      (error, results) => {
        if (error) {
          console.error("Error fetching custom fields:", error);
          reject(error);
        } else {
          resolve(results);
        }
      }
    );
  });
}

function buildQueryFromCriteria(criteria) {
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
      .map(
        (k) => `LOWER(aj.title) NOT LIKE LOWER('%${k.trim().substring(4)}%')`
      );
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
      .map(
        (c) => `LOWER(aj.company) NOT LIKE LOWER('%${c.trim().substring(4)}%')`
      );
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
        .map(
          (el) =>
            `LOWER(aj.${field}) NOT LIKE LOWER('%${el.trim().substring(4)}%')`
        );
      if (fieldIncludes.length)
        conditions.push(`(${fieldIncludes.join(" OR ")})`);
      if (fieldExcludes.length)
        conditions.push(`(${fieldExcludes.join(" AND ")})`);
    }
  });

  // Handle custom fields
  for (let i = 1; i <= 5; i++) {
    if (criteria[`custquerycustom${i}`]) {
      const customField = criteria[`custquerycustom${i}`].split(",");
      const customIncludes = customField
        .filter((cf) => !cf.trim().startsWith("NOT "))
        .map((cf) => `aj.custom_field_${i} LIKE '%${cf.trim()}%'`);
      const customExcludes = customField
        .filter((cf) => cf.trim().startsWith("NOT "))
        .map(
          (cf) => `aj.custom_field_${i} NOT LIKE '%${cf.trim().substring(4)}%'`
        );
      if (customIncludes.length)
        conditions.push(`(${customIncludes.join(" OR ")})`);
      if (customExcludes.length)
        conditions.push(`(${customExcludes.join(" AND ")})`);
    }
  }

  // Apply all conditions to the query
  if (conditions.length) {
    query += " AND " + conditions.join(" AND ");
  }

  query += " ORDER BY aj.posted_at DESC";
  return query;
}

async function processQueriesSequentially() {
  console.log("Starting to process criteria into queries");

  const feedsCriteria = await fetchFeedsWithCriteria(custidArg);
  const custFileHandles = {};

  console.log(`Length of feeds: ${feedsCriteria.length}`);
  console.log(`custidArg: ${custidArg}`);

  try {
    if (feedsCriteria.length === 0) {
      if (custidArg) {
        // If no feeds are found for a specific custid, empty the XML file for that custid
        const filePath = path.join(outputXmlFolderPath, `${custidArg}.xml`);
        console.log(`Checking file path: ${filePath}`);
        if (fs.existsSync(filePath)) {
          console.log(`File exists: ${filePath}`);
          fs.writeFileSync(
            filePath,
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>\n<jobs>\n</jobs>\n'
          );
          console.log(`Emptied the XML file for custid ${custidArg}`);
        } else {
          console.log(`File does not exist: ${filePath}`);
        }
      } else {
        // If no custid is provided and no feeds are found, empty all related XML files
        const allCustIds = await fetchAllCustIdsWithExistingFiles();
        console.log(`All custids to process: ${allCustIds}`);
        for (let custid of allCustIds) {
          const filePath = path.join(outputXmlFolderPath, `${custid}.xml`);
          console.log(`Checking file path: ${filePath}`);
          if (fs.existsSync(filePath)) {
            console.log(`File exists: ${filePath}`);
            fs.writeFileSync(
              filePath,
              '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>\n<jobs>\n</jobs>\n'
            );
            console.log(`Emptied the XML file for custid ${custid}`);
          } else {
            console.log(`File does not exist: ${filePath}`);
          }
        }
      }
      return; // Exit the function as there are no feeds to process
    }

    for (let criteria of feedsCriteria) {
      if (!criteria.jobpoolid) {
        console.error(
          "Jobpoolid is not defined in criteria for feedid:",
          criteria.feedid
        );
        continue;
      }

      // Fetch custom fields for the jobpoolid
      const customFields = await fetchCustomFields(criteria.jobpoolid);

      const query = buildQueryFromCriteria(criteria);
      console.log("SQL Query:", query);

      // Manage file stream creation per custid
      if (!custFileHandles[criteria.custid]) {
        const filePath = path.join(
          outputXmlFolderPath,
          `${criteria.custid}.xml`
        );
        custFileHandles[criteria.custid] = fs.createWriteStream(filePath, {
          flags: "w",
        });
        custFileHandles[criteria.custid].write(
          '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>\n<jobs>\n'
        );
      }

      // Note that we now pass the entire 'criteria' object to ensure access to necessary identifiers
      await streamResultsToXml(
        custFileHandles[criteria.custid],
        query,
        criteria,
        customFields
      );
    }
  } catch (error) {
    console.error("Error during processing queries:", error);
  } finally {
    // Ensure closing of all file handles
    Object.keys(custFileHandles).forEach((custid) => {
      if (custFileHandles[custid]) {
        custFileHandles[custid].write("</jobs>\n");
        custFileHandles[custid].end();
      }
    });

    console.log("All feeds processed. Closing database connection.");
    await closePool();
  }
}

async function fetchAllCustIdsWithExistingFiles() {
  return new Promise((resolve, reject) => {
    fs.readdir(outputXmlFolderPath, (err, files) => {
      if (err) {
        console.error("Error reading directory:", err);
        reject(err);
      } else {
        const custIds = files
          .filter((file) => file.endsWith(".xml"))
          .map((file) => path.basename(file, ".xml"));
        resolve(custIds);
      }
    });
  });
}

async function streamResultsToXml(fileStream, query, criteria, customFields) {
  return new Promise((resolve, reject) => {
    const queryStream = poolXmlFeeds.query(query).stream();
    queryStream
      .on("error", (error) => {
        console.error("Stream encountered an error:", error);
        reject(error);
      })
      .on("data", (job) => {
        fileStream.write(`  <job>\n`);
        Object.keys(job).forEach((key) => {
          if (
            [
              "id",
              "feedId",
              "url",
              "cpc",
              "effective_cpc",
              "cpa",
              "effective_cpa",
              "custom1",
              "custom2",
              "custom3",
              "custom4",
              "custom5",
              "jobpoolid",
              "acctnum",
              "custid",
            ].includes(key)
          )
            return; // Skip specific keys
          let value = job[key] ? job[key].toString() : "";
          value = value
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&apos;");
          fileStream.write(`    <${key}>${value}</${key}>\n`);
        });

        // Add custom fields to the job element
        customFields.forEach((customField) => {
          let customValue = customField.staticvalue;
          if (!customValue) {
            if (customField.appljobsmap === "cpc") {
              customValue = job.effective_cpc;
            } else if (customField.appljobsmap === "cpa") {
              customValue = job.effective_cpa;
            } else if (
              customField.appljobsmap &&
              job[customField.appljobsmap]
            ) {
              customValue = job[customField.appljobsmap];
            }
          }
          if (customValue) {
            customValue = customValue
              .toString()
              .replace(/&/g, "&amp;")
              .replace(/</ / g, "&lt;")
              .replace(/>/g, "&gt;")
              .replace(/"/g, "&quot;")
              .replace(/'/g, "&apos;");
            fileStream.write(
              `    <${customField.fieldname}>${customValue}</${customField.fieldname}>\n`
            );
          }
        });

        let customUrl = `https://appljack.com/applpass.php?c=${encodeURIComponent(
          criteria.custid
        )}&f=${encodeURIComponent(criteria.feedid)}&j=${encodeURIComponent(
          job.job_reference
        )}&jpid=${encodeURIComponent(criteria.jobpoolid)}`;
        customUrl = customUrl.replace(/&/g, "&amp;");
        fileStream.write(`    <url>${customUrl}</url>\n`);
        fileStream.write(`    <cpc>${job.effective_cpc}</cpc>\n`);
        fileStream.write(`    <cpa>${job.effective_cpa}</cpa>\n`);
        fileStream.write(`  </job>\n`);
      })
      .on("end", () => {
        resolve();
      });
  });
}

async function closePool() {
  return new Promise((resolve, reject) => {
    poolXmlFeeds.end((err) => {
      if (err) {
        console.error("Failed to close the pool:", err);
        reject(err);
      } else {
        console.log("Pool closed successfully.");
        resolve();
      }
    });
  });
}

processQueriesSequentially().catch(console.error);
