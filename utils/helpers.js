const dotenv = require("dotenv");
const mysql = require("mysql2/promise");
const fs = require("fs");
const path = require("path");

dotenv.config({ path: path.resolve(__dirname, "..", ".env") });

const pool = mysql.createPool({
  host: process.env.DB_HOST,
  database: process.env.DB_DATABASE,
  user: process.env.DB_USERNAME,
  password: process.env.DB_PASSWORD,
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

  const scriptTxt = `${scriptName}`;
  try { 

    const connection = await pool.getConnection();
    await connection.execute(
      "INSERT INTO appl_logs (log_type, log_level, script_name, message, line_number) VALUES (?, ?, ?, ?, ?)",
      [logType, logLevel, scriptTxt, message, lineNumber]
    );
  } catch (err) {
    const logFilePath = path.resolve(__dirname, "..", "appl_logs_errors.log");

    // Ensure the file exists, or create it if it doesn't
    if (!fs.existsSync(logFilePath)) {
      fs.writeFileSync(logFilePath, "", { flag: "w" }); // Create an empty file if it doesn't exist
    }

    fs.appendFileSync(
      logFilePath,
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

  try {
    const resolvedLogFilePath = path.resolve(__dirname, "..", logFilePath);

    // Ensure the file exists, or create it if it doesn't
    if (!fs.existsSync(resolvedLogFilePath)) {
      fs.writeFileSync(resolvedLogFilePath, "", { flag: "w" }); // Create an empty file if it doesn't exist
    }

    fs.appendFileSync(resolvedLogFilePath, logMessage, "utf8");
  } catch (err) {
    logToDatabase(
      "error",
      logFilePath,
      `Error during storing log message to file: ${err.message}`
    );
    console.log("Error during storing log message to file: ", err.message);
  }
};

// Export the functions
module.exports = {
  logToDatabase,
  logMessage,
};
