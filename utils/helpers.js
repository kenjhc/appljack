require("dotenv").config();
const mysql = require("mysql2/promise");
const fs = require("fs");

const dbConfig = {
  host: process.env.DB_HOST,
  user: process.env.DB_USERNAME,
  password: process.env.DB_PASSWORD,
  database: process.env.DB_DATABASE,
  charset: process.env.DB_CHARSET,
}; 

const logToDatabase = async (
  logLevel,
  scriptName,
  message,
  logType = "cronjob"
) => {
  const error = new Error();
  const stackLine = error.stack.split("\n")[2]; // Get the third line from the stack trace
  const lineNumber = stackLine.match(/:(\d+):\d+\)$/)?.[1]; // Extract the line number
  
  const connection = await mysql.createConnection(dbConfig);
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
    await connection.end();
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
