const axios = require('axios');
const zlib = require('zlib');
const fs = require('fs');
const xml2js = require('xml2js');

const gzFileUrl = 'https://clickcastfeeds.s3.amazonaws.com/af146f38797f7e92453378d5fa19f3e4/feed.xml.gz';

async function downloadAndExtractXml() {
  try {
    console.log("Starting download...");
    const response = await axios.get(gzFileUrl, { responseType: 'stream' });
    if (response.status === 200) {
      console.log("Download successful, processing file...");
      const xmlFilePath = '/chroot/home/appljack/appljack.com/html/feeddownloads/xmldl_orcdicefeed.xml';
      let gunzipStream = zlib.createGunzip();
      let xmlData = '';

      gunzipStream.on('data', (chunk) => xmlData += chunk.toString());
      gunzipStream.on('error', (error) => {
        console.error('Error during gunzip:', error.message);
      });
      gunzipStream.on('end', async () => {
        console.log("Decompression complete, parsing XML...");
        try {
          const parser = new xml2js.Parser();
          const result = await parser.parseStringPromise(xmlData);

          // Adjusted path based on the correct structure
          if (result.source && result.source.jobs && result.source.jobs[0] && result.source.jobs[0].job) {
            console.log("Modifying job elements...");
            result.source.jobs[0].job.forEach(job => {
              job.custid = "1234567890"; // Set custid directly as a string
            });
            console.log("Modified first job for inspection:", JSON.stringify(result.source.jobs[0].job[0], null, 2)); // Debugging
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

      response.data.pipe(gunzipStream);
    } else {
      console.error('Failed to download the .gz file. Status Code:', response.status);
    }
  } catch (error) {
    console.error('Error during download and extraction:', error.message);
  }
}

downloadAndExtractXml();
