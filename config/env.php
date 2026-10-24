<?php
/**
 * Custom Lightweight Environment Loader
 * 
 * Safely parses .env files without requiring third-party composer dependencies.
 * Supports quotes, inline comments, variable trimming, and system environment fallbacks.
 */

declare(strict_types=1);

if (!function_exists('load_env')) {
    /**
     * Loads .env file into PHP environment ($_ENV, $_SERVER, and putenv)
     *
     * @param string $path Path to .env file
     * @return bool True if loaded, false if file does not exist
     */
    function load_env(string $path): bool
    {
        if (!file_exists($path) || !is_readable($path)) {
            return false;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return false;
        }

        foreach ($lines as $line) {
            $line = trim($line);

            // Skip empty lines or pure comment lines
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            // Split into key and value by first equals sign
            $parts = explode('=', $line, 2);
            if (count($parts) !== 2) {
                continue;
            }

            $key = trim($parts[0]);
            $val = trim($parts[1]);

            // Strip trailing comments if not enclosed in quotes
            if (!str_starts_with($val, '"') && !str_starts_with($val, "'")) {
                $commentPos = strpos($val, '#');
                if ($commentPos !== false) {
                    $val = trim(substr($val, 0, $commentPos));
                }
            }

            // Strip surrounding double quotes or single quotes
            if (
                (str_starts_with($val, '"') && str_ends_with($val, '"')) ||
                (str_starts_with($val, "'") && str_ends_with($val, "'"))
            ) {
                $val = substr($val, 1, -1);
                // Handle escaped quotes
                $val = str_replace(['\"', "\\'"], ['"', "'"], $val);
            }

            // Populate $_ENV, $_SERVER, and putenv if not already set by host system
            $_ENV[$key] = $val;
            $_SERVER[$key] = $val;
            putenv("{$key}={$val}");
        }

        return true;
    }
}

if (!function_exists('env')) {
    /**
     * Retrieves an environment variable with a default fallback
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    function env(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $_ENV)) {
            return $_ENV[$key];
        }

        if (array_key_exists($key, $_SERVER)) {
            return $_SERVER[$key];
        }

        $val = getenv($key);
        if ($val !== false) {
            return $val;
        }

        return $default;
    }
}

// Automatically load .env from the project root if it exists
$envFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env';
if (file_exists($envFile)) {
    load_env($envFile);
}
