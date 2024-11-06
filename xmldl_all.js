const axios = require("axios");
const fs = require("fs");
const xml2js = require("xml2js");
const zlib = require("zlib");
const csvParse = require("csv-parse");
const js2xmlparser = require("js2xmlparser");
const mysql = require("mysql");
const stream = require("stream");
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
        db.end(); // Close the database connection if there is an error
        return;
      }

      // Check if results are empty
      if (!results.length) {
        console.log("No data found to process.");
        db.end(); // Close the database connection if no data is found
        return;
      }

      // Processing each result with the provided file_url and file_type
      for (const { file_url, file_type, jobpoolid, acctnum } of results) {
        try {
          // Validate the URL
          if (!isValidUrl(file_url.trim())) {
            console.error(`Invalid URL: ${file_url}. Skipping.`);
            continue;
          }

          const outputFileName = `${acctnum}-${jobpoolid}.xml`;
          const outputPath = `/chroot/home/appljack/appljack.com/html/feeddownloads/${outputFileName}`;
          if (file_type.toLowerCase() === "xml") {
            await downloadAndProcessXml(
              file_url.trim(),
              jobpoolid,
              acctnum,
              outputPath
            );
          } else if (file_type.toLowerCase() === "csv") {
            await downloadCsvAndConvertToXml(
              file_url.trim(),
              jobpoolid,
              acctnum,
              outputPath
            );
          }
        } catch (error) {
          console.error(
            `Error processing ${file_type.toUpperCase()} file at URL ${file_url}:`,
            error.message
          );
        }
      }

      // Close the database connection after all processing is done
      db.end(() => console.log("Database connection closed."));
    }
  );
}

// Function to validate URL
function isValidUrl(url) {
  try {
    new URL(url); // If the URL is invalid, this will throw an error
    return true;
  } catch {
    return false;
  }
}

async function downloadAndProcessXml(url, jobpoolid, acctnum, outputPath) {
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
      "binary/octet-stream", 
    ];
    const isXmlContent = validContentTypes.some((type) =>
      response.headers["content-type"].includes(type)
    );

    if (response.status === 200 && isXmlContent) {
      const fileStream = fs.createWriteStream(outputPath); // Create a write stream to directly write data to the file
      let xmlStream;

      if (
        response.headers["content-encoding"] === "gzip" ||
        response.headers["content-type"].includes("application/x-gzip") ||
        response.headers["content-type"].includes("application/gzip")
      ) {
        xmlStream = response.data.pipe(zlib.createGunzip());
      } else {
        xmlStream = response.data.pipe(new stream.PassThrough());
      }

      xmlStream.pipe(fileStream); // Pipe the XML stream directly to the file stream

      fileStream.on("finish", async () => {
        console.log(`Download completed, starting XML processing...`);
        try {
          const parser = new xml2js.Parser();
          let xmlData = ""; // Initialize an empty string to store XML data

          // Process XML data in chunks to avoid memory issues
          xmlStream.on("data", (chunk) => {
            xmlData += chunk.toString(); // Append each chunk to the XML data string
            // Check if the XML data exceeds the maximum allowed string length
            if (xmlData.length > 1000000) {
              // Adjust the threshold as needed
              // Parse the accumulated XML data
              parser.parseString(xmlData, (err, result) => {
                if (err) {
                  console.error("Error parsing XML:", err);
                  return;
                }
                processXml(result, jobpoolid, acctnum, outputPath); // Process the parsed XML data
                xmlData = ""; // Reset the XML data string
              });
            }
          });

          // Handle the remaining XML data after processing chunks
          xmlStream.on("end", () => {
            if (xmlData) {
              parser.parseString(xmlData, (err, result) => {
                if (err) {
                  console.error("Error parsing XML:", err);
                  return;
                }
                processXml(result, jobpoolid, acctnum, outputPath); // Process the parsed XML data
              });
            }
          });
        } catch (error) {
          console.error("Error processing XML:", error);
        }
      });

      fileStream.on("error", (error) => {
        console.error("Error writing to file stream:", error);
      });
    } else {
      console.error(
        `Failed to download XML: Status Code ${response.status}, Content-Type ${response.headers["content-type"]}`
      );
    }
  } catch (error) {
    console.error(`Error during download and processing for ${url}:`, error);
  }
}

// Function to process parsed XML data
function processXml(result, jobpoolid, acctnum, outputPath) {
  // Check if the result contains the expected structure
  if (result && result.source && result.source.job) {
    // Modify each job entry to include jobpoolid and acctnum
    const modifiedJobs = result.source.job.map((job) => {
      job.acctnum = [acctnum];
      job.jobpoolid = [jobpoolid];
      return job;
    });

    // Update the result with modified job entries
    result.source.job = modifiedJobs;

    // Convert the modified result back to XML format
    const builder = new xml2js.Builder();
    const modifiedXml = builder.buildObject(result);

    // Write the modified XML back to the file
    fs.writeFileSync(outputPath, modifiedXml);
    console.log(`Processed XML file saved to ${outputPath}`);
  } else {
    console.error("Invalid XML format: Missing or invalid job property");
  }
}

async function downloadCsvAndConvertToXml(url, jobpoolid, acctnum, outputPath) {
  try {
    console.log("Starting download of CSV...");
    const response = await axios.get(url, {
      responseType: "arraybuffer",
    });
    if (response.status === 200) {
      const csvData = response.data.toString("utf8");

      // Parse the CSV data
      const records = await new Promise((resolve, reject) => {
        csvParse.parse(
          csvData,
          {
            columns: true,
            skip_empty_lines: true,
            auto_parse: true, // Automatically parse numbers and booleans
          },
          (err, records) => {
            if (err) reject(err);
            else resolve(records);
          }
        );
      });

      // Sanitize column names
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

      // Construct the XML structure directly with `source` as the root element
      const xml = js2xmlparser.parse("source", { job: sanitizedRecords });

      // Write the XML data to the output file
      fs.writeFileSync(outputPath, xml); // Use writeFileSync
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
