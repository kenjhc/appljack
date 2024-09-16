const axios = require('axios');
const zlib = require('zlib');
const fs = require('fs');
const xml2js = require('xml2js');

const gzFileUrl = 'https://www.theladders.com/job-feeds/job-rapido-job-feed.xml';

async function downloadAndExtractXml() {
  try {
    console.log("Starting download...");
    // Include headers to mimic a browser request
    const response = await axios.get(gzFileUrl, {
      responseType: 'stream',
      headers: {
        'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/58.0.3029.110 Safari/537.3',
        'Accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,image/apng,*/*;q=0.8',
        'Accept-Encoding': 'gzip, deflate, br',
        'Accept-Language': 'en-US,en;q=0.9'
      }
    });

    if (response.status === 200) {
      console.log("Download successful, processing file...");
      const xmlFilePath = '/chroot/home/appljack/appljack.com/html/feeddownloads/xmldl_ladders.xml';
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
          if (result.source && result.source.job) {
            console.log("Modifying job elements...");
            result.source.job.forEach(job => {
              // Correctly set custid as an array of one element
              job.custid = ["3872157160"];
            });
            console.log("Modified first job for inspection:", JSON.stringify(result.source.job[0], null, 2)); // Debugging
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
