const dotenv = require("dotenv");
const path = require("path");

const currentPath = __dirname;

// Load .env file from the current directory
dotenv.config({ path: path.resolve(currentPath, ".env") });

const getEnvPath = () => {
  if (currentPath.includes("admin")) {
    return "/admin/"; // If directory includes 'admin'
  } else if (currentPath.includes("dev")) {
    return "/"; // If directory includes 'dev'
  } else if (currentPath.includes("appljack")) {
    return "/"; // Root of the appljack project
  } else {
    return "unknown"; // If none match
  }
};

// Access your environment variables like this
const config = {
  host: process.env.DB_HOST || "localhost",
  database: process.env.DB_DATABASE || "appljack",
  username: process.env.DB_USERNAME || "root",
  password: process.env.DB_PASSWORD || "",
  charset: process.env.DB_CHARSET || "utf8mb4",
  envPath: getEnvPath(),
};

module.exports = config;
