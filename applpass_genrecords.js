const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

// Path to your JSON file
const filePath = '/chroot/home/appljack/appljack.com/html/applpass_queue.json';

// Base data
const baseData = {
    "custid": "9706023615",
    "job_reference": "10127",
    "jobpoolid": "2244384563",
    "refurl": "no-referrer",
    "ipaddress": "173.53.37.224",
    "feedid": "898ae4f20d",
    "userAgent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/127.0.0.0 Safari/537.36"
};

// Function to generate records
function generateRecords(numRecords) {
    const startTime = new Date('2024-08-15T18:18:01'); // Starting timestamp
    const records = [];

    for (let i = 0; i < numRecords; i++) {
        const eventid = crypto.createHash('sha1').update(Math.random().toString()).digest('hex').substring(0, 10);
        const timestamp = new Date(startTime.getTime() + i * 1000).toISOString().replace('T', ' ').substr(0, 19);

        const record = {
            ...baseData,
            eventid: eventid,
            timestamp: timestamp
        };

        records.push(JSON.stringify(record));
    }

    // Write records to the JSON file
    fs.writeFileSync(filePath, records.join('\n') + '\n', { flag: 'a' }); // Append to the file
    console.log(`${numRecords} records written to ${filePath}`);
}

// How many records you want to generate
const numRecords = 10000; // Change this number as needed

// Generate and write records
generateRecords(numRecords);
