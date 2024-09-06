require("dotenv").config();

const fs = require("fs");
const mysql = require("mysql");
const path = require("path");

const outputXmlFolderPath = "/chroot/home/appljack/appljack.com/html/applfeeds";

const poolXmlFeeds = mysql.createPool({
  connectionLimit: 10,
  host: process.env.DB_HOST,
  user: process.env.DB_USERNAME,
  password: process.env.DB_PASSWORD,
  database: process.env.DB_DATABASE,
  charset: process.env.DB_CHARSET,
});

async function fetchAllFeedsWithCriteria() {
  return new Promise((resolve, reject) => {
    poolXmlFeeds.query(
      'SELECT acf.*, ac.jobpoolid FROM applcustfeeds acf JOIN applcust ac ON acf.custid = ac.custid WHERE acf.status = "active"',
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

function buildQueryFromCriteria(criteria) {
  let conditions = [];
  let query =
    "SELECT aj.*, acf.cpc, acf.cpa, acf.feedid FROM appljobs aj JOIN applcust ac ON aj.jobpoolid = ac.jobpoolid JOIN applcustfeeds acf ON ac.custid = acf.custid";

  if (criteria.jobpoolid) {
    conditions.push(`aj.jobpoolid = '${criteria.jobpoolid}'`);
  } else {
    console.error("jobpoolid is undefined for custid:", criteria.custid);
    return null; // Avoid executing an invalid query
  }

  // Handling multiple keywords inclusion and exclusion
  if (criteria.custquerykws) {
    let keywordCriteria = criteria.custquerykws.split(",").map((k) => k.trim());
    let kwsInclude = [];
    let kwsExclude = [];

    keywordCriteria.forEach((k) => {
      if (k.startsWith("NOT ")) {
        kwsExclude.push(
          `LOWER(aj.title) NOT LIKE LOWER('%${k.substring(4)}%')`
        );
      } else {
        kwsInclude.push(`LOWER(aj.title) LIKE LOWER('%${k}%')`);
      }
    });

    if (kwsInclude.length) conditions.push(`(${kwsInclude.join(" OR ")})`);
    if (kwsExclude.length) conditions.push(`${kwsExclude.join(" AND ")}`);
  }

  // Handling multiple industry values
  if (criteria.custqueryindustry) {
    let industryCriteria = criteria.custqueryindustry
      .split(",")
      .map((ind) => ind.trim());
    let industryConditions = industryCriteria.map(
      (ind) => `LOWER(aj.industry) LIKE LOWER('%${ind}%')`
    );
    conditions.push(`(${industryConditions.join(" OR ")})`);
  }

  // Handling multiple city values
  if (criteria.custquerycity) {
    let cityCriteria = criteria.custquerycity
      .split(",")
      .map((city) => city.trim());
    let cityConditions = cityCriteria.map(
      (city) => `LOWER(aj.city) LIKE LOWER('%${city}%')`
    );
    conditions.push(`(${cityConditions.join(" OR ")})`);
  }

  if (criteria.custquerystate) {
    conditions.push(
      `LOWER(aj.state) = LOWER('${criteria.custquerystate.trim()}')`
    );
  }

  // Handling multiple company values
  if (criteria.custqueryco) {
    let companyCriteria = criteria.custqueryco.split(",").map((c) => c.trim());
    let coInclude = [];
    let coExclude = [];

    companyCriteria.forEach((c) => {
      if (c.startsWith("NOT ")) {
        coExclude.push(
          `LOWER(aj.company) NOT LIKE LOWER('%${c.substring(4)}%')`
        );
      } else {
        coInclude.push(`LOWER(aj.company) LIKE LOWER('%${c}%')`);
      }
    });

    if (coInclude.length) conditions.push(`(${coInclude.join(" OR ")})`);
    if (coExclude.length) conditions.push(`${coExclude.join(" AND ")}`);
  }

  if (criteria.feedid) {
    conditions.push(`acf.feedid = '${criteria.feedid}'`);
  }

  if (conditions.length) {
    query += " WHERE " + conditions.join(" AND ");
  }

  query += " ORDER BY aj.posted_at DESC";

  return query;
}

async function updateNumJobs(feedid, numJobs) {
  return new Promise((resolve, reject) => {
    const updateQuery = "UPDATE applcustfeeds SET numjobs = ? WHERE feedid = ?";
    poolXmlFeeds.query(updateQuery, [numJobs, feedid], (error, results) => {
      if (error) {
        console.error(`Error updating numjobs for feedid: ${feedid}`, error);
        reject(error);
      } else {
        console.log(
          `Updated numjobs for feedid: ${feedid} with count: ${numJobs}`
        );
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

    // Check the status of the feed, if it's 'capped' or 'stopped', update numjobs to 0 in the database
    if (criteria.status === "capped" || criteria.status === "stopped") {
      await updateNumJobs(criteria.feedid, 0);
      console.log(
        `Feed ${criteria.feedid} status is '${criteria.status}', setting numjobs to 0.`
      );
      continue; // Skip further processing for this feed
    }
    console.log("Input criteria: ", criteria);

    // Construct SQL query based on feed criteria
    const query = buildQueryFromCriteria(criteria);

    // Log the SQL query to the console
    console.log("SQL Query:", query);

    if (!query) {
      console.error("Invalid query for criteria:", criteria);
      continue;
    }

    try {
      // Fetch jobs based on the constructed query
      const results = await queryDatabase(query);
      console.log(
        `Found ${results.length} jobs for feedid: ${criteria.feedid} and custid: ${criteria.custid}`
      );
      // console.log("Query results: ", results);

      // Update the numjobs column in the applcustfeeds table
      await updateNumJobs(criteria.feedid, results.length);
    } catch (error) {
      console.error("Error during query execution or updating numjobs:", error);
    }
  }

  console.log("All feeds processed. Closing database connection.");
  await closePool();
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

processQueriesSequentially().catch(console.error);
