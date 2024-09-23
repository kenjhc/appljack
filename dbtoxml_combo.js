const fs = require("fs");
const mysql = require("mysql");
const path = require("path");
const config = require("./config");

const poolXmlFeeds = mysql.createPool({
  connectionLimit: 10,
  host: config.host,
  user: config.username,
  password: config.password,
  database: config.database,
  charset: config.charset,
});

const outputXmlFolderPath = "/chroot/home/appljack/appljack.com/html/applfeeds";

// Function to fetch all custid values from the applcust table
async function fetchAllCustIds() {
  return new Promise((resolve, reject) => {
    poolXmlFeeds.query("SELECT custid FROM applcust", (error, results) => {
      if (error) {
        console.error("Error fetching custid values:", error);
        reject(error);
      } else {
        const custids = results.map((row) => row.custid);
        resolve(custids);
      }
    });
  });
}

async function fetchFeedsWithCriteria(custid) {
  return new Promise((resolve, reject) => {
    let query =
      "SELECT acf.*, ac.jobpoolid, acf.cpc as feed_cpc, acf.cpa as feed_cpa FROM applcustfeeds acf " +
      'JOIN applcust ac ON ac.custid = acf.custid WHERE acf.status = "active" AND ac.custid = ?';
    poolXmlFeeds.query(query, [custid], (error, results) => {
      if (error) {
        console.error("Error fetching feed criteria:", error);
        reject(error);
      } else {
        console.log(`Fetched ${results.length} feeds for custid ${custid}`);
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
        .map((cf) => `aj.custom${i} LIKE '%${cf.trim()}%'`);
      const customExcludes = customField
        .filter((cf) => cf.trim().startsWith("NOT "))
        .map((cf) => `aj.custom${i} NOT LIKE '%${cf.trim().substring(4)}%'`);

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

  // query += " ORDER BY aj.posted_at DESC";
  return query;
}

async function processQueriesSequentially() {
  console.log("Starting to process criteria into queries");

  try {
    const allCustIds = await fetchAllCustIds();

    for (let custid of allCustIds) {
      console.log(`Processing feeds for custid ${custid}`);
      const feedsCriteria = await fetchFeedsWithCriteria(custid);

      if (feedsCriteria.length === 0) {
        console.log(`No active feeds found for custid ${custid}`);
        const filePath = path.join(outputXmlFolderPath, `${custid}.xml`);
        if (fs.existsSync(filePath)) {
          fs.writeFileSync(
            filePath,
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>\n<jobs>\n</jobs>\n'
          );
          console.log(`Emptied the XML file for custid ${custid}`);
        }
        continue;
      }

      const custFileHandles = {};
      for (let criteria of feedsCriteria) {
        console.log(
          `Processing feed for custid ${criteria.custid} and jobpoolid ${criteria.jobpoolid}`
        );
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

      // Ensure closing of all file handles for the current custid
      Object.keys(custFileHandles).forEach((custid) => {
        if (custFileHandles[custid]) {
          custFileHandles[custid].write("</jobs>\n");
          custFileHandles[custid].end();
        }
      });
    }
  } catch (error) {
    console.error("Error during processing queries:", error);
  } finally {
    console.log("All feeds processed. Closing database connection.");
    await closePool();
  }
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
              .replace(/</g, "&lt;")
              .replace(/>/g, "&gt;")
              .replace(/"/g, "&quot;")
              .replace(/'/g, "&apos;");
            fileStream.write(
              `    <${customField.fieldname}>${customValue}</${customField.fieldname}>\n`
            );
          }
        });

        let customUrl = `https://appljack.com${config.envPath}applpass.php?c=${encodeURIComponent(
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
