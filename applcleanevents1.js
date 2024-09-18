const mysql = require('mysql2');
const fs = require('fs');
const config = require('./config');

// Create a connection to the database with promise support
const connection = mysql.createConnection({
  host: config.host,
  user: config.username,
  password: config.password,
  database: config.database
}).promise();

// Logging function to write messages to a log file
const logMessage = (message) => {
  const timestamp = new Date().toISOString();
  const log = `${timestamp} - ${message}\n`;
  fs.appendFileSync('applcleanevents.log', log);
  console.log(log);  // Also print to console for real-time feedback
};

const processBatch = async (limit, offset) => {
  try {
    logMessage(`Processing batch starting at offset ${offset}...`);

    // Select eventids to be deleted and log each one
    const selectQuery = `
      SELECT e1.eventid FROM applevents e1
      JOIN applevents e2
      ON e1.ipaddress = e2.ipaddress
      AND e1.jobid = e2.jobid
      AND e1.timestamp > e2.timestamp
      AND TIMESTAMPDIFF(SECOND, e2.timestamp, e1.timestamp) < 30
      WHERE e1.timestamp BETWEEN '2024-08-01 00:00:00' AND '2024-08-31 23:59:59'
      AND e2.timestamp BETWEEN '2024-08-01 00:00:00' AND '2024-08-31 23:59:59'
      LIMIT ${limit} OFFSET ${offset};
    `;

    logMessage(`Executing batch select query...`);
    const [rows] = await connection.query(selectQuery);

    if (rows.length === 0) {
      logMessage('No more duplicates found in this batch.');
      return;
    }

    logMessage(`Found ${rows.length} potential duplicates to process.`);

    // Insert into the temporary table and log each one
    for (const row of rows) {
      await connection.query('INSERT INTO temp_to_delete (eventid) VALUES (?)', [row.eventid]);
      logMessage(`Inserted eventid ${row.eventid} into temp_to_delete`);
    }

    logMessage(`Inserted ${rows.length} eventids into temp_to_delete for deletion.`);

    // Process the next batch
    await processBatch(limit, offset + limit);

  } catch (error) {
    logMessage(`Error during batch processing: ${error.message}`);
    throw error;
  }
};

const deleteDuplicates = async () => {
  try {
    logMessage('Starting deletion of duplicates from applevents...');

    const deleteQuery = `
      DELETE FROM applevents
      WHERE eventid IN (SELECT eventid FROM temp_to_delete);
    `;
    const [result] = await connection.query(deleteQuery);

    logMessage(`Deleted ${result.affectedRows} duplicate rows from applevents.`);
  } catch (error) {
    logMessage(`Error during deletion of duplicates: ${error.message}`);
    throw error;
  }
};

const startCleanup = async () => {
  logMessage('Starting cleanup of applevents for August 2024.');

  try {
    // Create temporary table with correct data type for eventid
    await connection.query('CREATE TEMPORARY TABLE temp_to_delete (eventid CHAR(20))');
    await processBatch(10000, 0);
    await deleteDuplicates();
    logMessage('Cleanup completed successfully.');
  } catch (error) {
    logMessage('Cleanup failed: ' + error.message);
  } finally {
    connection.end();
  }
};

// Start the cleanup process
startCleanup();
