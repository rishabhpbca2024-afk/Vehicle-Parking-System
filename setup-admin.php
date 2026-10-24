<?php
/**
 * Initial First-Time Administrator Account Setup
 * 
 * Automatically locks once an administrator account has been provisioned.
 */

declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/functions.php';

// Verify that the database schema has been installed first
if (!is_database_installed()) {
    redirect('install.php');
}

// Check if an Administrator account already exists
if (is_admin_created()) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Setup Locked - <?= htmlspecialchars(APP_NAME) ?></title>
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
            .card-locked {
                background: #111827;
                border: 1px solid rgba(255, 255, 255, 0.1);
                border-radius: 1.25rem;
                max-width: 540px;
                width: 100%;
                padding: 2.5rem;
                text-align: center;
                box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
            }
        </style>
    </head>
    <body>
        <div class="card-locked">
            <div class="text-warning mb-3">
                <i class="bi bi-lock-fill display-3"></i>
            </div>
            <h3 class="fw-bold mb-2">First-Time Setup Locked</h3>
            <p class="text-white-50 mb-4">
                An administrator account has already been initialized on this installation. For security reasons, the initial setup utility is permanently locked.
            </p>
            <div class="d-grid gap-2">
                <a href="login.php" class="btn btn-primary btn-lg">
                    <i class="bi bi-box-arrow-in-right me-2"></i>Go to Login
                </a>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

$errors = [];
$name = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_die();

    $name = sanitize($_POST['name'] ?? '');
    $email = sanitize($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    // Validation
    if ($name === '' || strlen($name) > 100) {
        $errors[] = 'Full name is required and must not exceed 100 characters.';
    }

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150) {
        $errors[] = 'A valid email address is required (maximum 150 characters).';
    }

    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters long.';
    }

    if ($password !== $confirmPassword) {
        $errors[] = 'Password confirmation does not match.';
    }

    if (empty($errors)) {
        try {
            $pdo = get_db_connection(true);

            // Double check if admin was added concurrently
            $checkStmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'ADMIN'");
            if ((int)$checkStmt->fetchColumn() > 0) {
                $errors[] = 'An administrator account has already been created.';
            } else {
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);

                $insertStmt = $pdo->prepare("
                    INSERT INTO users (name, email, password_hash, role, is_active, created_at)
                    VALUES (:name, :email, :password_hash, 'ADMIN', 1, NOW())
                ");

                $insertStmt->execute([
                    ':name'          => $name,
                    ':email'         => $email,
                    ':password_hash' => $passwordHash
                ]);

                set_flash('success', 'Administrator account created successfully! You can now log in.');
                redirect('login.php');
            }
        } catch (PDOException $e) {
            if ($e->getCode() == 23000 || str_contains($e->getMessage(), 'Duplicate entry')) {
                $errors[] = 'This email address is already registered.';
            } else {
                $errors[] = 'Failed to create administrator account: ' . $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Administrator - <?= htmlspecialchars(APP_NAME) ?></title>
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
            padding: 2rem 1rem;
        }
        .admin-card {
            background: #111827;
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 1.25rem;
            max-width: 560px;
            width: 100%;
            overflow: hidden;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
        }
        .admin-header {
            background: linear-gradient(135deg, #4f46e5 0%, #3b82f6 100%);
            padding: 2rem;
            text-align: center;
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

<div class="admin-card">
    <div class="admin-header text-white">
        <i class="bi bi-person-fill-gear display-4 mb-2"></i>
        <h3 class="fw-bold mb-1">Create Administrator</h3>
        <p class="mb-0 text-white-50">Set up your initial system administrator account</p>
    </div>

    <div class="p-4 p-md-5">
        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger mb-4">
                <div class="fw-bold mb-1"><i class="bi bi-exclamation-triangle-fill me-2"></i>Please resolve the following:</div>
                <ul class="mb-0 ps-3 small">
                    <?php foreach ($errors as $error): ?>
                        <li><?= htmlspecialchars($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="POST" autocomplete="off">
            <?= csrf_field() ?>

            <div class="mb-3">
                <label for="name" class="form-label">Administrator Full Name</label>
                <div class="input-group">
                    <span class="input-group-text bg-dark border-secondary text-secondary"><i class="bi bi-person"></i></span>
                    <input type="text" class="form-control" id="name" name="name" required
                           placeholder="e.g. System Administrator" value="<?= e($name) ?>">
                </div>
            </div>

            <div class="mb-3">
                <label for="email" class="form-label">Email Address (Login Username)</label>
                <div class="input-group">
                    <span class="input-group-text bg-dark border-secondary text-secondary"><i class="bi bi-envelope"></i></span>
                    <input type="email" class="form-control" id="email" name="email" required
                           placeholder="admin@smartpark.local" value="<?= e($email) ?>">
                </div>
            </div>

            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <label for="password" class="form-label">Password</label>
                    <div class="input-group">
                        <span class="input-group-text bg-dark border-secondary text-secondary"><i class="bi bi-key"></i></span>
                        <input type="password" class="form-control" id="password" name="password" required
                               placeholder="Min. 8 characters">
                    </div>
                </div>
                <div class="col-md-6">
                    <label for="confirm_password" class="form-label">Confirm Password</label>
                    <div class="input-group">
                        <span class="input-group-text bg-dark border-secondary text-secondary"><i class="bi bi-key-fill"></i></span>
                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" required
                               placeholder="Re-type password">
                    </div>
                </div>
            </div>

            <div class="alert alert-secondary small mb-4 bg-dark border-secondary text-light">
                <i class="bi bi-shield-lock-fill text-info me-1"></i> Passwords are protected using secure PHP <code>password_hash()</code> encryption. Once created, this page will be disabled.
            </div>

            <div class="d-grid gap-2">
                <button type="submit" class="btn btn-primary btn-lg">
                    <i class="bi bi-check-lg me-2"></i>Create Administrator Account
                </button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
