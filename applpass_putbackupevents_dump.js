console.log("Test: Node.js is executing");

const fs = require('fs');
const readline = require('readline');
const mysql = require('mysql2/promise');
const path = require('path');

// Log to show that the script has started
console.log('Script started');

// Database configuration
const dbConfig = {
    host: 'localhost',
    user: 'appljack_johnny',
    password: 'app1j0hnny01$',
    database: 'appljack_core',
};

// File paths
const backupFilePath = path.join(__dirname, 'applpass_backupdump_923.json');
const logFilePath = path.join(__dirname, 'applpass_backup_923.log');  // Log file path

// Log the resolved directory and file paths
console.log(`__dirname: ${__dirname}`);
console.log(`Resolved Backup File Path: ${backupFilePath}`);
console.log(`Log File Path: ${logFilePath}`);

// Check if the backup file exists
if (!fs.existsSync(backupFilePath)) {
    console.error(`Backup file not found: ${backupFilePath}`);
    process.exit(1);  // Exit if the backup file doesn't exist
} else {
    console.log('Backup file exists');
}

// Check if the log directory exists, and create it if necessary
const logDir = path.dirname(logFilePath);
if (!fs.existsSync(logDir)) {
    console.log(`Log directory does not exist, creating: ${logDir}`);
    fs.mkdirSync(logDir, { recursive: true });
}

// Clear the log file at the start of the script
try {
    fs.writeFileSync(logFilePath, '', 'utf8');
    console.log('Log file initialized');
} catch (error) {
    console.error(`Error creating or writing to log file: ${error.message}`);
    process.exit(1);  // Exit if we can't create or write to the log file
}

// Function to log errors or info
function logError(error) {
    const errorMessage = `${new Date().toISOString()} - Error: ${error}\n`;
    console.log(errorMessage);  // Print error to the console for debugging
    fs.appendFileSync(logFilePath, errorMessage, 'utf8');
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
        connection = await mysql.createConnection(dbConfig);
        console.log('Database connection established');

        const fileStream = fs.createReadStream(backupFilePath);
        fileStream.on('error', (error) => {
            console.error(`Error reading backup file: ${error.message}`);
            process.exit(1);  // Exit if file cannot be read
        });

        const rl = readline.createInterface({
            input: fileStream,
            crlfDelay: Infinity
        });

        try {
            for await (const line of rl) {
                console.log(`Processing line: ${line}`);
                if (line.trim()) {
                    try {
                        const eventData = JSON.parse(line);
                        console.log(`Parsed event: ${eventData.eventid}`);

                        const missingFields = checkMissingFields(eventData);
                        if (missingFields.length > 0) {
                            logError(`Missing or invalid fields [${missingFields.join(', ')}] for eventID: ${eventData.eventid || 'UNKNOWN'} - Skipped`);
                            continue;
                        }

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

                        const query = `
                            INSERT INTO applevents (eventid, timestamp, eventtype, custid, jobid, refurl, useragent, ipaddress, cpc, cpa, feedid)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        `;
                        console.log(`Inserting EventID: ${eventData.eventid}`);
                        await connection.execute(query, values);

                    } catch (parseError) {
                        logError(`JSON Parse Error: ${parseError.message} - Skipped`);
                    }
                }
            }
        } catch (error) {
            console.error(`Error processing lines: ${error.message}`);
        }

        rl.close();
        console.log('Finished processing the file.');

    } catch (err) {
        logError(err.message);
    } finally {
        if (connection) {
            await connection.end();
            console.log('Database connection closed');
        }
    }
}

// Run the backup event insertion function
insertBackupEvents();
