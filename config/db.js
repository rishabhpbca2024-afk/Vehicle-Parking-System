const mysql = require('mysql2/promise');
require('dotenv').config();

const dbHost = process.env.DB_HOST || '127.0.0.1';
const dbPort = process.env.DB_PORT ? parseInt(process.env.DB_PORT, 10) : 3306;
const dbUser = process.env.DB_USER || 'root';
const dbPassword = process.env.DB_PASSWORD !== undefined ? process.env.DB_PASSWORD : '';
const dbName = process.env.DB_NAME || 'parking_management';

// Check if using DATABASE_URL or individual credentials
let poolConfig = {
    host: dbHost,
    port: dbPort,
    user: dbUser,
    password: dbPassword,
    database: dbName,
    waitForConnections: true,
    connectionLimit: 15,
    queueLimit: 0,
    decimalNumbers: true
};

if (process.env.DATABASE_URL) {
    try {
        const parsed = new URL(process.env.DATABASE_URL);
        poolConfig.host = parsed.hostname || poolConfig.host;
        poolConfig.port = parsed.port ? parseInt(parsed.port, 10) : poolConfig.port;
        poolConfig.user = parsed.username || poolConfig.user;
        poolConfig.password = parsed.password || poolConfig.password;
        poolConfig.database = parsed.pathname ? parsed.pathname.replace(/^\//, '') : poolConfig.database;
    } catch (e) {
        console.warn('DATABASE_URL parse warning, using standard credentials.');
    }
}

// Enable SSL if running on cloud or explicitly requested (required for TiDB / Aiven / PlanetScale)
if (process.env.DB_SSL === 'true' || (process.env.NODE_ENV === 'production' && poolConfig.host !== '127.0.0.1' && poolConfig.host !== 'localhost')) {
    poolConfig.ssl = { rejectUnauthorized: false };
}

const pool = mysql.createPool(poolConfig);

module.exports = pool;

