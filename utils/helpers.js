require("dotenv").config();
const mysql = require("mysql2/promise");
const fs = require("fs");

const pool = mysql.createPool({
  host: process.env.DB_HOST,
  user: process.env.DB_USERNAME,
  password: process.env.DB_PASSWORD,
  database: process.env.DB_DATABASE,
  charset: process.env.DB_CHARSET,
  connectionLimit: 10,  
});

const logToDatabase = async (
  logLevel,
  scriptName,
  message,
  logType = "cronjob"
) => {
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
