<?php
/**
 * Secure PDO Database Connection Manager
 * 
 * Supports standard connection parameters or DATABASE_URL strings.
 * Enforces strict error masking, parameterized queries, and UTF-8 encoding.
 */

declare(strict_types=1);

require_once __DIR__ . '/env.php';

/**
 * Parses DATABASE_URL and merges DB_PASSWORD with documented precedence.
 *
 * Precedence Rule:
 * 1. If DB_PASSWORD is explicitly defined in environment / .env, it takes highest precedence.
 * 2. If DB_PASSWORD is not set (null), any password embedded in DATABASE_URL is used.
 * 3. If neither is set, defaults to empty password "" (standard XAMPP default).
 *
 * @return array{host: string, port: int, user: string, pass: string, name: string}
 * @throws InvalidArgumentException When DATABASE_URL is malformed
 */
function parse_database_config(): array
{
    $databaseUrl = env('DATABASE_URL');
    $dbPasswordEnv = env('DB_PASSWORD');

    // Default connection values for XAMPP
    $config = [
        'host' => '127.0.0.1',
        'port' => 3306,
        'user' => 'root',
        'pass' => '',
        'name' => 'parking_management'
    ];

    if (!empty($databaseUrl)) {
        // Fix issues where parse_url might fail on unusual schemes
        $parsed = parse_url($databaseUrl);

        if ($parsed === false) {
            throw new InvalidArgumentException("DATABASE_URL is not a valid URI format.");
        }

        if (!empty($parsed['host'])) {
            $config['host'] = $parsed['host'];
        }

        if (!empty($parsed['port'])) {
            $config['port'] = (int) $parsed['port'];
        }

        if (isset($parsed['user'])) {
            $config['user'] = rawurldecode($parsed['user']);
        }

        if (isset($parsed['pass'])) {
            $config['pass'] = rawurldecode($parsed['pass']);
        }

        if (!empty($parsed['path'])) {
            // Remove leading slash
            $dbName = ltrim($parsed['path'], '/');
            // Remove query params if any
            if (($qPos = strpos($dbName, '?')) !== false) {
                $dbName = substr($dbName, 0, $qPos);
            }
            if ($dbName !== '') {
                $config['name'] = $dbName;
            }
        }
    }

    // Apply DB_PASSWORD precedence:
    // If DB_PASSWORD key is explicitly provided in .env/env, override URL password
    if ($dbPasswordEnv !== null) {
        $config['pass'] = (string) $dbPasswordEnv;
    }

    return $config;
}

/**
 * Establish a PDO connection to MySQL.
 *
 * @param bool $selectDatabase Whether to select the target database or connect to server only
 * @return PDO
 * @throws PDOException
 */
function get_db_connection(bool $selectDatabase = true): PDO
{
    static $dbInstance = null;

    if ($selectDatabase && $dbInstance instanceof PDO) {
        return $dbInstance;
    }

    $config = parse_database_config();

    // DSN construction
    $dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $config['host'], $config['port']);
    if ($selectDatabase && !empty($config['name'])) {
        $dsn .= ';dbname=' . $config['name'];
    }

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_TIMEOUT            => 5
    ];

    if (defined('Pdo\\Mysql::ATTR_INIT_COMMAND')) {
        $options[\Pdo\Mysql::ATTR_INIT_COMMAND] = "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci";
    } elseif (defined('PDO::MYSQL_ATTR_INIT_COMMAND')) {
        $options[PDO::MYSQL_ATTR_INIT_COMMAND] = "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci";
    }

    try {
        $pdo = new PDO($dsn, $config['user'], $config['pass'], $options);
        if ($selectDatabase) {
            $dbInstance = $pdo;
        }
        return $pdo;
    } catch (PDOException $e) {
        // Redact any accidental credential leaks from error message
        $safeMsg = "Database Connection Failed: " . $e->getMessage();
        if (!empty($config['pass'])) {
            $safeMsg = str_replace($config['pass'], '******', $safeMsg);
        }
        error_log($safeMsg);
        throw new PDOException($safeMsg, (int) $e->getCode());
    }
}

/**
 * Helper to check if MySQL server and database schema are ready and operational
 *
 * @return bool
 */
function is_database_installed(): bool
{
    try {
        $pdo = get_db_connection(true);
        // Verify key tables exist
        $stmt = $pdo->query("SHOW TABLES LIKE 'users'");
        return $stmt && $stmt->rowCount() > 0;
    } catch (Throwable) {
        return false;
    }
}

/**
 * Check if the initial administrator account has been created
 *
 * @return bool
 */
function is_admin_created(): bool
{
    try {
        $pdo = get_db_connection(true);
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM users WHERE role = 'ADMIN'");
        $row = $stmt ? $stmt->fetch() : null;
        return $row && (int)$row['count'] > 0;
    } catch (Throwable) {
        return false;
    }
}
