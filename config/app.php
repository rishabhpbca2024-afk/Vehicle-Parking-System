<?php
/**
 * Application Core Configuration & Bootstrapper
 */

declare(strict_types=1);

require_once __DIR__ . '/env.php';

// Configure Environment & Error Reporting
$appEnv = env('APP_ENV', 'development');
if ($appEnv === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    ini_set('log_errors', '1');
    ini_set('error_log', dirname(__DIR__) . '/error.log');
}

// Timezone Setup
$appTimezone = env('APP_TIMEZONE', 'Asia/Kolkata');
date_default_timezone_set($appTimezone);

// Secure Session Configuration
if (session_status() === PHP_SESSION_NONE) {
    $isHttps = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443) ||
        (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
    );

    // Apply strict cookie flags
    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_samesite', 'Lax');

    session_set_cookie_params([
        'lifetime' => 0, // Session cookie expires when browser is closed
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    session_start();
}

/**
 * Detect Application Base URL dynamically
 * Works whether installed at root, XAMPP htdocs subdirectory, or virtual host
 *
 * @param string $path Optional relative path to append
 * @return string Full normalized URL
 */
function base_url(string $path = ''): string
{
    static $detectedBase = null;

    if ($detectedBase === null) {
        $scheme = (
            (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
            (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443) ||
            (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
        ) ? 'https' : 'http';

        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

        // Project root on file system
        $projectRoot = str_replace('\\', '/', dirname(__DIR__));
        
        // Document root on server
        $docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']) : '';
        
        $subDir = '';
        if ($docRoot !== '' && str_starts_with($projectRoot, $docRoot)) {
            $subDir = substr($projectRoot, strlen($docRoot));
        } else {
            // Fallback: examine SCRIPT_NAME to find project folder
            $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
            $scriptDir = dirname($scriptName);
            // If script is in a subdirectory (e.g. /vehicles/entry.php), trim back
            $knownDirs = ['/config', '/dashboard', '/slots', '/vehicles', '/payments', '/rates', '/users', '/reports'];
            foreach ($knownDirs as $kd) {
                if (($pos = strpos($scriptDir, $kd)) !== false) {
                    $scriptDir = substr($scriptDir, 0, $pos);
                    break;
                }
            }
            $subDir = $scriptDir;
        }

        $subDir = trim($subDir, '/');
        $detectedBase = $scheme . '://' . $host . ($subDir !== '' ? '/' . $subDir : '');
    }

    $cleanPath = ltrim($path, '/');
    return $detectedBase . ($cleanPath !== '' ? '/' . $cleanPath : '');
}

/**
 * Shorthand alias for base_url
 */
function url(string $path = ''): string
{
    return base_url($path);
}

/**
 * Asset URL helper
 */
function asset(string $path = ''): string
{
    return base_url('assets/' . ltrim($path, '/'));
}

// Global Application Constants
define('APP_NAME', env('APP_NAME', 'SmartPark - Parking Management System'));
define('CURRENCY_SYMBOL', env('APP_CURRENCY_SYMBOL', '₹'));
define('CURRENCY_CODE', env('APP_CURRENCY_CODE', 'INR'));
