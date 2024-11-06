const axios = require('axios');
const fs = require('fs');
const xml2js = require('xml2js');
const zlib = require('zlib');
const csvParse = require('csv-parse');
const js2xmlparser = require("js2xmlparser");
const mysql = require('mysql');
const stream = require('stream');
const config = require('./config');

// Set up a MySQL connection
const db = mysql.createConnection({
  host: config.host,
  user: config.username,
  password: config.password,
  database: config.database,
  charset: config.charset,
});

db.connect(err => {
  if (err) throw err;
  console.log('Connected to database.');
  startProcess();
});

async function startProcess() {
  db.query('SELECT jobpoolurl as file_url, jobpoolfiletype as file_type, jobpoolid, acctnum FROM appljobseed', async (err, results) => {
    if (err) {
      console.error('Error fetching data from database:', err.message);
      db.end(); // Close the database connection if there is an error
      return;
    }

    // Check if results are empty
    if (!results.length) {
      console.log('No data found to process.');
      db.end(); // Close the database connection if no data is found
      return;
    }

    // Processing each result with the provided file_url and file_type
    for (const { file_url, file_type, jobpoolid, acctnum } of results) {
      try {
        const outputFileName = `${acctnum}-${jobpoolid}.xml`;
        const outputPath = `/chroot/home/appljack/appljack.com/html/feeddownloads/${outputFileName}`;
        if (file_type.toLowerCase() === 'xml') {
          await downloadAndProcessXml(file_url.trim(), jobpoolid, acctnum, outputPath, file_url);
        } else if (file_type.toLowerCase() === 'csv') {
          await downloadCsvAndConvertToXml(file_url.trim(), jobpoolid, acctnum, outputPath);
        }
      } catch (error) {
        console.error(`Error processing ${file_type.toUpperCase()} file at URL ${file_url}:`, error.message);
      }
    }

    // Close the database connection after all processing is done
    db.end(() => console.log('Database connection closed.'));
  });
}

async function downloadAndProcessXml(url, jobpoolid, acctnum, outputPath, fileUrl) {
  console.log(`Attempting to download and process XML from ${url}...`);
  try {
    const response = await axios.get(url, {
      responseType: 'stream',
      timeout: 30000,
      headers: {
        'User-Agent': 'Mozilla/5.0',
        'Accept-Encoding': 'gzip, deflate, br'
      }
    });

    const validContentTypes = ['application/xml', 'text/xml', 'application/x-gzip', 'application/gzip', "binary/octet-stream"];
    const isXmlContent = validContentTypes.some(type => response.headers['content-type'].includes(type));

    if (response.status === 200 && isXmlContent) {
      const fileStream = fs.createWriteStream(outputPath); // Create a write stream to directly write data to the file
      let xmlStream;

      if (response.headers['content-encoding'] === 'gzip' ||
          response.headers['content-type'].includes('application/x-gzip') ||
          response.headers['content-type'].includes('application/gzip')) {
        xmlStream = response.data.pipe(zlib.createGunzip());
      } else {
        xmlStream = response.data.pipe(new stream.PassThrough());
      }

      xmlStream.pipe(fileStream); // Pipe the XML stream directly to the file stream

      fileStream.on('finish', async () => {
        console.log(`Download completed, starting XML processing...`);
        try {
          const parser = new xml2js.Parser();
          const xmlData = fs.readFileSync(outputPath); // Read the XML data from the file
          parser.parseString(xmlData, (err, result) => {
            if (err) {
              console.error('Error parsing XML:', err);
              return;
            }
            processXml(result, jobpoolid, acctnum, outputPath, fileUrl);
          });
        } catch (error) {
          console.error('Error processing XML:', error);
        }
      });

      fileStream.on('error', error => {
        console.error('Error writing to file stream:', error);
      });

    } else {
      console.error(`Failed to download XML: Status Code ${response.status}, Content-Type ${response.headers['content-type']}`);
    }
  } catch (error) {
    console.error(`Error during download and processing for ${url}:`, error);
  }
}

// Function to process parsed XML data
function processXml(result, jobpoolid, acctnum, outputPath, fileUrl) {
  // Extract all job elements from the parsed XML
  const jobs = extractJobs(result);

  if (jobs.length > 0) {
    // Flatten nested XML structure if needed
    flattenXml(jobs);

    // Modify each job entry to include jobpoolid and acctnum
    const modifiedJobs = jobs.map(job => {
      job.acctnum = [acctnum];
      job.jobpoolid = [jobpoolid];
      return job;
    });

    // Construct the XML structure directly with `source` as the root element
    const xml = js2xmlparser.parse("source", { job: modifiedJobs });

    // Write the XML data to the output file
    fs.writeFileSync(outputPath, xml); // Use writeFileSync
    console.log(`Processed XML file saved to ${outputPath}`);
  } else {
    console.error(`Invalid XML format: No job elements found in file ${fileUrl}`);
  }
}

// Function to extract all job elements from the parsed XML
function extractJobs(result) {
  let jobs = [];
  function traverse(node) {
    if (node.job) {
      if (Array.isArray(node.job)) {
        jobs = jobs.concat(node.job);
      } else {
        jobs.push(node.job);
      }
    }
    for (const key in node) {
      if (typeof node[key] === 'object') {
        traverse(node[key]);
      }
    }
  }
  traverse(result);
  return jobs;
}

// Function to flatten nested XML structure
function flattenXml(jobs) {
  jobs.forEach(job => {
    for (const key in job) {
      if (typeof job[key] === 'object' && !Array.isArray(job[key])) {
        for (const nestedKey in job[key]) {
          job[`${key}${nestedKey}`] = job[key][nestedKey];
        }
        delete job[key];
      }
    }
  });
}

async function downloadCsvAndConvertToXml(url, jobpoolid, acctnum, outputPath) {
  try {
    console.log("Starting download of CSV...");
    const response = await axios.get(url, {
      responseType: 'arraybuffer'
    });
    if (response.status === 200) {
      const csvData = response.data.toString('utf8');

      // Parse the CSV data
      const records = await new Promise((resolve, reject) => {
        csvParse.parse(csvData, {  // Correct usage of csvParse
          columns: true,
          skip_empty_lines: true,
          auto_parse: true // Automatically parse numbers and booleans
        }, (err, records) => {
          if (err) reject(err);
          else resolve(records);
        });
      });

      // Sanitize column names
      const sanitizedRecords = records.map(record => {
        const sanitizedRecord = {};
        for (const key in record) {
          if (record.hasOwnProperty(key)) {
            const sanitizedKey = key.replace(/[^a-zA-Z0-9_]/g, '_');
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
      console.error('Failed to download the CSV file. Status Code:', response.status);
    }
  } catch (error) {
    console.error('Error during CSV download and conversion:', error.message);
  }
}
