const fs = require("fs");
const mysql = require("mysql");
const path = require("path");
const config = require("./config");
const { envSuffix } = require("./config");

const outputXmlFolderPath = `/chroot/home/appljack/appljack.com/html${envSuffix}/applfeeds`;

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
      'SELECT acf.*, ac.jobpoolid, acf.cpc as feed_cpc FROM applcustfeeds acf FORCE INDEX (idx_status) JOIN applcust ac ON ac.custid = acf.custid WHERE acf.status = "active";',
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

async function fetchCustIdFromApplCustFeeds(feedid) {
  return new Promise((resolve, reject) => {
    poolXmlFeeds.query(
      "SELECT custid FROM applcustfeeds WHERE feedid = ?",
      [feedid],
      (error, results) => {
        if (error) {
          reject(error);
        } else {
          resolve(results[0].custid);
        }
      }
    );
  });
}

async function fetchJobPoolIdFromApplCust(custid) {
  return new Promise((resolve, reject) => {
    poolXmlFeeds.query(
      "SELECT jobpoolid FROM applcust WHERE custid = ?",
      [custid],
      (error, results) => {
        if (error) {
          reject(error);
        } else {
          resolve(results[0].jobpoolid);
        }
      }
    );
  });
}

function queryDatabase(query) {
  return new Promise((resolve, reject) => {
    poolXmlFeeds.query(query, (error, results) => {
      if (error) {
        reject(error);
      } else {
        resolve(results);
      }
    });
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
      continue; // Skip processing this criteria and move to the next one
    }

    // Construct SQL query based on feed criteria
    const query = buildQueryFromCriteria(criteria);

    // Log the SQL query to the console
    console.log("SQL Query:", query);

    try {
      // Fetch jobs based on the constructed query
      const results = await queryDatabase(query);
      if (results && results.length > 0) {
        console.log(
          `Jobs found for feedid: ${criteria.feedid} and custid: ${criteria.custid}`
        );
      } else {
        console.log(
          `No jobs found for feedid: ${criteria.feedid} and custid: ${criteria.custid}`
        );
      }
      // Generate XML file named [custid]-[feedid].xml, including jobs based on criteria
      // This is moved outside of the 'if' condition so it executes regardless of results length
      await generateXmlFile(criteria.custid, criteria.feedid, results);
    } catch (error) {
      console.error("Error during query execution or XML file writing:", error);
    }
  }

  console.log("All feeds processed. Closing database connection.");
  await closePool();
}

function buildQueryFromCriteria(criteria) {
  let conditions = [];
  let query =
    "SELECT aj.*, COALESCE(aj.cpc, '" +
    criteria.feed_cpc +
    "') AS effective_cpc FROM appljobs aj JOIN applcust ac ON aj.jobpoolid = ac.jobpoolid";

  if (!criteria.custid) {
    console.error("custid is not defined in criteria.");
    return null; // Exit if custid is not defined in criteria
  }

  // Filter jobs based on custid
  conditions.push(`ac.custid = '${criteria.custid}'`);

  // Handling additional criteria
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

  if (criteria.custqueryindustry) {
    conditions.push(
      `LOWER(aj.industry) LIKE LOWER('%${criteria.custqueryindustry.trim()}%')`
    );
  }

  if (criteria.custquerycity) {
    conditions.push(
      `LOWER(aj.city) LIKE LOWER('%${criteria.custquerycity.trim()}%')`
    );
  }

  if (criteria.custquerystate) {
    conditions.push(
      `LOWER(aj.state) = LOWER('${criteria.custquerystate.trim()}')`
    );
  }

  if (conditions.length) {
    query += " WHERE " + conditions.join(" AND ");
  }

  query += " ORDER BY aj.posted_at DESC";

  return query;
}

function queryDatabase(query) {
  return new Promise((resolve, reject) => {
    poolXmlFeeds.query(query, (error, results) => {
      if (error) {
        reject(error);
      } else {
        resolve(results);
      }
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

async function generateXmlFile(custid, feedid, jobsData) {
  const filePath = path.join(outputXmlFolderPath, `${custid}-${feedid}.xml`);
  let stream;

  try {
    // Ensure the directory exists
    fs.mkdirSync(outputXmlFolderPath, { recursive: true });

    stream = fs.createWriteStream(filePath, { flags: "w" });
    stream.write(
      '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>\n<jobs>\n'
    );

    jobsData.forEach((job) => {
      stream.write(`  <job>\n`);
      Object.keys(job).forEach((key) => {
        if (key === "url" || key === "cpc" || key === "effective_cpc") return; // Skip the URL & CPC keys, will add it manually later

        let value = job[key] ? job[key].toString() : "";
        value = value
          .replace(/&/g, "&amp;")
          .replace(/</g, "&lt;")
          .replace(/>/g, "&gt;")
          .replace(/"/g, "&quot;")
          .replace(/'/g, "&apos;");
        stream.write(`    <${key}>${value}</${key}>\n`);
      });

      if (job.hasOwnProperty("job_reference")) {
        let customUrl = `https://appljack.com/applpass.php?c=${encodeURIComponent(
          custid
        )}&f=${encodeURIComponent(feedid)}&j=${encodeURIComponent(
          job.job_reference
        )}`;
        customUrl = customUrl.replace(/&/g, "&amp;");
        stream.write(`    <url>${customUrl}</url>\n`);
      }
      stream.write(`    <cpc>${job.effective_cpc}</cpc>\n`);

      stream.write(`  </job>\n`);
    });

    stream.write("</jobs>\n");
  } catch (error) {
    console.error("Error creating or writing to file:", error);
    return; // Exit the function if an error occurs
  }

  return new Promise((resolve, reject) => {
    if (!stream) {
      reject("Stream is not defined.");
      return;
    }

    stream.end();

    stream.on("finish", () => {
      console.log(
        `${custid}-${feedid}.xml has been saved in ${outputXmlFolderPath}`
      );
      resolve();
    });

    stream.on("error", (error) => {
      console.error("Stream encountered an error:", error);
      reject(error);
    });
  });
}

processQueriesSequentially().catch(console.error);
