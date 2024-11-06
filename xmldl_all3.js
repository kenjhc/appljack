const axios = require("axios");
const fs = require("fs");
const zlib = require("zlib");
const csvParse = require("csv-parse");
const js2xmlparser = require("js2xmlparser");
const mysql = require("mysql");
const stream = require("stream");
const XmlStream = require("xml-stream");
const config = require("./config");

// Set up a MySQL connection
const db = mysql.createConnection({
  host: config.host,
  user: config.username,
  password: config.password,
  database: config.database,
  charset: config.charset,
});

db.connect((err) => {
  if (err) throw err;
  console.log("Connected to database.");
  startProcess();
});

async function startProcess() {
  db.query(
    "SELECT jobpoolurl as file_url, jobpoolfiletype as file_type, jobpoolid, acctnum FROM appljobseed",
    async (err, results) => {
      if (err) {
        console.error("Error fetching data from database:", err.message);
        db.end();
        return;
      }

      if (!results.length) {
        console.log("No data found to process.");
        db.end();
        return;
      }

      for (const { file_url, file_type, jobpoolid, acctnum } of results) {
        console.log(`Starting processing for file URL: ${file_url}`);
        try {
          const outputFileName = `${acctnum}-${jobpoolid}.xml`;
          const outputPath = `/chroot/home/appljack/appljack.com/html/feeddownloads/${outputFileName}`;
          if (file_type.toLowerCase() === "xml") {
            await downloadAndProcessXml(
              file_url.trim(),
              jobpoolid,
              acctnum,
              outputPath,
              file_url
            );
          } else if (file_type.toLowerCase() === "csv") {
            await downloadCsvAndConvertToXml(
              file_url.trim(),
              jobpoolid,
              acctnum,
              outputPath
            );
          }
          console.log(`Finished processing for file URL: ${file_url}`);
        } catch (error) {
          console.error(
            `Error processing ${file_type.toUpperCase()} file at URL ${file_url}:`,
            error.message
          );
        }
      }

      db.end(() => console.log("Database connection closed."));
    }
  );
}

async function downloadAndProcessXml(
  url,
  jobpoolid,
  acctnum,
  outputPath,
  fileUrl
) {
  console.log(`Attempting to download and process XML from ${url}...`);
  try {
    const response = await axios.get(url, {
      responseType: "stream",
      timeout: 30000,
      headers: {
        "User-Agent": "Mozilla/5.0",
        "Accept-Encoding": "gzip, deflate, br",
      },
    });

    const validContentTypes = [
      "application/xml",
      "text/xml",
      "application/x-gzip",
      "application/gzip",
      "binary/octet-stream"
    ];
    const isXmlContent = validContentTypes.some((type) =>
      response.headers["content-type"].includes(type)
    );

    if (response.status === 200 && isXmlContent) {
      let xmlStream;
      if (
        response.headers["content-encoding"] === "gzip" ||
        response.headers["content-type"].includes("application/x-gzip") ||
        response.headers["content-type"].includes("application/gzip")
      ) {
        xmlStream = response.data.pipe(zlib.createGunzip());
      } else {
        xmlStream = response.data;
      }

      processXmlStream(xmlStream, jobpoolid, acctnum, outputPath, fileUrl);
    } else {
      console.error(
        `Failed to download XML: Status Code ${response.status}, Content-Type ${response.headers["content-type"]}`
      );
    }
  } catch (error) {
    console.error(`Error during download and processing for ${url}:`, error);
  }
}

function processXmlStream(xmlStream, jobpoolid, acctnum, outputPath, fileUrl) {
  const xml = new XmlStream(xmlStream);
  const jobs = [];

  xml.on("endElement: job", (job) => {
    const flattenedJob = flattenJob(job);
    flattenedJob.acctnum = acctnum;
    flattenedJob.jobpoolid = jobpoolid;
    jobs.push(flattenedJob);
  });

  xml.on("end", () => {
    if (jobs.length > 0) {
      const xmlOutput = js2xmlparser.parse("source", { job: jobs });
      fs.writeFileSync(outputPath, xmlOutput);
      console.log(`Processed XML file saved to ${outputPath}`);
    } else {
      console.error(
        `Invalid XML format: No job elements found in file ${fileUrl}`
      );
    }
  });

  xml.on("error", (error) => {
    console.error("Error processing XML:", error);
  });
}

function flattenJob(job) {
  const flattened = {};
  function flatten(obj, prefix = "") {
    for (const key in obj) {
      const newKey = prefix ? `${prefix}_${key}` : key;
      if (typeof obj[key] === "object" && obj[key] !== null) {
        flatten(obj[key], newKey);
      } else {
        flattened[newKey] = obj[key];
      }
    }
  }
  flatten(job);
  return flattened;
}

async function downloadCsvAndConvertToXml(url, jobpoolid, acctnum, outputPath) {
  try {
    console.log(`Starting download of CSV from ${url}...`);
    const response = await axios.get(url, {
      responseType: "arraybuffer",
    });
    if (response.status === 200) {
      const csvData = response.data.toString("utf8");

      const records = await new Promise((resolve, reject) => {
        csvParse.parse(
          csvData,
          {
            columns: true,
            skip_empty_lines: true,
            auto_parse: true,
          },
          (err, records) => {
            if (err) reject(err);
            else resolve(records);
          }
        );
      });

      const sanitizedRecords = records.map((record) => {
        const sanitizedRecord = {};
        for (const key in record) {
          if (record.hasOwnProperty(key)) {
            const sanitizedKey = key.replace(/[^a-zA-Z0-9_]/g, "_");
            sanitizedRecord[sanitizedKey] = record[key];
          }
        }
        sanitizedRecord.jobpoolid = jobpoolid;
        sanitizedRecord.acctnum = acctnum;
        return sanitizedRecord;
      });

      const xml = js2xmlparser.parse("source", { job: sanitizedRecords });
      fs.writeFileSync(outputPath, xml);
      console.log(`Processed CSV to XML file saved to ${outputPath}`);
    } else {
      console.error(
        "Failed to download the CSV file. Status Code:",
        response.status
      );
    }
  } catch (error) {
    console.error("Error during CSV download and conversion:", error.message);
  }
}
