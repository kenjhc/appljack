// require("dotenv").config();
const mysql = require("mysql2/promise");
const fs = require("fs");
const config = require("../config");

const pool = mysql.createPool({
  host: config.host,
  user: config.username,
  password: config.password,
  database: config.database,
  charset: config.charset,
  connectionLimit: 10,
});

const logToDatabase = async (
  logLevel,
  scriptName,
  message,
  logType = "cronjob"
) => {
  logMessage(
    `1. ${config.database} - ${config.username} - ${config.password}`,
    logFilePath
  );
  const error = new Error();
  const stackLine = error.stack.split("\n")[2];
  const lineNumber = stackLine.match(/:(\d+):\d+\)$/)?.[1];

  const connection = await pool.getConnection();
  const scriptTxt = `${lineNumber + ":" ?? "-"} ${scriptName}`;
  try {
    await connection.execute(
      "INSERT INTO appl_logs (log_type, log_level, script_name, message) VALUES (?, ?, ?, ?)",
      [logType, logLevel, scriptTxt, message]
    );
  } catch (err) {
    fs.appendFileSync(
      "appl_logs_errors.log",
      `${new Date().toISOString()} - Failed to log error to database: ${
        err.message
      }\n`,
      "utf8"
    );
  } finally {
    connection.release();
  }
};

const logMessage = (message, logFilePath) => {
  const logMessage = `${new Date().toISOString()} - ${message}\n`;
  fs.appendFileSync(logFilePath, logMessage, "utf8");
};

// Export the functions
module.exports = {
  logToDatabase,
  logMessage,
};
