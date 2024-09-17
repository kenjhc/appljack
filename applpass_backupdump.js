const fs = require('fs');
const path = require('path');

// Define the path for the input log file and the output JSON file
const logFilePath = path.join(__dirname, 'applpass7.log');
const outputFilePath = path.join(__dirname, 'applpass_backupdump.json');

// Define the cut-off date (events before this date will be ignored)
const cutoffDate = new Date('2024-09-01T00:00:00');

// Function to extract JSON data from log file and apply the timestamp filter
function extractEventData(logFile) {
    // Read the log file
    const logData = fs.readFileSync(logFile, 'utf8');

    // Use a regular expression to find and capture JSON data from "Event data to write" lines
    const regex = /Event data to write: (\{.*?\})/g;
    let match;
    const eventDataArray = [];

    // Loop through all matches in the log file
    while ((match = regex.exec(logData)) !== null) {
        // Parse the matched JSON string
        try {
            const eventData = JSON.parse(match[1]);

            // Convert the timestamp string to a Date object for comparison
            const eventDate = new Date(eventData.timestamp);

            // Only add the event data if the timestamp is after or on the cutoff date
            if (eventDate >= cutoffDate) {
                eventDataArray.push(eventData);
            }
        } catch (error) {
            console.error("Error parsing JSON:", error);
        }
    }

    // Return the array of filtered event data
    return eventDataArray;
}

// Main function to extract data and write it to the output file
function main() {
    // Extract the event data from the log file, applying the timestamp filter
    const eventDataArray = extractEventData(logFilePath);

    // Write the extracted data to the output JSON file
    fs.writeFileSync(outputFilePath, JSON.stringify(eventDataArray, null, 2), 'utf8');
    console.log(`Successfully extracted and wrote ${eventDataArray.length} valid event entries to ${outputFilePath}`);
}

// Run the main function
main();
