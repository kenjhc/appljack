/**
 * applstats_smart_backfill.js – Intelligent 30-day window stats filler
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
  const logFilePath = 'applstats_backfill.log';
  let connection;

  try {
    connection = await pool.getConnection();
    const windowStart = dayjs().subtract(30, 'day');
    const windowEnd = dayjs().subtract(1, 'day'); // exclude today

    const [customers] = await connection.execute("SELECT DISTINCT custid FROM applcust");

    for (const { custid } of customers) {
      const [feeds] = await connection.execute("SELECT feedid, numjobs FROM applcustfeeds WHERE custid = ?", [custid]);

      for (const { feedid, numjobs } of feeds) {
        for (
          let date = windowStart;
          date.isBefore(windowEnd) || date.isSame(windowEnd);
          date = date.add(1, 'day')
        ) {
          const dateStr = date.format('YYYY-MM-DD');

          // Skip if already exists
          const [exists] = await connection.execute(
            "SELECT 1 FROM appl_feed_stats WHERE custid = ? AND feedid = ? AND date = ? LIMIT 1",
            [custid, feedid, dateStr]
          );

          if (exists.length > 0) {
            continue; // already filled, skip it
          }

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

          console.log(`✅ Inserted: ${custid} - ${feedid} - ${dateStr}`);
        }
      }
    }

    logMessage("Smart backfill completed successfully.", logFilePath);
    console.log("✅ Smart backfill completed at", new Date().toISOString());

  } catch (error) {
    logMessage(`Error in smart backfill: ${error.message}`, logFilePath);
    console.error("❌ Error in smart backfill cron:", error);
  } finally {
    if (connection) connection.release();
    pool.end();
  }
})();
