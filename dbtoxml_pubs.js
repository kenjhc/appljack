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


async function updateUrlInCombinedXml(custid, publisherid) {
    const fileName = `${custid}-${publisherid}.xml`;
    const filePath = path.join(outputXmlFolderPath, fileName);
  
    if (fs.existsSync(filePath)) {
      try {
        const xmlContent = await readXMLFile(filePath);
  
        // Modify the <url> field in each <job> element
        if (xmlContent.jobs && xmlContent.jobs.job) {
          xmlContent.jobs.job.forEach(job => {
            if (job.url && job.url[0]) {
              const currentUrl = job.url[0];
              const separator = currentUrl.includes('?') ? '&' : '?';
              job.url[0] = `${currentUrl}${separator}pub=${publisherid}`;
            }
          });
  
          // Write the updated XML back to the file
          const updatedXml = builder.buildObject(xmlContent);
          fs.writeFileSync(filePath, updatedXml, 'utf8');
          console.log(`Updated URLs in ${fileName}`);
        } else {
          console.warn(`No jobs found in ${fileName} to update URLs.`);
        }
      } catch (err) {
        console.error(`Error updating URLs in ${fileName}:`, err);
      }
    } else {
      console.warn(`File not found for URL update: ${fileName}`);
    }
  }

// Main function to combine XML files by custid and publisherid
async function combineXmlFiles() {
    try {
      const feedsData = await getFeedsData();
      const groupedFeeds = {};
  
      // Group feedids by custid and activepubs (publisherid)
      for (const feedData of feedsData) {
        const { custid, feedid, activepubs } = feedData;
        const publisherIds = activepubs.split(','); // Handle multiple publishers
  
        if (!groupedFeeds[custid]) {
          groupedFeeds[custid] = {};
        }
  
        // Group feedid by each publisherid
        publisherIds.forEach(publisherid => {
          if (!groupedFeeds[custid][publisherid]) {
            groupedFeeds[custid][publisherid] = [];
          }
          groupedFeeds[custid][publisherid].push(feedid);
        });
      }
  
      // Process each custid and publisherid group
      for (const custid in groupedFeeds) {
        for (const publisherid in groupedFeeds[custid]) {
          const jobElements = [];
          const feedids = groupedFeeds[custid][publisherid];
  
          for (const feedid of feedids) {
            const xmlFileName = `${custid}-${feedid}.xml`;
            const xmlFilePath = path.join(outputXmlFolderPath, xmlFileName);
  
            // Check if the file exists
            if (fs.existsSync(xmlFilePath)) {
              try {
                const xmlContent = await readXMLFile(xmlFilePath);
  
                // Collect all <job> elements from the file
                if (xmlContent.jobs && xmlContent.jobs.job) {
                  jobElements.push(...xmlContent.jobs.job);
                } else {
                  console.warn(`Warning: No jobs found in file ${xmlFileName}`);
                }
              } catch (err) {
                console.error(`Error reading or parsing XML file: ${xmlFileName}`);
                console.error(err);
              }
            } else {
              console.warn(`File not found: ${xmlFileName}`);
            }
          }
  
          // If jobs were found, write them to a new combined file
          if (jobElements.length > 0) {
            writeCombinedXMLFile(custid, publisherid, jobElements);
  
            // Update the URL in the combined file
            await updateUrlInCombinedXml(custid, publisherid);
          }
        }
      }
  
      console.log('XML file combining and URL update completed.');
    } catch (error) {
      console.error('Error combining XML files:', error);
    } finally {
      poolXmlFeeds.end(() => {
        console.log('MySQL connection pool closed.');
      });
    }
  }
  
  // Run the script
  combineXmlFiles();




