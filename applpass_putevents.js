const fs = require("fs");
const readline = require("readline");
const mysql = require("mysql2/promise");
const path = require("path");
const config = require("./config");

// Database configuration
const dbConfig = {
  host: config.host,
  user: config.username,
  password: config.password,
  database: config.database,
};

// File path to the JSON file
const filePath = path.join(__dirname, "applpass_queue.json");

// Function to process events
async function processEvents() {
  let connection;
  try {
    // Connect to the database
    connection = await mysql.createConnection(dbConfig);
    console.log("Connected to the database");

    // Create a read stream and use readline to process the file line by line
    const fileStream = fs.createReadStream(filePath);
    const rl = readline.createInterface({
      input: fileStream,
      crlfDelay: Infinity,
    });

    for await (const line of rl) {
      if (line.trim()) {
        const eventData = JSON.parse(line);

        // Log the event data to check for undefined values
        console.log("Event Data:", eventData);

        const job_reference = eventData.job_reference;
        const jobpoolid = eventData.jobpoolid;
        const feedid = eventData.feedid;
        const eventid = eventData.eventid;
        const timestamp = eventData.timestamp;
        const custid = eventData.custid;
        const refurl = eventData.refurl;
        const ipaddress = eventData.ipaddress;
        const userAgent = eventData.userAgent;
        const publisherid = eventData.publisherid;

        // Log each value to see which are undefined
        console.log("job_reference:", job_reference);
        console.log("jobpoolid:", jobpoolid);
        console.log("feedid:", feedid);
        console.log("eventid:", eventid);
        console.log("timestamp:", timestamp);
        console.log("custid:", custid);
        console.log("refurl:", refurl);
        console.log("ipaddress:", ipaddress);
        console.log("userAgent:", userAgent);
        console.log("publisherid:", publisherid);

        // Fetch cpc, cpa, and status from the database
        const query = `
                    SELECT
                        af.cpc AS feed_cpc, af.cpa, af.status,
                        aj.cpc AS job_cpc
                    FROM
                        applcustfeeds af
                    LEFT JOIN
                        appljobs aj
                    ON
                        aj.job_reference = ? AND aj.jobpoolid = ?
                    WHERE
                        af.feedid = ?
                `;

        const [rows] = await connection.execute(query, [
          job_reference,
          jobpoolid,
          feedid,
        ]);

        if (rows.length > 0) {
          const data = rows[0];
          const cpc = data.feed_cpc || data.job_cpc || "0.00";
          const cpa = data.cpa || "0.00";
          const status = data.status || "";

          // Determine the correct table based on the status
          const tableName =
            status === "capped" || status === "stopped"
              ? "appleventsinac"
              : "applevents_test";

          // Insert the event data into the correct table
          const insertQuery = `
                        INSERT INTO ${tableName} (eventid, timestamp, custid, jobid, refurl, ipaddress, cpc, cpa, feedid, useragent, eventtype, publisherid)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'cpc', ?)
                    `;
          const values = [
            eventid,
            timestamp,
            custid,
            job_reference,
            refurl,
            ipaddress,
            cpc,
            cpa,
            feedid,
            userAgent,
            publisherid
          ];

          await connection.execute(insertQuery, values);
          console.log(
            `Event ${eventid} inserted successfully into ${tableName}`
          );
        } else {
          console.error(
            `No data found for job_reference ${job_reference} and jobpoolid ${jobpoolid}`
          );
        }
      }
    }

    // Optionally, you could truncate the file after processing
    fs.truncateSync(filePath, 0);
    console.log("File processed and truncated successfully");
  } catch (err) {
    console.error("Error processing events:", err);
  } finally {
    if (connection) {
      await connection.end();
    }
  }
}

// Run the event processing function
processEvents();
