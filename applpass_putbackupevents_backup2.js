const fs = require('fs');
const { chain } = require('stream-chain');
const { parser } = require('stream-json');
const { pick } = require('stream-json/filters/Pick');
const { streamArray } = require('stream-json/streamers/StreamArray');
const mysql = require('mysql2/promise');
const path = require('path');

// Database configuration
const dbConfig = {
    host: 'localhost',
    user: 'appljack_johnny',
    password: 'app1j0hnny01$',
    database: 'appljack_core',
};

// File paths
const backupFilePath = path.join(__dirname, 'applpass_backupdump_923.json');
const logFilePath = path.join(__dirname, 'applpass_backup.log');  // Log file path


// Clear the log file at the start of the script
fs.writeFileSync(logFilePath, '', 'utf8');

// Function to log errors or info
function logError(error) {
    const errorMessage = `${new Date().toISOString()} - Error: ${error}\n`;
    fs.appendFileSync(logFilePath, errorMessage, 'utf8');
    console.log(errorMessage);  // Also print to console for debugging
}

// Function to check for missing required fields and log them
function checkMissingFields(eventData) {
    const missingFields = [];
    if (!eventData.eventid) missingFields.push('eventid');
    if (!eventData.timestamp) missingFields.push('timestamp');
    if (!eventData.custid || isNaN(parseInt(eventData.custid))) missingFields.push('custid');
    if (!eventData.job_reference) missingFields.push('job_reference');
    if (!eventData.refurl) missingFields.push('refurl');
    if (!eventData.userAgent) missingFields.push('userAgent');
    if (!eventData.ipaddress) missingFields.push('ipaddress');
    if (!eventData.feedid) missingFields.push('feedid');

    return missingFields;
}

// Function to insert events from the backup file
async function insertBackupEvents() {
    let connection;
    try {
        // Connect to the database
        connection = await mysql.createConnection(dbConfig);
        console.log('Database connection established.');

        // Create a streaming JSON parser
        const pipeline = chain([
            fs.createReadStream(backupFilePath),  // Stream the file
            parser(),                            // Parse the JSON
            pick({ filter: '.*' }),               // Pick array elements
            streamArray()                         // Stream through each element of the array
        ]);

        pipeline.on('data', async ({ value: eventData }) => {
            console.log(`Processing event: ${eventData.eventid}`);

            // Check for missing required fields
            const missingFields = checkMissingFields(eventData);
            if (missingFields.length > 0) {
                logError(`Missing or invalid fields [${missingFields.join(', ')}] for eventID: ${eventData.eventid || 'UNKNOWN'} - Skipped`);
                return;  // Skip this event
            }

            try {
                // Replace undefined optional values with null
                const values = [
                    eventData.eventid,
                    eventData.timestamp,
                    eventData.eventtype ?? null,
                    eventData.custid,
                    eventData.job_reference,
                    eventData.refurl,
                    eventData.userAgent,
                    eventData.ipaddress,
                    eventData.cpc ?? null,
                    eventData.cpa ?? null,
                    eventData.feedid
                ];

                // Insert data into applevents table
                const query = `
                    INSERT INTO applevents (eventid, timestamp, eventtype, custid, jobid, refurl, useragent, ipaddress, cpc, cpa, feedid)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                `;
                await connection.execute(query, values);
                console.log(`Inserted eventID: ${eventData.eventid}`);

            } catch (dbError) {
                // Check if the error is due to a duplicate eventid
                if (dbError.code === 'ER_DUP_ENTRY') {
                    logError(`Duplicate EventID: ${eventData.eventid} - Skipped`);
                } else {
                    logError(`Database Insertion Error: ${dbError.message}`);
                }
            }
        });

        pipeline.on('end', () => {
            console.log('Finished processing the file.');
        });

        pipeline.on('error', (err) => {
            logError(`Stream Error: ${err.message}`);
        });

    } catch (err) {
        logError(err.message);  // Log any errors that occur
    } finally {
        if (connection) {
            await connection.end();
            console.log('Database connection closed.');
        }
    }
}

// Run the backup event insertion function
insertBackupEvents();
