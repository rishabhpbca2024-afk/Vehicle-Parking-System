<?php
/**
 * User Authentication Login Page
 */

declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

// First-run installer check
if (!is_database_installed()) {
    redirect('install.php');
}

// First-time admin creation check
if (!is_admin_created()) {
    redirect('setup-admin.php');
}

// Redirect if already authenticated
if (is_logged_in()) {
    redirect('dashboard/index.php');
}

$error = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_die();

    $email = sanitize($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        $error = 'Please enter both your email address and password.';
    } else {
        try {
            $pdo = get_db_connection(true);
            $stmt = $pdo->prepare("
                SELECT id, name, email, password_hash, role, is_active 
                FROM users 
                WHERE email = :email 
                LIMIT 1
            ");
            $stmt->execute([':email' => $email]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password_hash'])) {
                if ((int)$user['is_active'] !== 1) {
                    $error = 'Your account has been deactivated. Please contact an administrator.';
                } else {
                    login_user($user);
                    set_flash('success', "Welcome back, " . htmlspecialchars($user['name']) . "!");
                    redirect('dashboard/index.php');
                }
            } else {
                $error = 'Invalid email address or password.';
            }
        } catch (Throwable $e) {
            $error = 'An error occurred while signing in. Please try again later.';
            error_log("Login error: " . $e->getMessage());
        }
    }
}

$flashMessages = get_flash_messages();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In - <?= htmlspecialchars(APP_NAME) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #0b0f19;
            color: #f1f5f9;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Inter', sans-serif;
            padding: 1.5rem;
        }
        .login-card {
            background: #111827;
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 1.25rem;
            max-width: 460px;
            width: 100%;
            overflow: hidden;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.6);
        }
        .login-header {
            background: linear-gradient(135deg, #4f46e5 0%, #3b82f6 100%);
            padding: 2.25rem 2rem;
            text-align: center;
            color: #ffffff;
        }
        .brand-icon {
            width: 56px;
            height: 56px;
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(8px);
            border-radius: 14px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 1rem;
            font-size: 1.75rem;
        }
        .form-control {
            background-color: #1e293b;
            border-color: rgba(255, 255, 255, 0.12);
            color: #ffffff;
            padding: 0.75rem 1rem;
        }
        .form-control:focus {
            background-color: #1e293b;
            border-color: #6366f1;
            color: #ffffff;
            box-shadow: 0 0 0 0.25rem rgba(99, 102, 241, 0.25);
        }
        .form-label {
            font-size: 0.875rem;
            font-weight: 500;
            color: #94a3b8;
        }
    </style>
</head>
<body>

<div class="login-card">
    <div class="login-header">
        <div class="brand-icon">
            <i class="bi bi-p-square-fill"></i>
        </div>
        <h3 class="fw-bold mb-1">SmartPark</h3>
        <p class="mb-0 text-white-50 small">Vehicle Parking Management System</p>
    </div>

    <div class="p-4 p-md-5">
        <?php foreach ($flashMessages as $flash): ?>
            <div class="alert alert-<?= htmlspecialchars($flash['type']) ?> alert-dismissible fade show small" role="alert">
                <?= htmlspecialchars($flash['message']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endforeach; ?>

        <?php if ($error !== ''): ?>
            <div class="alert alert-danger d-flex align-items-center small mb-4" role="alert">
                <i class="bi bi-exclamation-octagon-fill me-2 fs-5"></i>
                <div><?= htmlspecialchars($error) ?></div>
            </div>
        <?php endif; ?>

        <form method="POST" autocomplete="off">
            <?= csrf_field() ?>

            <div class="mb-3">
                <label for="email" class="form-label">Email Address</label>
                <div class="input-group">
                    <span class="input-group-text bg-dark border-secondary text-secondary"><i class="bi bi-envelope"></i></span>
                    <input type="email" class="form-control" id="email" name="email" required autofocus
                           placeholder="user@smartpark.local" value="<?= e($email) ?>">
                </div>
            </div>

            <div class="mb-4">
                <label for="password" class="form-label">Password</label>
                <div class="input-group">
                    <span class="input-group-text bg-dark border-secondary text-secondary"><i class="bi bi-lock"></i></span>
                    <input type="password" class="form-control" id="password" name="password" required
                           placeholder="Enter your password">
                </div>
            </div>

            <div class="d-grid gap-2">
                <button type="submit" class="btn btn-primary btn-lg fw-semibold">
                    <i class="bi bi-box-arrow-in-right me-2"></i>Sign In
                </button>
            </div>
        </form>

        <div class="mt-4 pt-3 text-center border-top border-secondary-subtle">
            <small class="text-secondary">
                Protected parking operations console &copy; <?= date('Y') ?>
            </small>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
