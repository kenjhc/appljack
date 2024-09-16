const dotenv = require("dotenv");
const path = require("path");

// Load .env file from the current directory
dotenv.config({ path: path.resolve(process.cwd(), ".env") });

// Access your environment variables like this
const config = {
  host: process.env.DB_HOST || "localhost",
  database: process.env.DB_DATABASE || "appljack",
  username: process.env.DB_USERNAME || "root",
  password: process.env.DB_PASSWORD || "",
  charset: process.env.DB_CHARSET || "utf8mb4"
};

module.exports = config;