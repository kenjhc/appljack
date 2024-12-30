const fs = require('fs');
const path = require('path');
const sax = require('sax');
const mysql = require('mysql');
const config = require('./config');
const { envSuffix } = require("./config");

process.on('unhandledRejection', (reason, promise) => {
    console.error('Unhandled rejection:', reason);
    // Log this rejection or investigate further
});

process.on('uncaughtException', (error) => {
    console.error('Uncaught exception:', error);
    // Log this exception or take appropriate action
});


// Define these variables at the top level of your script, making them accessible throughout
let activeDbOperations = 0; // Counter to track active database operations

let jobQueue = []; // Initialize the job queue
let isProcessingBatch = false; // Flag to indicate if a batch is currently being processed
const batchSize = 100; // Define the batch size as a constant accessible throughout your script
let totalJobsInsertedCount = 0;

function logFailedBatch(batch, feedId, error) {
  const logDir = path.join('/chroot/home/appljack/appljack.com/html/log'); // Specify the log directory path
  const logFile = path.join(logDir, 'failed_batches.log');

  // Ensure the log directory exists
  if (!fs.existsSync(logDir)){
    fs.mkdirSync(logDir, { recursive: true }); // Create the directory if it doesn't exist
  }

  const timestamp = new Date().toISOString();
  const logEntry = {
    timestamp,
    feedId,
    error: error.message,
    batchDetails: batch.map(item => ({
      job_reference: item.job_reference,
      city: item.city // Example: log specific fields that might be causing issues
      // Add more fields as necessary
    }))
  };

  // Append the log entry to the log file
  fs.appendFile(logFile, JSON.stringify(logEntry) + '\n', (err) => {
    if (err) {
      console.error('Failed to log failed batch details:', err);
    }
  });
}



// Define processQueue function
const processQueue = async (feedId) => {
  if (isProcessingBatch || jobQueue.length < batchSize) return;
  isProcessingBatch = true;

  const batch = jobQueue.slice(0, batchSize);
  jobQueue = jobQueue.slice(batchSize);

  try {
    await processBatch(batch, feedId);
  //  console.log(`Batch processed successfully. Inserted ${batch.length} rows for feedId: ${feedId}. Remaining jobs in queue: ${jobQueue.length}`);
  } catch (err) {
    console.error('Failed to process batch:', err);
    logFailedBatch(batch, feedId, err);
  } finally {
    isProcessingBatch = false;
    if (jobQueue.length >= batchSize) {
      processQueue(feedId); // Process the next batch if there are enough jobs in the queue
    }
  }
};



// Create a connection pool rather than a single connection
const pool = mysql.createPool({
  connectionLimit: 10, // Example limit, adjust based on your needs
  host: config.host,
  user: config.username,
  password: config.password,
  database: config.database,
  charset: config.charset,
});


// Optionally, listen for pool-level errors (less common, not required for basic operations)
pool.on('error', (err) => {
  console.error('Error with the connection pool:', err);
  // You can add additional logic here if needed
});


// Function to empty the table using the pool
const emptyTable = () => {
  return new Promise((resolve, reject) => {
    const query = 'TRUNCATE TABLE appljobs'; // Define the query
    pool.query(query, (err, result) => { // Use pool.query with the defined query
      if (err) return reject(err);
      console.log('Table emptied successfully');

      resolve();
    });
  });
};

// Function to process and upload a batch of items using the pool
const processBatch = async (batch, feedId, attempt = 0) => {
  activeDbOperations++; // Increment counter at the start
  const maxAttempts = 3; // Maximum number of retry attempts
  const retryDelay = 1000; // Delay between retries in milliseconds

  // A helper function to delay execution
  const delay = (duration) => new Promise(resolve => setTimeout(resolve, duration));

  const executeBatch = () => {
    return new Promise((resolve, reject) => {
      const query = 'INSERT INTO appljobs (feedId, location, title, city, state, zip, country, job_type, posted_at, job_reference, company, mobile_friendly_apply, category, html_jobs, url, body, custid) VALUES ?';


      const values = batch.map(item => {
        // Check if item.posted_at is a valid date string
        let formattedPostedAt = null;
        if (item.posted_at) {
          const tempDate = new Date(item.posted_at);
          if (!isNaN(tempDate.getTime())) { // Checks if the date is valid
            formattedPostedAt = tempDate.toISOString().split('T')[0];
          } else {
            // Handle invalid date (e.g., set to null, use a default date, or log a warning)
            console.warn(`Invalid posted_at date for job_reference ${item.job_reference}: ${item.posted_at}`);
            formattedPostedAt = null; // or set a default value
          }
        }

        return [
          feedId, item.location, item.title, item.city, item.state, item.zip, item.country, item.job_type, formattedPostedAt, item.job_reference, item.company, item.mobile_friendly_apply, item.category, item.html_jobs, item.url, item.body, item.custid
        ];
      });




      pool.query(query, [values], async (err, result) => {
        if (err) {
          console.error(`Attempt ${attempt + 1} failed for feedId ${feedId}, Batch size ${batch.length}`, err);
          if (attempt < maxAttempts - 1) {
            console.log(`Retrying batch for Feed ID ${feedId}. Attempt ${attempt + 2} of ${maxAttempts}`);
            await delay(retryDelay);
            executeBatch().then(resolve).catch(reject);
          } else {
            logFailedBatch(batch, feedId, err); // Log detailed batch information
            reject(err);
          }
        } else {
          if (batch.length !== result.affectedRows) {
            const discrepancyMsg = `Discrepancy detected for feedId ${feedId}: Expected ${batch.length} insertions, but got ${result.affectedRows}.`;
            console.warn(discrepancyMsg);
            logFailedBatch(batch, feedId, new Error(discrepancyMsg)); // Adapt as necessary.
          }
          totalJobsInsertedCount += result.affectedRows;
          resolve(result);
        }
      });
    });
  };

  // Start the first attempt
  return executeBatch().finally(() => {
  activeDbOperations--; // Decrement counter when done
  });
};



// Function to parse and process the XML file using sax
const parseXmlFile = (filePath) => {
  console.log(`Starting to process file: ${filePath}`);
  return new Promise((resolve, reject) => {
    const stream = fs.createReadStream(filePath);
    const parser = sax.createStream(true);
    let currentItem = {};
    let currentTag = '';

    // Extract the filename without extension as feedId
    const feedId = path.basename(filePath, path.extname(filePath));

    let batch = [];
    let jobOpenCount = 0;
    let jobCloseCount = 0;
    let jobsQueuedCount = 0; // Increment when a job is added to the queue



    parser.on('opentag', (node) => {
      currentTag = node.name;
      if (node.name === 'job') {
        jobOpenCount++;
        // Initialize currentItem with feedId when a new job element is encountered
        currentItem = { feedId: feedId };
      }
    });


    // Define a mapping from XML tag names to standardized property names
    const tagToPropertyMap = {
      referencenumber: 'job_reference',
      // Add other mappings here for different fields that need normalization
      description: 'body',
      date: 'posted_at',
      custid: 'custid',

      // Continue mapping other tags as needed
    };

    parser.on('text', (text) => {
      if (currentItem && currentTag) {
        let trimmedText = text.trim();

        // Check if the current tag is in the mapping
        if (tagToPropertyMap[currentTag]) {
          // Use the standardized property name from the mapping
          let propertyName = tagToPropertyMap[currentTag];
          currentItem[propertyName] = (currentItem[propertyName] || '') + trimmedText;
        } else {
          // For tags not in the mapping, use the tag name directly
          currentItem[currentTag] = (currentItem[currentTag] || '') + trimmedText;
        }
      }
    });

    // Add this cdata event handler
    parser.on('cdata', (cdata) => {
      if (currentItem && currentTag) {
        if (tagToPropertyMap[currentTag]) {
          let propertyName = tagToPropertyMap[currentTag];
          currentItem[propertyName] = (currentItem[propertyName] || '') + cdata;
        } else {
          currentItem[currentTag] = (currentItem[currentTag] || '') + cdata;
        }
      }
    });

    const maxLength = 100; // Adjust based on your column's definition
    const maxLengthMore = 255; // Adjust based on your column's definition


    parser.on('closetag', (nodeName) => {
      if (nodeName === 'job') {
        jobCloseCount++;
        currentItem.feedId = feedId; // Ensure feedId is assigned to each job

        // Trim the 'city' field if it exceeds the maxLength (100 characters)
        if (currentItem.city && currentItem.city.length > maxLength) {
          console.warn(`Trimming 'city' data for job_reference ${currentItem.job_reference} because it exceeds ${maxLength} characters.`);
          currentItem.city = currentItem.city.substring(0, maxLength);
        }

        // Trim the 'category' field if it exceeds maxLengthMore (255 characters)
        if (currentItem.category && currentItem.category.length > maxLengthMore) {
          console.warn(`Trimming 'category' data for job_reference ${currentItem.job_reference} because it exceeds ${maxLengthMore} characters.`);
          currentItem.category = currentItem.category.substring(0, maxLengthMore);
        }

        // Previously: Filtering based on CPC value
        // This filtering is now removed based on the requirements


             jobQueue.push(currentItem); // Add the currentItem to the job queue only if it has all required fields
             jobsQueuedCount++; // Increment the counter for jobs added to the queue


        // Attempt to process the queue without awaiting, as this is within an event callback
        processQueue(feedId);
      }
    });





    parser.on('end', async () => {
      console.log(`Total jobs opened: ${jobOpenCount}`);
      console.log(`Total jobs closed: ${jobCloseCount}`);
      console.log(`Total jobs queued: ${jobsQueuedCount}`);
      // Check if there are any jobs left in the queue and attempt to process them
      if (jobQueue.length > 0) {
        await processQueue(feedId);
      }
      console.log('XML file processing completed.');
      console.log(`Total jobs inserted: ${totalJobsInsertedCount}`);



      resolve(); // Resolve the promise indicating the completion of XML file processing
    });


    parser.on('error', (err) => {
      console.error('Parsing error:', err);
      reject(err);
    });

    stream.pipe(parser);
  });
};

// Function to process the remaining jobs in the queue with the correct feedId
const processRemainingJobs = async () => {
  console.log(`Processing remaining ${jobQueue.length} jobs in queue.`);

  while (jobQueue.length > 0) {
    // Take the first job from the queue
    const job = jobQueue.shift();

    // Process this single job (or a small batch, if you modify this to take several)
    // Use the job's feedId, not a hardcoded "finalBatch"
    await processBatch([job], job.feedId);
  }

  // No need to clear the queue here, as it's already being emptied by the shift() method
};

// Function to parse and process XML files
const processFiles = async () => {
  const directoryPath = `/chroot/home/appljack/appljack.com/html${envSuffix}/feeddownloads/`;

  try {
    await emptyTable();

    const files = fs.readdirSync(directoryPath);
    for (const file of files) {
      if (path.extname(file) === '.xml') {
        try {
          await parseXmlFile(path.join(directoryPath, file));
          console.log(`Processed file: ${file}`);
        } catch (err) {
          console.error('Error processing file', file, err);
        }
      }
    }

    // Modified logic to process the remaining jobs in the queue with correct feedId
    console.log(`Processing remaining ${jobQueue.length} jobs in queue.`);
    while (jobQueue.length > 0) {
      // Here we're assuming each job in the queue has a property 'feedId'
      // This property should have been assigned during the parsing phase
      const job = jobQueue.shift(); // Take the first job from the queue

      // Process this single job using its correct feedId
      await processBatch([job], job.feedId);
    }

  } catch (err) {
    console.error('An error occurred:', err);
  } finally {
    await new Promise((resolve, reject) => {
      pool.end(err => {
        if (err) {
          console.error('Failed to close the connection pool:', err);
          reject(err); // reject the promise if there's an error
        } else {
          console.log('Connection pool closed successfully.');
          resolve(); // resolve the promise on successful closure
        }
      });
    });
  }
};

// Start processing
processFiles();
