const fs = require("fs");
const mysql = require("mysql");
const path = require("path");
const config = require("./config");
const { envSuffix } = require("./config");

const outputXmlFolderPath = `/chroot/home/appljack/appljack.com/html${envSuffix}/applfeeds`;
// const outputXmlFolderPath = "applfeeds";

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
      `SELECT 
         acf.*, 
         ac.jobpoolid, 
         ac.arbcustcpc, 
         ac.arbcustcpa, 
         acf.cpc AS feed_cpc, 
         acf.cpa AS feed_cpa, 
         acf.arbcampcpc, 
         acf.arbcampcpa 
       FROM 
         applcustfeeds acf FORCE INDEX (idx_status) 
       JOIN 
         applcust ac ON ac.custid = acf.custid;`,
      (error, results) => {
        if (error) {
          console.error("Error fetching feed criteria:", error);
          reject(error);
        } else {
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
          console.error("Error fetching custom fields:", error);
          reject(error);
        } else {
          resolve(results);
        }
      }
    );
  });
}

async function processQueriesSequentially() {
  console.log("Starting to process criteria into queries");

  const feedsCriteria = await fetchAllFeedsWithCriteria();


  for (let criteria of feedsCriteria) {
    console.log(
      `Processing feed: ${criteria.feedid} for customer: ${criteria.custid}`
    );

    if (!criteria.jobpoolid) {
      console.error("jobpoolid is not defined in criteria.");
      continue;
    }

    const customFields = await fetchCustomFields(criteria.jobpoolid);
    const query = buildQueryFromCriteria(criteria);

    console.log("SQL Query:", query);

    try {
      const results = await streamResultsToXml(
        criteria.custid,
        criteria.feedid,
        criteria.jobpoolid,
        query,
        customFields,
        criteria
      );
      if (results && results.length > 0) {
        console.log(
          `Jobs found for feedid: ${criteria.feedid} and custid: ${criteria.custid}`
        );
      } else {
        console.log(
          `No jobs found for feedid: ${criteria.feedid} and custid: ${criteria.custid}`
        );
      }
    } catch (error) {
      console.error("Error during query execution or XML file writing:", error);
    }
  }


  await emptyXmlForStoppedFeeds(feedsCriteria);
  console.log("All feeds processed. Closing database connection.");
  await closePool();
}

async function streamResultsToXml(
  custid,
  feedid,
  jobpoolid,
  query,
  customFields,
  criteria
) {
  return new Promise((resolve, reject) => {
    const filePath = path.join(outputXmlFolderPath, `${custid}-${feedid}.xml`);
    const fileStream = fs.createWriteStream(filePath, { flags: "w" });

    fileStream.write(
      '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>\n<jobs>\n'
    );

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
            return;
          let value = job[key] ? job[key].toString() : "";
          value = value
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&apos;");
          fileStream.write(`    <${key}>${value}</${key}>\n`);
        });

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
        }applpass.php?c=${encodeURIComponent(custid)}&f=${encodeURIComponent(
          feedid
        )}&j=${encodeURIComponent(job.job_reference)}&jpid=${encodeURIComponent(
          jobpoolid
        )}`;
        customUrl = customUrl.replace(/&/g, "&amp;");
        fileStream.write(`    <url>${customUrl}</url>\n`);

        // Apply arbitrage adjustments
        const adjustedCpc = applyArbitrageAdjustment(job.cpc, criteria.arbcampcpc || criteria.arbcustcpc);
        const adjustedCpa = applyArbitrageAdjustment(job.cpa, criteria.arbcampcpa || criteria.arbcustcpa);

        fileStream.write(`    <cpc>${adjustedCpc}</cpc>\n`);
        fileStream.write(`    <cpa>${adjustedCpa}</cpa>\n`);
        fileStream.write(`  </job>\n`);

        
      })
      .on("end", () => {
        fileStream.write("</jobs>\n");
        fileStream.end();
        console.log(
          `${custid}-${feedid}.xml has been saved in ${outputXmlFolderPath}`
        );
        resolve();
      });
      
  });


}

async function emptyXmlForStoppedFeeds(feeds) {
  for (const feed of feeds) {


   
    if (feed.status == "stopped" || feed.status == "date stopped") {
      const filePath = path.join(outputXmlFolderPath, `${feed.custid}-${feed.feedid}.xml`);

   
      try {
        fs.writeFileSync(filePath, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>\n<jobs></jobs>\n');
        console.log(`Emptied XML file for Feed ID ${feed.feedid} with status "${feed.status}".`);
       
      } catch (error) {
        console.error(`Failed to empty XML file for Feed ID ${feed.feedid}:`, error.message);
     
      }
    }
  }
}


function applyArbitrageAdjustment(value, adjustmentPercentage) {
  if (!adjustmentPercentage) return value;
  const adjustment = 1 - (parseFloat(adjustmentPercentage) / 100);
  return (parseFloat(value) * adjustment).toFixed(2);
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

function buildQueryFromCriteria(criteria) {
  let conditions = [];
  let query = `SELECT aj.*, COALESCE(NULLIF('${criteria.feed_cpc}', 'null'), aj.cpc) AS effective_cpc, COALESCE(NULLIF('${criteria.feed_cpa}', 'null'), aj.cpa) AS effective_cpa FROM appljobs aj WHERE aj.jobpoolid = '${criteria.jobpoolid}'`;

  // Handling keywords
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

  // Handling companies
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

  // Additional fields handling
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

  query += " ORDER BY aj.posted_at DESC";
  return query;
}

processQueriesSequentially().catch(console.error);