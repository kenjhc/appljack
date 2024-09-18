const zlib = require('zlib');
const fs = require('fs');
const xml2js = require('xml2js');

(async () => {
    const fetch = await import('node-fetch'); // Dynamic import

    const { CookieJar } = require('tough-cookie');
    const cookieJar = new CookieJar();

    async function downloadAndExtractXml() {
        try {
            const gzFileUrl = 'https://www.theladders.com/job-feeds/job-rapido-job-feed.xml';
            console.log("Starting download...");

            // Make the fetch request
            const response = await fetch.default(gzFileUrl, {
                headers: {
                    'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/58.0.3029.110 Safari/537.3',
                    'Accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Encoding': 'gzip, deflate, br', // Be aware that you'll need to handle compression manually
                    'Accept-Language': 'en-US,en;q=0.9'
                },
                // Pass the cookie jar to maintain cookies across requests
                // This will automatically handle cookies for subsequent requests
                // based on the Set-Cookie headers in the responses
                agent: new (require('https').Agent)({ keepAlive: true, rejectUnauthorized: false, cookieJar })
            });

            // Check the status code directly from the response object
            const statusCode = response.status;
            console.log("Response Status Code:", statusCode);

            // Handle the body of the response
            const body = await response.text();

            if (statusCode === 200) {
                console.log("Download successful, processing file...");
                const xmlFilePath = '/chroot/home/appljack/appljack.com/html/feeddownloads/xmldl_ladders.xml';
                let xmlData = body;

                // Process XML data
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
                            job.custid = ["3872157160"];
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
            } else {
                console.error('Failed to download the file. Status Code:', statusCode);
                console.error('Response Body:', body);
            }
        } catch (error) {
            console.error('Error during download and extraction:', error.message);
            console.error(error.stack);
        }
    }

    downloadAndExtractXml();
})();
