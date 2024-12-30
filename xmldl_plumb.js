const axios = require('axios');
const zlib = require('zlib');
const fs = require('fs');
const xml2js = require('xml2js');
const { envSuffix } = require("./config");

const gzFileUrl = 'https://jobhubcentral.com/combined_plumb.xml';

async function downloadAndExtractXml() {
  try {
    console.log("Starting download...");
    const response = await axios.get(gzFileUrl, { responseType: 'stream' });

    if (response.status === 200) {
      console.log("Download successful, processing file...");
      const xmlFilePath = `/chroot/home/appljack/appljack.com/html${envSuffix}/feeddownloads/xmldl_plumb.xml`;
      let xmlData = '';
      let stream = response.data;

      // Check if the response is gzipped or not
      const contentEncoding = response.headers['content-encoding'];
      if (contentEncoding && contentEncoding.includes('gzip')) {
        // Use gunzip for decompression
        const gunzipStream = zlib.createGunzip();
        stream = response.data.pipe(gunzipStream);

        gunzipStream.on('error', (error) => {
          console.error('Error during gunzip:', error.message);
        });
      }

      // Process XML data
      stream.on('data', (chunk) => xmlData += chunk.toString());
      stream.on('error', (error) => {
        console.error('Error during XML data handling:', error.message);
      });
      stream.on('end', async () => {
        console.log("Processing complete, parsing XML...");
        try {
          const parser = new xml2js.Parser();
          const result = await parser.parseStringPromise(xmlData);

          // Assuming your XML structure matches the parsed output you've shown
          // This will iterate over each job and set the custid
          if (result.jobs && result.jobs.job) {
            console.log("Modifying job elements...");
            result.jobs.job.forEach(job => {
              // Correctly set custid as an array of one element
              job.custid = ["6340517575"];
            });
            console.log("Modified first job for inspection:", JSON.stringify(result.jobs.job[0], null, 2)); // Debugging
          } else {
            console.log("The expected XML structure was not found.");
          }

          const builder = new xml2js.Builder();
          const modifiedXml = builder.buildObject(result);
          fs.writeFileSync(xmlFilePath, modifiedXml);
          console.log(`Modified XML file saved to ${xmlFilePath}`);
        } catch (error) {
          console.error('Error processing XML:', error.message);
        }
      });
    } else {
      console.error('Failed to download the file. Status Code:', response.status);
    }
  } catch (error) {
    console.error('Error during download and extraction:', error.message);
  }
}

downloadAndExtractXml();
