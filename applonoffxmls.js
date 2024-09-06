require('dotenv').config(); 

const mysql = require('mysql2/promise');
const fs = require('fs').promises; // Use fs promises API for async operations
const path = require('path');
// Configure your database connection here
const pool = mysql.createPool({
  host: process.env.DB_HOST,
  user: process.env.DB_USERNAME,
  password: process.env.DB_PASSWORD,
  database: process.env.DB_DATABASE,
  charset: process.env.DB_CHARSET
});

const generateXmlFilesForFeeds = async () => {
  let connection;
  try {
    connection = await pool.getConnection();

    // Select all rows where status is 'capped' or 'stopped'
    const [feeds] = await connection.query('SELECT * FROM applcustfeeds WHERE status IN ("capped", "stopped")');

    for (const feed of feeds) {
      // Construct the file name
      const fileName = `${feed.custid}-${feed.feedid}.xml`;
      // Define the file path (adjust the directory path as needed)
      const filePath = path.join('/chroot/home/appljack/appljack.com/html/applfeeds/', fileName);

      // Create an empty XML file
      await fs.writeFile(filePath, '', { flag: 'w' });
      console.log(`Created empty XML file: ${fileName}`);
    }

  } catch (error) {
    console.error('An error occurred:', error.message);
  } finally {
    // Close the database connection pool
    if (connection) await connection.release();
    await pool.end();
    console.log('Database connection closed.');
  }
};

generateXmlFilesForFeeds();
