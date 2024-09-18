const fs = require('fs');
const readline = require('readline');
const mysql = require('mysql2/promise');
const path = require('path');
const config = require('./config');

// Database configuration
const dbConfig = {
    host: config.host,
    user: config.username,
    password: config.password,
    database: config.database
};

// File paths
const backupFilePath = path.join(__dirname, 'applpass_queue_backup.json');
const logFilePath = path.join(__dirname, 'applpass_backup.log');  // Log file path

// Clear the log file at the start of the script
fs.writeFileSync(logFilePath, '', 'utf8');

// Function to log errors or info
function logError(error) {
    const errorMessage = `${new Date().toISOString()} - Error: ${error}\n`;
    fs.appendFileSync(logFilePath, errorMessage, 'utf8');
}

// Function to check for missing required fields and log them
function checkMissingFields(eventData) {
    const missingFields = [];
    if (!eventData.eventid) missingFields.push('eventid');
    if (!eventData.timestamp) missingFields.push('timestamp');
    if (!eventData.custid || isNaN(parseInt(eventData.custid))) missingFields.push('custid'); // Ensure custid is valid
    if (!eventData.job_reference) missingFields.push('job_reference'); // Using the correct field name from JSON
    if (!eventData.refurl) missingFields.push('refurl');
    if (!eventData.userAgent) missingFields.push('userAgent'); // Using the correct field name from JSON
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

        // Create a read stream for the backup file
        const fileStream = fs.createReadStream(backupFilePath);
        const rl = readline.createInterface({
            input: fileStream,
            crlfDelay: Infinity
        });

        for await (const line of rl) {
            if (line.trim()) {
                const eventData = JSON.parse(line);

                // Check for missing required fields
                const missingFields = checkMissingFields(eventData);
                if (missingFields.length > 0) {
                    logError(`Missing or invalid fields [${missingFields.join(', ')}] for eventID: ${eventData.eventid || 'UNKNOWN'} - Skipped`);
                    continue; // Skip this event
                }

                try {
                    // Replace undefined optional values with null
                    const values = [
                        eventData.eventid,
                        eventData.timestamp,
                        eventData.eventtype ?? null,
                        eventData.custid,
                        eventData.job_reference, // Directly using job_reference from JSON
                        eventData.refurl,
                        eventData.userAgent, // Directly using userAgent from JSON
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

                } catch (dbError) {
                    // Check if the error is due to a duplicate eventid
                    if (dbError.code === 'ER_DUP_ENTRY') {
                        logError(`Duplicate EventID: ${eventData.eventid} - Skipped`);
                    } else {
                        logError(`Database Insertion Error: ${dbError.message}`);
                    }
                }
            }
        }

        // Close the stream
        fileStream.close();

    } catch (err) {
        logError(err.message);  // Log any errors that occur
    } finally {
        if (connection) {
            await connection.end();
        }
    }
}

// Run the backup event insertion function
insertBackupEvents();
