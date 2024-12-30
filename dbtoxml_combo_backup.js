const fs = require('fs');
const mysql = require('mysql');
const path = require('path');
const config = require('./config');
const { envSuffix } = require("./config");

const outputXmlFolderPath = `/chroot/home/appljack/appljack.com/html${envSuffix}/applfeeds`;

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
        poolXmlFeeds.query(
            'SELECT acf.*, ac.jobpoolid, acf.cpc as feed_cpc FROM applcustfeeds acf ' +
            'JOIN applcust ac ON ac.custid = acf.custid WHERE acf.status = "active"',
            (error, results) => {
                if (error) {
                    console.error('Error fetching feed criteria:', error);
                    reject(error);
                } else {
                    resolve(results);
                }
            }
        );
    });
}

function buildQueryFromCriteria(criteria) {
    let conditions = [];
    let query = "SELECT aj.*, COALESCE(aj.cpc, '" + criteria.feed_cpc + "') AS effective_cpc FROM appljobs aj JOIN applcust ac ON aj.jobpoolid = ac.jobpoolid";

    if (!criteria.custid) {
        console.error('custid is not defined in criteria.');
        return null; // Exit if custid is not defined in criteria
    }

    conditions.push(`ac.custid = '${criteria.custid}'`);
    if (criteria.custquerykws) {
        const keywords = criteria.custquerykws.split(',');
        const includes = keywords.filter(k => !k.trim().startsWith('NOT ')).map(k => `LOWER(aj.title) LIKE LOWER('%${k.trim()}%')`);
        const excludes = keywords.filter(k => k.trim().startsWith('NOT ')).map(k => `LOWER(aj.title) NOT LIKE LOWER('%${k.trim().substring(4)}%')`);

        if (includes.length) conditions.push(`(${includes.join(' OR ')})`);
        if (excludes.length) conditions.push(`(${excludes.join(' AND ')})`);
    }

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

    if (conditions.length) {
        query += " WHERE " + conditions.join(' AND ');
    }

    query += " ORDER BY aj.posted_at DESC";
    return query;
}

async function processQueriesSequentially() {
    console.log("Starting to process criteria into queries");
    const feedsCriteria = await fetchAllFeedsWithCriteria();
    const custFileHandles = {};

    for (let criteria of feedsCriteria) {
        if (!criteria.jobpoolid) {
            console.error('Jobpoolid is not defined in criteria for feedid:', criteria.feedid);
            continue;
        }

        const query = buildQueryFromCriteria(criteria);
        console.log('SQL Query:', query);

        // Manage file stream creation per custid
        if (!custFileHandles[criteria.custid]) {
            const filePath = path.join(outputXmlFolderPath, `${criteria.custid}.xml`);
            custFileHandles[criteria.custid] = fs.createWriteStream(filePath, {flags: 'w'});
            custFileHandles[criteria.custid].write('<?xml version="1.0" encoding="UTF-8" standalone="yes"?>\n<jobs>\n');
        }

        // Note that we now pass the entire 'criteria' object to ensure access to necessary identifiers
        await streamResultsToXml(custFileHandles[criteria.custid], query, criteria);
    }

    // Closing all file handles
    Object.keys(custFileHandles).forEach(custid => {
        custFileHandles[custid].write('</jobs>\n');
        custFileHandles[custid].end();
    });

    console.log("All feeds processed. Closing database connection.");
    await closePool();
}

async function streamResultsToXml(fileStream, query, criteria) {
    return new Promise((resolve, reject) => {
        const queryStream = poolXmlFeeds.query(query).stream();
        queryStream.on('error', (error) => {
            console.error('Stream encountered an error:', error);
            reject(error);
        }).on('data', (job) => {
            fileStream.write(`  <job>\n`);
            Object.keys(job).forEach(key => {
                if (key === 'url' || key === 'cpc' || key === 'effective_cpc') return; // Skip specific keys
                let value = job[key] ? job[key].toString() : '';
                value = value.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&apos;');
                fileStream.write(`    <${key}>${value}</${key}>\n`);
            });
            let customUrl = `https://appljack.com/applpass.php?c=${encodeURIComponent(criteria.custid)}&f=${encodeURIComponent(criteria.feedid)}&j=${encodeURIComponent(job.job_reference)}`;
            customUrl = customUrl.replace(/&/g, '&amp;');
            fileStream.write(`    <url>${customUrl}</url>\n`);
            fileStream.write(`    <cpc>${job.effective_cpc}</cpc>\n`);
            fileStream.write(`  </job>\n`);
        }).on('end', () => {
            resolve();
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

processQueriesSequentially().catch(console.error);
