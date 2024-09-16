const fs = require('fs');
const path = require('path');
const sax = require('sax');
const mysql = require('mysql');
const config = require('./config');

process.on('unhandledRejection', (reason, promise) => {
    console.error('Unhandled rejection:', reason);
});

process.on('uncaughtException', (error) => {
    console.error('Uncaught exception:', error);
});

let jobQueue = [];
let isProcessingBatch = false;
const batchSize = 100;
let totalJobsInsertedCount = 0;
let tempConnection;
let connectionReleased = false;

// Function to create a temporary table with a dedicated connection
const createTempTableWithConnection = async () => {
    tempConnection = await new Promise((resolve, reject) => {
        pool.getConnection((err, connection) => {
            if (err) reject(err);
            else resolve(connection);
        });
    });

    return new Promise((resolve, reject) => {
        tempConnection.query(`CREATE TEMPORARY TABLE IF NOT EXISTS appljobs_temp LIKE appljobs`, (err, result) => {
            if (err) {
                console.error('Failed to create temporary table', err);
                reject(err);
            } else {
                console.log('Temporary table created successfully');
                resolve();
            }
        });
    });
};

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


const pool = mysql.createPool({
    connectionLimit: 10,
    host: config.host,
    user: config.username,
    password: config.password,
    database: config.database,
    charset: config.charset,
});

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


const createTempTable = async () => {
    return new Promise((resolve, reject) => {
        pool.query(`CREATE TEMPORARY TABLE IF NOT EXISTS appljobs_temp LIKE appljobs`, (err, result) => {
            if (err) {
                console.error('Failed to create temporary table', err);
                return reject(err);
            }
            console.log('Temporary table created successfully');
            resolve();
        });
    });
};



// Modify the processBatch function to use appljobs_temp
const processBatch = async (batch, feedId) => {
  const values = batch.map(item => [
    item.feedId, item.location, item.title, item.city, item.state, item.zip, item.country,
    item.job_type, item.posted_at, item.job_reference, item.company, item.mobile_friendly_apply,
    item.category, item.html_jobs, item.url, item.body, item.custid
  ]);

  return new Promise((resolve, reject) => {
    const query = 'INSERT INTO appljobs_temp (feedId, location, title, city, state, zip, country, job_type, posted_at, job_reference, company, mobile_friendly_apply, category, html_jobs, url, body, custid) VALUES ?';
    tempConnection.query(query, [values], (err, result) => {
      if (err) return reject(err);
      console.log(`Batch processed: ${result.affectedRows} rows inserted.`);
      resolve();
    });
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

const updateApplJobsTable = async () => {
  return new Promise((resolve, reject) => {
    // Begin a transaction with tempConnection
    tempConnection.beginTransaction(err => {
      if (err) return reject(err);

      const updateExisting = `
        UPDATE appljobs aj
        JOIN appljobs_temp ajt ON aj.job_reference = ajt.job_reference
        SET aj.feedId = ajt.feedId, aj.location = ajt.location, aj.title = ajt.title,
            aj.city = ajt.city, aj.state = ajt.state, aj.zip = ajt.zip, aj.country = ajt.country,
            aj.job_type = ajt.job_type, aj.posted_at = ajt.posted_at, aj.company = ajt.company,
            aj.mobile_friendly_apply = ajt.mobile_friendly_apply, aj.category = ajt.category,
            aj.html_jobs = ajt.html_jobs, aj.url = ajt.url, aj.body = ajt.body, aj.custid = ajt.custid`;

      const insertNew = `
        INSERT INTO appljobs (feedId, location, title, city, state, zip, country, job_type,
                              posted_at, job_reference, company, mobile_friendly_apply,
                              category, html_jobs, url, body, custid)
        SELECT ajt.feedId, ajt.location, ajt.title, ajt.city, ajt.state, ajt.zip, ajt.country,
               ajt.job_type, ajt.posted_at, ajt.job_reference, ajt.company, ajt.mobile_friendly_apply,
               ajt.category, ajt.html_jobs, ajt.url, ajt.body, ajt.custid
        FROM appljobs_temp ajt
        LEFT JOIN appljobs aj ON ajt.job_reference = aj.job_reference
        WHERE aj.job_reference IS NULL`;

      const deleteOld = `
        DELETE aj
        FROM appljobs aj
        LEFT JOIN appljobs_temp ajt ON aj.job_reference = ajt.job_reference
        WHERE ajt.job_reference IS NULL`;

        // Execute the updateExisting query
        tempConnection.query(updateExisting, (err, result) => {
          if (err) {
            tempConnection.rollback(() => reject(err));
            return;
          }

          // Execute the insertNew query
          tempConnection.query(insertNew, (err, result) => {
            if (err) {
              tempConnection.rollback(() => reject(err));
              return;
            }

            // Execute the deleteOld query
            tempConnection.query(deleteOld, (err, result) => {
              if (err) {
                tempConnection.rollback(() => reject(err));
                return;
              }

              // Commit the transaction
              tempConnection.commit(err => {
                if (err) {
                  tempConnection.rollback(() => reject(err));
                  return;
                }
                console.log('Updated appljobs table successfully.');
                resolve();
              });
            });
          });
        });
      });
    });
  };



// Function to process the remaining jobs in the queue with the correct feedId
const processRemainingJobs = async () => {
  console.log(`Processing remaining ${jobQueue.length} jobs in queue.`);

  while (jobQueue.length > 0) {
    const job = jobQueue.shift(); // Take the first job from the queue
    await processBatch([job], job.feedId); // Process this single job using its correct feedId
  }
};

// Function to parse and process XML files
const processFiles = async () => {
    const directoryPath = '/chroot/home/appljack/appljack.com/html/feeddownloads/';

    try {
        await createTempTableWithConnection(); // Use the function that establishes tempConnection

        const filePaths = fs.readdirSync(directoryPath)
                            .filter(file => path.extname(file) === '.xml')
                            .map(file => path.join(directoryPath, file));

        for (const filePath of filePaths) {
            await parseXmlFile(filePath);
            console.log(`Processed file: ${filePath}`);
            await processRemainingJobs();
        }

        await updateApplJobsTable();
        console.log('appljobs table synchronized successfully.');
    } catch (err) {
        console.error('An error occurred:', err);
    } finally {
    console.log('Ensuring all operations have completed before closing the connection pool...');
    // Check if the tempConnection exists and has not been released yet
    if (tempConnection && !connectionReleased) {
        try {
            tempConnection.release(); // Release the dedicated connection
            console.log('Temporary connection released successfully.');
            connectionReleased = true; // Mark the connection as released to prevent future release attempts
        } catch (releaseError) {
            console.error('Error releasing temporary connection:', releaseError);
        }
    }
    closeConnectionPool(); // Proceed with closing the pool
}

};


// Function to safely close the connection pool
const closeConnectionPool = () => {
  pool.end(err => {
    if (err) {
      console.error('Failed to close the connection pool:', err);
    } else {
      console.log('Connection pool closed successfully.');
    }
  });
};

// Call processFiles to start processing
processFiles().then(() => {
  console.log('All processing complete.');
}).catch(error => {
  console.error('An error occurred during processing:', error);
});
