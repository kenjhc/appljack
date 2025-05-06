/**
 * applstats_cron.js – Optimized 60-day feed stats updater
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
    const startDate = today.subtract(1, 'day');
    const endDate = today;

    const [customers] = await connection.execute("SELECT DISTINCT custid FROM applcust");

    for (const { custid } of customers) {
      // Fetch all feeds in one go
      const [feeds] = await connection.execute(
        "SELECT feedid, numjobs FROM applcustfeeds WHERE custid = ?",
        [custid]
      );

      // Build a mapping for feedid -> numjobs
      const feedJobsMap = {};
      feeds.forEach(({ feedid, numjobs }) => {
        feedJobsMap[feedid] = numjobs;
      });

      for (let date = startDate; date.isBefore(endDate) || date.isSame(endDate); date = date.add(1, 'day')) {
        const dateStr = date.format('YYYY-MM-DD');
        const startDateTime = `${dateStr} 00:00:00`;
        const endDateTime = `${dateStr} 23:59:59`;

        // Fetch ALL event stats in one query, grouped by feedid + eventtype
        const [statsRows] = await connection.execute(`
          SELECT feedid, eventtype,
            COUNT(DISTINCT eventid) AS event_count,
            SUM(cpc) AS total_cpc,
            SUM(cpa) AS total_cpa
          FROM applevents
          WHERE custid = ? AND timestamp >= ? AND timestamp <= ?
          GROUP BY feedid, eventtype
        `, [custid, startDateTime, endDateTime]);

        // Build a structure to hold stats per feedid
        const feedStatsMap = {};

        // Initialize all feeds to 0
        for (const { feedid } of feeds) {
          feedStatsMap[feedid] = {
            clicks: 0,
            applies: 0,
            total_cpc: 0.0,
            total_cpa: 0.0,
            numjobs: feedJobsMap[feedid] || 0,
          };
        }

        // Populate stats from query results
        statsRows.forEach(row => {
          if (!feedStatsMap[row.feedid]) {
            // In case a feedid appears in events but not in applcustfeeds
            feedStatsMap[row.feedid] = {
              clicks: 0,
              applies: 0,
              total_cpc: 0.0,
              total_cpa: 0.0,
              numjobs: 0,
            };
          }

          if (row.eventtype === 'cpc') {
            feedStatsMap[row.feedid].clicks = row.event_count || 0;
            feedStatsMap[row.feedid].total_cpc = row.total_cpc || 0.0;
          } else if (row.eventtype === 'cpa') {
            feedStatsMap[row.feedid].applies = row.event_count || 0;
            feedStatsMap[row.feedid].total_cpa = row.total_cpa || 0.0;
          }
        });

        // Prepare bulk insert values
        const insertValues = [];
        for (const [feedid, stats] of Object.entries(feedStatsMap)) {
          insertValues.push([
            custid,
            feedid,
            dateStr,
            stats.clicks,
            stats.applies,
            stats.total_cpc,
            stats.total_cpa,
            stats.numjobs
          ]);
        }

        if (insertValues.length > 0) {
          await connection.query(`
            INSERT INTO appl_feed_stats
              (custid, feedid, date, clicks, applies, total_cpc, total_cpa, numjobs)
            VALUES ?
            ON DUPLICATE KEY UPDATE
              clicks = VALUES(clicks),
              applies = VALUES(applies),
              total_cpc = VALUES(total_cpc),
              total_cpa = VALUES(total_cpa),
              numjobs = VALUES(numjobs)
          `, [insertValues]);

          console.log(`✅ ${custid} - All feeds - ${dateStr} (${insertValues.length} rows updated)`);
        } else {
          console.log(`ℹ️ No feeds to update for ${custid} on ${dateStr}`);
        }
      }
    }

    console.log("✅ Feed stats updated successfully at", new Date().toISOString());
    logMessage("Feed stats optimized cron completed.", logFilePath);

  } catch (error) {
    console.error("❌ Error in applstats_cron.js:", error);
    logMessage(`Error: ${error.message}`, logFilePath);
  } finally {
    if (connection) connection.release();
    pool.end();
  }
})();
