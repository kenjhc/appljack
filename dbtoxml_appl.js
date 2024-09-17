const fs = require('fs');
const mysql = require('mysql');
const path = require('path');
const config = require('./config');

const outputXmlFolderPath = '/chroot/home/appljack/appljack.com/html/applfeeds';

const poolXmlFeeds = mysql.createPool({
    connectionLimit: 10,
    host: config.host,
    user: config.username,
    password: config.password,
    database: config.database,
    charset: config.charset,
});

async function fetchAllFeedsWithCriteria() {
    return new Promise((resolve, reject) => {
        poolXmlFeeds.query('SELECT * FROM applcustfeeds WHERE status = "active"', (error, results) => {
            if (error) {
                console.error('Error fetching feed criteria:', error);
                reject(error);
            } else {
                resolve(results);
            }
        });
    });
}

function buildQueryFromCriteria(criteria) {
    let conditions = [];
    let query = "SELECT aj.*, acf.cpc, acf.cpa, acf.feedid FROM appljobs aj JOIN applcustfeeds acf ON aj.custid = acf.custid";

    conditions.push(`aj.custid = '${criteria.custid}'`);

    // Handling keywords inclusion and exclusion
    if (criteria.custquerykws) {
        const keywords = criteria.custquerykws.split(',');
        const includes = keywords.filter(k => !k.trim().startsWith('NOT ')).map(k => `LOWER(aj.title) LIKE LOWER('%${k.trim()}%')`);
        const excludes = keywords.filter(k => k.trim().startsWith('NOT ')).map(k => `LOWER(aj.title) NOT LIKE LOWER('%${k.trim().substring(4)}%')`);

        if (includes.length) conditions.push(`(${includes.join(' OR ')})`);
        if (excludes.length) conditions.push(`(${excludes.join(' AND ')})`);
    }

    // Handling company inclusion and exclusion
    if (criteria.custqueryco) {
        const companies = criteria.custqueryco.split(',');
        const coIncludes = companies.filter(c => !c.trim().startsWith('NOT ')).map(c => `LOWER(aj.company) LIKE LOWER('%${c.trim()}%')`);
        const coExcludes = companies.filter(c => c.trim().startsWith('NOT ')).map(c => `LOWER(aj.company) NOT LIKE LOWER('%${c.trim().substring(4)}%')`);

        if (coIncludes.length) conditions.push(`(${coIncludes.join(' OR ')})`);
        if (coExcludes.length) conditions.push(`(${coExcludes.join(' AND ')})`);
    }

    if (criteria.custqueryindustry) {
        conditions.push(`LOWER(aj.industry) LIKE LOWER('%${criteria.custqueryindustry.trim()}%')`);
    }

    if (criteria.custquerycity) {
        conditions.push(`LOWER(aj.city) LIKE LOWER('%${criteria.custquerycity.trim()}%')`);
    }

    if (criteria.custquerystate) {
        conditions.push(`LOWER(aj.state) = LOWER('${criteria.custquerystate.trim()}')`);
    }

    if (criteria.feedid) {
        conditions.push(`acf.feedid = '${criteria.feedid}'`);
    }

    if (conditions.length) {
        query += " WHERE " + conditions.join(' AND ');
    }

    query += " ORDER BY aj.posted_at DESC";

    return query;
}



async function processQueriesSequentially() {
    console.log("Starting to process criteria into queries");

    const feedsCriteria = await fetchAllFeedsWithCriteria();

    for (let criteria of feedsCriteria) {
        console.log(`Processing feed: ${criteria.feedid} for customer: ${criteria.custid}`);

        // Construct SQL query based on feed criteria
        const query = buildQueryFromCriteria(criteria);

        // Log the SQL query to the console
        console.log('SQL Query:', query);

        try {
            // Fetch jobs based on the constructed query
            const results = await queryDatabase(query);
            if (results && results.length > 0) {
                console.log(`Jobs found for feedid: ${criteria.feedid} and custid: ${criteria.custid}`);
            } else {
                console.log(`No jobs found for feedid: ${criteria.feedid} and custid: ${criteria.custid}`);
            }
            // Generate XML file named [custid]-[feedid].xml, including jobs based on criteria
            // This is moved outside of the 'if' condition so it executes regardless of results length
            await generateXmlFile(criteria.custid, criteria.feedid, results);
        } catch (error) {
            console.error('Error during query execution or XML file writing:', error);
        }
    }

    console.log("All feeds processed. Closing database connection.");
    await closePool();
}


function queryDatabase(query) {
    return new Promise((resolve, reject) => {
        poolXmlFeeds.query(query, (error, results) => {
            if (error) {
                reject(error);
            } else {
                resolve(results);
            }
        });
    });
}

async function closePool() {
    return new Promise((resolve, reject) => {
        poolXmlFeeds.end(err => {
            if (err) {
                console.error("Failed to close the pool:", err);
                reject(err);
            } else {
                console.log("Pool closed successfully.");
                resolve();
            }
        });
    });
}

async function generateXmlFile(custid, feedid, jobsData) {
    const filePath = path.join(outputXmlFolderPath, `${custid}-${feedid}.xml`);
    let stream;

    try {
        // Ensure the directory exists
        fs.mkdirSync(outputXmlFolderPath, { recursive: true });

        stream = fs.createWriteStream(filePath, {flags: 'w'});
        stream.write('<?xml version="1.0" encoding="UTF-8" standalone="yes"?>\n<jobs>\n');

        jobsData.forEach(job => {
            stream.write(`  <job>\n`);
            Object.keys(job).forEach(key => {
                if (key === 'url') return; // Skip the URL key, will add it manually later

                let value = job[key] ? job[key].toString() : '';
                value = value.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&apos;');
                stream.write(`    <${key}>${value}</${key}>\n`);
            });

            if (job.hasOwnProperty('job_reference')) {
                let customUrl = `https://appljack.com/applpass.php?c=${custid}&f=${feedid}&j=${job.job_reference}`;
                customUrl = customUrl.replace(/&/g, '&amp;');
                stream.write(`    <url>${customUrl}</url>\n`);
            }

            stream.write(`  </job>\n`);
        });

        stream.write('</jobs>\n');
    } catch (error) {
        console.error("Error creating or writing to file:", error);
        return; // Exit the function if an error occurs
    }

    return new Promise((resolve, reject) => {
        if (!stream) {
            reject("Stream is not defined.");
            return;
        }

        stream.end();

        stream.on('finish', () => {
            console.log(`${custid}-${feedid}.xml has been saved in ${outputXmlFolderPath}`);
            resolve();
        });

        stream.on('error', (error) => {
            console.error('Stream encountered an error:', error);
            reject(error);
        });
    });
}





processQueriesSequentially().catch(console.error);
