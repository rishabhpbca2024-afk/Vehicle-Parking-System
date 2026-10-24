<?php
/**
 * Authentication and Role-Based Access Control Guards
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/functions.php';

/**
 * Check if a valid user session is active
 */
function is_logged_in(): bool
{
    return !empty($_SESSION['user_id']) && !empty($_SESSION['user_role']);
}

/**
 * Get active user details array from session
 */
function current_user(): ?array
{
    if (!is_logged_in()) {
        return null;
    }
    return [
        'id'    => (int) $_SESSION['user_id'],
        'name'  => (string) $_SESSION['user_name'],
        'email' => (string) $_SESSION['user_email'],
        'role'  => (string) $_SESSION['user_role']
    ];
}

/**
 * Check if the active user is an Administrator
 */
function is_admin(): bool
{
    return is_logged_in() && ($_SESSION['user_role'] === 'ADMIN');
}

/**
 * Check if the active user is an Operator
 */
function is_operator(): bool
{
    return is_logged_in() && ($_SESSION['user_role'] === 'OPERATOR');
}

/**
 * Guard: Enforce authenticated session
 */
function require_login(): void
{
    if (!is_logged_in()) {
        set_flash('warning', 'Please log in to access this page.');
        redirect('login.php');
    }
}

/**
 * Guard: Enforce Administrator privilege
 */
function require_admin(): void
{
    require_login();
    if (!is_admin()) {
        http_response_code(403);
        set_flash('danger', 'Access denied. Administrator privileges are required.');
        redirect('dashboard/index.php');
    }
}

/**
 * Guard: Enforce Operator or Administrator privilege
 */
function require_operator_or_admin(): void
{
    require_login();
    if (!is_admin() && !is_operator()) {
        http_response_code(403);
        set_flash('danger', 'Access denied. Authorized personnel only.');
        redirect('login.php');
    }
}

/**
 * Log user in and regenerate session ID to thwart session fixation attacks
 */
function login_user(array $user): void
{
    // Prevent session fixation
    session_regenerate_id(true);

    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_name'] = $user['name'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['user_role'] = $user['role'];
    $_SESSION['logged_at'] = time();
}

/**
 * Safely destroy user session
 */
function logout_user(): void
{
    $_SESSION = [];

    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params["path"],
            $params["domain"],
            $params["secure"],
            $params["httponly"]
        );
    }

    session_destroy();
}
