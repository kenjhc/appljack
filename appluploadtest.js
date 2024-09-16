const fs = require('fs');
const path = require('path');
const sax = require('sax');
const mysql = require('mysql');
const moment = require('moment');
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
let tempConnection;

const cleanDecimalValue = (value) => {
    if (typeof value === 'string') {
        const cleanedValue = value.replace(/[^0-9.]/g, '');
        return cleanedValue === '' ? null : cleanedValue;
    }
    return value;
};

const loadMapping = async (jobpoolid) => {
    return new Promise((resolve, reject) => {
        const query = 'SELECT xml_tag, db_column FROM appldbmapping WHERE jobpoolid = ?';
        tempConnection.query(query, [jobpoolid], (err, results) => {
            if (err) return reject(err);

            const mapping = {};

            results.forEach(({ xml_tag, db_column }) => {
                mapping[xml_tag] = db_column;
            });

            mapping.custid = 'custid';
            mapping.jobpoolid = 'jobpoolid';
            mapping.acctnum = 'acctnum';

            console.log('Mapping loaded:', mapping);

            resolve(mapping);
        });
    });
};

const getAcctNum = async (jobpoolid) => {
    return new Promise((resolve, reject) => {
        const query = 'SELECT acctnum FROM appljobseed WHERE jobpoolid = ?';
        tempConnection.query(query, [jobpoolid], (err, results) => {
            if (err) {
                console.error('Error fetching acctnum:', err);
                return reject(err);
            }
            resolve(results.length > 0 ? results[0].acctnum : null);
        });
    });
};

const createTempTableWithConnection = async () => {
    tempConnection = await new Promise((resolve, reject) => {
        pool.getConnection((err, connection) => {
            if (err) return reject(err);
            resolve(connection);
        });
    });

    return new Promise((resolve, reject) => {
        tempConnection.query('CREATE TEMPORARY TABLE IF NOT EXISTS appljobs_temp LIKE appljobs', (err, result) => {
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
    const logDir = path.join('/chroot/home/appljack/appljack.com/html/log');
    const logFile = path.join(logDir, 'failed_batches.log');

    if (!fs.existsSync(logDir)) {
        fs.mkdirSync(logDir, { recursive: true });
    }

    const timestamp = new Date().toISOString();
    const logEntry = {
        timestamp,
        feedId,
        error: error.message,
        batchDetails: batch.map(item => ({ job_reference: item.job_reference, city: item.city }))
    };

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

const processQueue = async (feedId) => {
    if (isProcessingBatch || jobQueue.length < batchSize) return;
    isProcessingBatch = true;
    const batch = jobQueue.slice(0, batchSize);
    jobQueue = jobQueue.slice(batchSize);

    try {
        await processBatch(batch, feedId);
    } catch (err) {
        console.error('Failed to process batch:', err);
        logFailedBatch(batch, feedId, err);
    } finally {
        isProcessingBatch = false;
        if (jobQueue.length >= batchSize) processQueue(feedId);
    }
};

const processBatch = async (batch, feedId) => {
    const values = batch.map(item => [
        item.feedId, item.location, item.title, item.city, item.state, item.zip, item.country,
        item.job_type, item.posted_at, item.job_reference, item.company, item.mobile_friendly_apply,
        item.category, item.html_jobs, item.url, item.body, item.jobpoolid, item.acctnum, item.industry,
        cleanDecimalValue(item.cpc), cleanDecimalValue(item.cpa),
        item.custom1, item.custom2, item.custom3, item.custom4, item.custom5
    ]);

    return new Promise((resolve, reject) => {
        const query = `
            INSERT INTO appljobs_temp (feedId, location, title, city, state, zip, country, job_type,
                                       posted_at, job_reference, company, mobile_friendly_apply,
                                       category, html_jobs, url, body, jobpoolid, acctnum, industry,
                                       cpc, cpa, custom1, custom2, custom3, custom4, custom5)
            VALUES ? ON DUPLICATE KEY UPDATE job_reference=VALUES(job_reference)
        `;

        tempConnection.query(query, [values], (err, result) => {
            if (err) {
                console.error('Failed to insert batch into appljobs_temp:', err);
                reject(err);
            } else {
                resolve();
            }
        });
    });
};

const parseXmlFile = async (filePath) => {
    console.log(`Starting to process file: ${filePath}`);
    const feedId = path.basename(filePath, path.extname(filePath));
    const parts = feedId.split('-');
    const jobpoolid = parts[1];
    const acctnum = await getAcctNum(jobpoolid);
    const tagToPropertyMap = await loadMapping(jobpoolid);

    try {
        return new Promise((resolve, reject) => {
            const stream = fs.createReadStream(filePath);
            const parser = sax.createStream(true);
            let currentItem = {};
            let currentTag = '';
            let tagStack = [];

            const setNestedValue = (obj, path, value) => {
                const keys = path.split('.');
                let tempObj = obj;
                while (keys.length > 1) {
                    const key = keys.shift();
                    if (!tempObj[key]) {
                        tempObj[key] = {};
                    }
                    tempObj = tempObj[key];
                }
                tempObj[keys[0]] = value;
            };

            const getNestedValue = (obj, path) => {
                const keys = path.split('.');
                let tempObj = obj;
                for (let i = 0; i < keys.length; i++) {
                    if (!tempObj[keys[i]]) {
                        return undefined;
                    }
                    tempObj = tempObj[keys[i]];
                }
                return tempObj;
            };

            parser.on('opentag', (node) => {
                currentTag = node.name;
                tagStack.push(currentTag);
                if (node.name === 'job' || node.name === 'doc') {
                    currentItem = { feedId: feedId };
                }
            });

            parser.on('text', (text) => {
                if (currentItem && currentTag) {
                    let trimmedText = text.trim();
                    let fullTag = tagStack.join('.');

                    if (tagToPropertyMap[fullTag]) {
                        let propertyName = tagToPropertyMap[fullTag];
                        setNestedValue(currentItem, propertyName, (getNestedValue(currentItem, propertyName) || '') + trimmedText);
                    } else if (tagToPropertyMap[`job.${fullTag}`]) {
                        let propertyName = tagToPropertyMap[`job.${fullTag}`];
                        setNestedValue(currentItem, propertyName, (getNestedValue(currentItem, propertyName) || '') + trimmedText);
                    } else if (tagToPropertyMap[`doc.${fullTag}`]) {
                        let propertyName = tagToPropertyMap[`doc.${fullTag}`];
                        setNestedValue(currentItem, propertyName, (getNestedValue(currentItem, propertyName) || '') + trimmedText);
                    } else if (tagToPropertyMap[currentTag]) {
                        let propertyName = tagToPropertyMap[currentTag];
                        setNestedValue(currentItem, propertyName, (getNestedValue(currentItem, propertyName) || '') + trimmedText);
                    }
                }
            });

            parser.on('cdata', (cdata) => {
                if (currentItem && currentTag) {
                    let fullTag = tagStack.join('.');

                    if (tagToPropertyMap[fullTag]) {
                        let propertyName = tagToPropertyMap[fullTag];
                        setNestedValue(currentItem, propertyName, (getNestedValue(currentItem, propertyName) || '') + cdata);
                    } else if (tagToPropertyMap[`job.${fullTag}`]) {
                        let propertyName = tagToPropertyMap[`job.${fullTag}`];
                        setNestedValue(currentItem, propertyName, (getNestedValue(currentItem, propertyName) || '') + cdata);
                    } else if (tagToPropertyMap[`doc.${fullTag}`]) {
                        let propertyName = tagToPropertyMap[`doc.${fullTag}`];
                        setNestedValue(currentItem, propertyName, (getNestedValue(currentItem, propertyName) || '') + cdata);
                    } else if (tagToPropertyMap[currentTag]) {
                        let propertyName = tagToPropertyMap[currentTag];
                        setNestedValue(currentItem, propertyName, (getNestedValue(currentItem, propertyName) || '') + cdata);
                    }
                }
            });

            parser.on('closetag', (nodeName) => {
                if (nodeName === 'job' || nodeName === 'doc') {
                    currentItem.jobpoolid = jobpoolid;
                    currentItem.acctnum = acctnum;

                    if (currentItem.posted_at) {
                        const dateFormats = [
                            'YYYY-MM-DD HH:mm:ss.SSS [UTC]',
                            'YYYY-MM-DDTHH:mm:ss.SSS[Z]',
                            'YYYY-MM-DDTHH:mm:ss.SSS',
                            'ddd, DD MMM YYYY HH:mm:ss [UTC]',
                            'YYYY-MM-DD'
                        ];
                        const date = moment(currentItem.posted_at, dateFormats, true);
                        if (date.isValid()) {
                            currentItem.posted_at = date.format('YYYY-MM-DD');
                        } else {
                            console.warn(`Invalid date format found: ${currentItem.posted_at}`);
                            currentItem.posted_at = null;
                        }
                    }

                    console.log('Parsed item:', currentItem);

                    if (!currentItem.job_reference) {
                        console.warn('Missing job_reference for item:', currentItem);
                    }

                    jobQueue.push(currentItem);
                    processQueue(feedId).catch(err => console.error("Error processing queue:", err));
                }
                tagStack.pop();
            });

            parser.on('end', async () => {
                if (jobQueue.length > 0) {
                    await processQueue(feedId);
                }
                resolve();
            });

            parser.on('error', (err) => {
                reject(err);
            });

            stream.pipe(parser);
        });
    } catch (err) {
        console.error('Error parsing XML file:', err);
        throw err;
    }
};

const processRemainingJobs = async (feedId) => {
    while (jobQueue.length > 0) {
        const batch = jobQueue.splice(0, batchSize);
        try {
            await processBatch(batch, feedId);
        } catch (err) {
            console.error('Failed to process batch:', err);
            logFailedBatch(batch, feedId, err);
        }
    }
};

const filePath = '/chroot/home/appljack/appljack.com/html/feeddownloads/8215880437-5845774622.xml'; // Update this with the specific file path for testing

(async () => {
    try {
        await createTempTableWithConnection();
        await parseXmlFile(filePath);
        await processRemainingJobs(filePath.split('/').pop().split('-').pop().split('.').shift());
        tempConnection.release();
    } catch (err) {
        console.error('Error:', err);
    }
})();
