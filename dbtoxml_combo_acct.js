// require("dotenv").config();

const fs = require("fs");
const mysql = require("mysql");
const path = require("path");
const config = require("./config");
const outputXmlFolderPath = "/chroot/home/appljack/appljack.com/html/applfeeds";

const poolXmlFeeds = mysql.createPool({
  connectionLimit: 10,
  host: config.host,
  user: config.username,
  password: config.password,
  database: config.database,
  charset: config.charset,
});

async function fetchAllFeedsWithCriteria() {
  return new Promise((resolve, reject) => {
    poolXmlFeeds.query(
      "SELECT acf.*, ac.jobpoolid, ac.acctnum, acf.cpc as feed_cpc, acf.cpa as feed_cpa, " +
      "ac.arbcustcpc, ac.arbcustcpa, acf.arbcampcpc, acf.arbcampcpa " +
      "FROM applcustfeeds acf " +
      'JOIN applcust ac ON ac.custid = acf.custid WHERE acf.status = "active"',
      (error, results) => {
        if (error) {
          console.error("Error fetching feed criteria:", error);
          reject(error);
        } else {
          console.log(`Fetched ${results.length} feeds across all accounts.`);
          resolve(results);
        }
      }
    );
  });
}

async function fetchCustomFields(jobpoolid) {
  return new Promise((resolve, reject) => {
    poolXmlFeeds.query(
      "SELECT fieldname, staticvalue, appljobsmap FROM applcustomfields WHERE jobpoolid = ?",
      [jobpoolid],
      (error, results) => {
        if (error) {
          console.error(
            `Error fetching custom fields for jobpoolid ${jobpoolid}:`,
            error
          );
          reject(error);
        } else {
          console.log(
            `Fetched ${results.length} custom fields for jobpoolid ${jobpoolid}.`
          );
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

  // Additional fields for industry, city, state, and custom fields
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
  if (conditions.length) {
    query += " AND " + conditions.join(" AND ");
  }

  //  query += " ORDER BY aj.posted_at DESC";
  return query;
}

function applyArbitrageAdjustment(value, adjustmentPercentage) {
  if (!adjustmentPercentage) return value;
  const adjustment = 1 - (parseFloat(adjustmentPercentage) / 100);
  return (parseFloat(value) * adjustment).toFixed(2);
}

async function processQueriesSequentially() {
  console.log("Starting to process criteria into queries");
  const feedsCriteria = await fetchAllFeedsWithCriteria();

  // Group feeds by acctnum
  const groupedByAcctnum = feedsCriteria.reduce((acc, criteria) => {
    const acctnum = criteria.acctnum;

    if (!acc[acctnum]) {
      acc[acctnum] = [];
    }
    acc[acctnum].push(criteria);

    return acc;
  }, {});

  for (const acctnum of Object.keys(groupedByAcctnum)) {
    const criteriaList = groupedByAcctnum[acctnum];
    const acctFilePath = path.join(outputXmlFolderPath, `${acctnum}.xml`);

    if (criteriaList.length === 0) {
      // Empty the file if no active feeds are found
      if (fs.existsSync(acctFilePath)) {
        fs.writeFileSync(
          acctFilePath,
          '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>\n<jobs>\n</jobs>\n'
        );
        console.log(`Emptied the XML file for acctnum ${acctnum}`);
      }
      continue;
    }

    console.log(
      `Processing ${criteriaList.length} feeds for acctnum ${acctnum}.`
    );

    const acctFileHandle = fs.createWriteStream(acctFilePath, { flags: "w" });
    acctFileHandle.write(
      '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>\n<jobs>\n'
    );

    for (let criteria of criteriaList) {
      console.log(
        `Processing feed for custid ${criteria.custid} and jobpoolid ${criteria.jobpoolid}`
      );

      // Fetch custom fields for the jobpoolid
      const customFields = await fetchCustomFields(criteria.jobpoolid);

      const query = buildQueryFromCriteria(criteria);
      console.log("SQL Query:", query);

      await streamResultsToXml(acctFileHandle, query, criteria, customFields);
    }

    acctFileHandle.write("</jobs>\n");
    acctFileHandle.end();
    console.log(`Completed writing XML file for acctnum ${acctnum}.`);
  }

  console.log("All feeds processed. Closing database connection.");
  await closePool();
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

        let customUrl = `https://appljack.com${
          config.envPath
        }applpass.php?c=${encodeURIComponent(
          criteria.custid
        )}&f=${encodeURIComponent(criteria.feedid)}&j=${encodeURIComponent(
          job.job_reference
        )}&jpid=${encodeURIComponent(criteria.jobpoolid)}`;
        customUrl = customUrl.replace(/&/g, "&amp;");
        fileStream.write(`    <url>${customUrl}</url>\n`);

        // Apply arbitrage adjustments
        const adjustedCpc = applyArbitrageAdjustment(job.effective_cpc, criteria.arbcampcpc || criteria.arbcustcpc);
        const adjustedCpa = applyArbitrageAdjustment(job.effective_cpa, criteria.arbcampcpa || criteria.arbcustcpa);

        fileStream.write(`    <cpc>${adjustedCpc}</cpc>\n`);
        fileStream.write(`    <cpa>${adjustedCpa}</cpa>\n`);
        fileStream.write(`  </job>\n`);
      })
      .on("end", () => {
        console.log(
          `Completed streaming results for jobpoolid ${criteria.jobpoolid}.`
        );
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
        process.exit(1);
      } else {
        console.log("Pool closed successfully.");
        resolve();
        process.exit(0);
      }
    });
  });
}

processQueriesSequentially().catch(console.error);
