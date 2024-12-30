const axios = require('axios');
const zlib = require('zlib');
const fs = require('fs');
const { envSuffix } = require("./config");

// URL of the .gz file you want to download
const gzFileUrl = 'https://clickcastfeeds.s3.amazonaws.com/13a27ffa60d42c953cf6968cecff7291/feed.xml.gz';

// Function to download and extract the XML file
async function downloadAndExtractXml() {
  try {
    const response = await axios.get(gzFileUrl, {
      responseType: 'stream', // Use stream for large files
    });

    if (response.status === 200) {
      const xmlFilePath = `/chroot/home/appljack/appljack.com/html${envSuffix}/feeddownloads/xmldl_orccpa.xml`;
      const writeStream = fs.createWriteStream(xmlFilePath);

      // Create a gunzip stream
      const gunzip = zlib.createGunzip();

      // Pipe the response stream through gunzip and then to the file
      response.data.pipe(gunzip).pipe(writeStream);

      // Listen for download progress events
      let downloadedSize = 0;
      const totalSize = parseInt(response.headers['content-length'], 10);

      response.data.on('data', (chunk) => {
        downloadedSize += chunk.length;
        const progress = (downloadedSize / totalSize) * 100;
        if (process.stdout.isTTY) {
          process.stdout.clearLine(); // Clear the last printed line
          process.stdout.cursorTo(0); // Move the cursor to the beginning
          process.stdout.write(`Downloading: ${progress.toFixed(2)}%`);
        }
      });

      // When the download is complete
      response.data.on('end', () => {
        if (process.stdout.isTTY) {
          process.stdout.clearLine(); // Clear the last printed line
          process.stdout.cursorTo(0); // Move the cursor to the beginning
        }
        console.log(`XML file saved to ${xmlFilePath}`);
      });
    } else {
      console.error('Failed to download the .gz file');
    }
  } catch (error) {
    console.error('Error:', error.message);
  }
}

// Call the function to start the download and extraction process
downloadAndExtractXml();
