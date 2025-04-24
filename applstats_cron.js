/**
 * applstats_cron.js  – 60-day feed stats (historical range support)
 */
require('dotenv').config();
const mysql = require('mysql2/promise');
const dayjs = require('dayjs');
const config = require('./config');
const { logMessage } = require('./utils/helpers');

const pool = mysql.createPool({
  host: config.host,
  user: config.username,
  password: config.password,
  database: config.database,
  charset: config.charset || 'utf8mb4',
  waitForConnections: true,
  connectionLimit: 10,
});

(async () => {
  const logFilePath = 'applstats.log';
  let connection;

  try {
    connection = await pool.getConnection();
    const today = dayjs();
    const startDate = today.subtract(7, 'day');
    const endDate = today;

    const [customers] = await connection.execute("SELECT DISTINCT custid FROM applcust");

    for (const { custid } of customers) {
      const [feeds] = await connection.execute("SELECT feedid, numjobs FROM applcustfeeds WHERE custid = ?", [custid]);

      for (const { feedid, numjobs } of feeds) {
        for (let date = startDate; date.isBefore(endDate) || date.isSame(endDate); date = date.add(1, 'day')) {
          const dateStr = date.format('YYYY-MM-DD');

          const [clickStats] = await connection.execute(`
            SELECT COUNT(DISTINCT eventid) AS clicks, SUM(cpc) AS total_cpc
            FROM applevents
            WHERE custid = ? AND feedid = ? AND eventtype = 'cpc' AND DATE(timestamp) = ?
          `, [custid, feedid, dateStr]);

          const [applyStats] = await connection.execute(`
            SELECT COUNT(*) AS applies, SUM(cpa) AS total_cpa
            FROM applevents
            WHERE custid = ? AND feedid = ? AND eventtype = 'cpa' AND DATE(timestamp) = ?
          `, [custid, feedid, dateStr]);

          await connection.execute(`
            INSERT INTO appl_feed_stats (custid, feedid, date, clicks, applies, total_cpc, total_cpa, numjobs)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
              clicks = VALUES(clicks),
              applies = VALUES(applies),
              total_cpc = VALUES(total_cpc),
              total_cpa = VALUES(total_cpa),
              numjobs = VALUES(numjobs)
          `, [
            custid,
            feedid,
            dateStr,
            clickStats[0].clicks || 0,
            applyStats[0].applies || 0,
            clickStats[0].total_cpc || 0.0,
            applyStats[0].total_cpa || 0.0,
            numjobs
          ]);

          console.log(`✅ ${custid} - ${feedid} - ${dateStr}`);
        }
      }
    }

    console.log("✅ Feed stats updated successfully at", new Date().toISOString());
    logMessage("Feed stats 60-day cron completed.", logFilePath);

  } catch (error) {
    console.error("❌ Error in applstats_cron.js:", error);
    logMessage(`Error: ${error.message}`, logFilePath);
  } finally {
    if (connection) connection.release();
    pool.end();
  }
})();
