const fs = require('fs');
const path = require('path');

// Function to clear old logs
function clearOldLogs(logFilePath) {
    // Read the log file
    fs.readFile(logFilePath, 'utf8', (err, data) => {
        if (err) {
            console.error('Error reading the log file:', err);
            return;
        }

        // Get the current time
        const now = new Date();

        // Define the threshold time (72 hours ago)
        const threshold = new Date(now.getTime() - 72 * 60 * 60 * 1000);

        // Split the data into lines
        const lines = data.split(/(?=\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\])/);

        // Filter logs to keep only the ones within the last 72 hours
        const recentLogs = lines.filter(line => {
            // Extract the timestamp from the log entry
            const timestampStr = line.match(/\[(.*?)\]/)[1]; // Assumes the format [YYYY-MM-DD HH:MM:SS]
            const logTime = new Date(timestampStr);

            // Check if the log entry is within the last 72 hours
            return logTime > threshold;
        });

        // Write the recent logs back to the file
        fs.writeFile(logFilePath, recentLogs.join(''), 'utf8', (err) => {
            if (err) {
                console.error('Error writing the log file:', err);
            } else {
                console.log('Old logs cleared successfully.');
            }
        });
    });
}

// Specify the path to your log file
const logFilePath = path.join(__dirname, '/applpass.log');

// Call the function to clear old logs
clearOldLogs(logFilePath);
