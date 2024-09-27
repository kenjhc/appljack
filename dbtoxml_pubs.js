const fs = require('fs');
const path = require('path');
const xml2js = require('xml2js');
const mysql = require('mysql2');
const config = require('./config');

// Define the output directory for the combined XML files
const outputXmlFolderPath = "/chroot/home/appljack/appljack.com/html/applfeeds";

// Initialize MySQL connection pool using the config
const poolXmlFeeds = mysql.createPool({
  connectionLimit: 10,
  host: config.host,
  user: config.username,
  password: config.password,
  database: config.database,
  charset: config.charset,
});

// Initialize XML parser and builder
const parser = new xml2js.Parser();
const builder = new xml2js.Builder();

// Query applcustfeeds to get relevant data (custid, feedid, activepubs)
function getFeedsData() {
  return new Promise((resolve, reject) => {
    const query = `SELECT custid, feedid, activepubs FROM applcustfeeds WHERE activepubs IS NOT NULL`;
    poolXmlFeeds.query(query, (error, results) => {
      if (error) reject(error);
      resolve(results);
    });
  });
}

// Read and parse an XML file
function readXMLFile(filePath) {
  return new Promise((resolve, reject) => {
    fs.readFile(filePath, 'utf-8', (err, data) => {
      if (err) reject(err);
      parser.parseString(data, (err, result) => {
        if (err) reject(err);
        resolve(result);
      });
    });
  });
}

// Write a combined XML file
function writeCombinedXMLFile(custid, publisherid, combinedJobs) {
  const combinedXml = builder.buildObject({ jobs: { job: combinedJobs } });
  const fileName = `${custid}-${publisherid}.xml`;
  const outputFilePath = path.join(outputXmlFolderPath, fileName);

  fs.writeFile(outputFilePath, combinedXml, (err) => {
    if (err) throw err;
    console.log(`Successfully created ${fileName}`);
  });
}

// Main function to combine XML files by custid and publisherid
async function combineXmlFiles() {
  try {
    const feedsData = await getFeedsData();

    // Process each custid with its feedid and activepubs (publisherid)
    for (const feedData of feedsData) {
      const { custid, feedid, activepubs } = feedData;
      const publisherIds = activepubs.split(','); // Handle multiple publishers

      for (const publisherid of publisherIds) {
        const jobElements = [];

        // Find XML files that match the pattern [custid]-[feedid].xml
        const xmlFileName = `${custid}-${feedid}.xml`;
        const xmlFilePath = path.join(outputXmlFolderPath, xmlFileName);

        // Check if the file exists
        if (fs.existsSync(xmlFilePath)) {
          const xmlContent = await readXMLFile(xmlFilePath);

          // Collect all <job> elements from the file
          if (xmlContent.jobs && xmlContent.jobs.job) {
            jobElements.push(...xmlContent.jobs.job);
          }
        }

        // If jobs were found, write them to a new combined file
        if (jobElements.length > 0) {
          writeCombinedXMLFile(custid, publisherid, jobElements);
        }
      }
    }

    console.log('XML file combining completed.');
    poolXmlFeeds.end(); // End the pool connection when done
  } catch (error) {
    console.error('Error combining XML files:', error);
  }
}

// Run the script
combineXmlFiles();
