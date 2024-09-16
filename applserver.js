const express = require('express');
const mysql = require('mysql');
const app = express();
const PORT = process.env.PORT || 3000;
const crypto = require('crypto');
const config = require('./config');




// Create a connection pool to MySQL database
const pool = mysql.createPool({
  connectionLimit: 10,
  host: config.host,
  user: config.username,
  password: config.password,
  database: config.database
});

// Asynchronous middleware for logging track.png requests
app.get('/public/track.png', async (req, res, next) => {
  console.log('Track.png request received');

  const { c: acid, f: afid, j: ajid } = req.query; // Extract custid and feedid from query params

  console.log('acid:', acid);
  console.log('afid:', afid);
  console.log('ajid:', ajid);


  // Check if custid or feedid is missing
  if (!acid) {
    console.error('Error: custid is missing');
    return res.status(400).send('custid is required');
  }

  if (!afid) {
    console.error('Error: feedid is missing');
    return res.status(400).send('feedid is required');
  }

  if (!ajid) {
    console.error('Error: feedid is missing');
    return res.status(400).send('job id is required');
  }

  const refurl = req.headers.referer || req.headers.referrer;
  const ipaddress = req.headers['x-forwarded-for'] || req.socket.remoteAddress;
  const eventid = crypto.randomBytes(5).toString('hex');
  const timestamp = new Date().toISOString().slice(0, 19).replace('T', ' ');
  const useragent = req.headers['user-agent'] || 'Unknown';

  try {
    console.log('Before querying for CPA');
    const cpaResult = await new Promise((resolve, reject) => {
      pool.query('SELECT job_reference AS cpa FROM appljobs WHERE job_reference = ?', [ajid], (error, results) => {
        if (error) {
          reject(error);
        } else {
          resolve(results.length > 0 ? results[0].cpa : null);
        }
      });
    });

    console.log('CPA result:', cpaResult);

    console.log('Before inserting into database');
    const insertQuery = 'INSERT INTO applevents (custid, feedid, jobid, refurl, ipaddress, eventid, timestamp, cpa, useragent) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)';
    console.log('SQL Query:', insertQuery);
    await pool.query(insertQuery, [acid, afid, ajid, refurl, ipaddress, eventid, timestamp, cpaResult, useragent]);

    console.log('Data inserted successfully');

    // After logging, serve the 1x1 transparent pixel
    const transparentGif = Buffer.from('R0lGODlhAQABAIAAAP///wAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==', 'base64');
    res.writeHead(200, {
      'Content-Type': 'image/gif',
      'Content-Length': transparentGif.length
    });
    res.end(transparentGif);
  } catch (error) {
    console.error('Error:', error);
    res.status(500).send('Server error');
    next(error); // Pass error to error handling middleware
  }
});

// Define global error handling middleware
app.use((err, req, res, next) => {
  console.error('Global error handler:', err);

  // Handle specific types of errors
  if (err instanceof SyntaxError) {
    res.status(400).send('Bad request');
  } else {
    res.status(500).send('Server error');
  }
});

// Serve other static files from the public directory
app.use(express.static('public'));
console.log(`express thing`);

app.listen(PORT, () => {
  console.log(`Server running on port ${PORT}`);
});
