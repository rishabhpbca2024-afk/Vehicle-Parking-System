<?php
/**
 * User Logout Script
 */

declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

logout_user();

set_flash('info', 'You have been logged out successfully.');
redirect('login.php');
