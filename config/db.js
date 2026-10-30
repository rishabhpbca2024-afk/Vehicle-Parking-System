const mysql = require('mysql2/promise');
require('dotenv').config();

const dbUrl = process.env.DATABASE_URL || 'mysql://root@127.0.0.1:3306/parking_management';
const password = process.env.DB_PASSWORD !== undefined ? process.env.DB_PASSWORD : '';

const parsed = new URL(dbUrl);

const pool = mysql.createPool({
    host: parsed.hostname || '127.0.0.1',
    port: parsed.port ? parseInt(parsed.port, 10) : 3306,
    user: parsed.username || 'root',
    password: password || parsed.password || '',
    database: parsed.pathname ? parsed.pathname.replace(/^\//, '') : 'parking_management',
    waitForConnections: true,
    connectionLimit: 15,
    queueLimit: 0,
    decimalNumbers: true
});

module.exports = pool;
