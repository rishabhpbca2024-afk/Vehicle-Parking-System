<?php
/**
 * Application Entrypoint & Smart Redirector
 */

declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

// Check if database schema exists
if (!is_database_installed()) {
    redirect('install.php');
}

// Check if initial admin account exists
if (!is_admin_created()) {
    redirect('setup-admin.php');
}

// If already authenticated, jump to dashboard
if (is_logged_in()) {
    redirect('dashboard/index.php');
}

// Otherwise prompt for login
redirect('login.php');
