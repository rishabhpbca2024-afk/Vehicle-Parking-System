<?php
/**
 * Friendly System & Database Error Page
 */

declare(strict_types=1);

require_once __DIR__ . '/config/app.php';

$errorCode = $_GET['code'] ?? '500';
$errorTitle = 'Database or System Error';
$errorMessage = 'A database connection or system error occurred while processing your request.';

if ($errorCode === '403') {
    $errorTitle = 'Access Forbidden';
    $errorMessage = 'You do not have the required permissions to view this resource.';
} elseif ($errorCode === '404') {
    $errorTitle = 'Page Not Found';
    $errorMessage = 'The requested page or parking record could not be found.';
} elseif ($errorCode === 'db') {
    $errorTitle = 'Database Connection Unavailable';
    $errorMessage = 'Unable to establish a connection with the MySQL database server. Please ensure that MySQL is running in your XAMPP Control Panel and that your .env settings are correct.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($errorTitle) ?> — <?= htmlspecialchars(APP_NAME) ?></title>
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
        .error-card {
            background: #111827;
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 1.25rem;
            max-width: 580px;
            width: 100%;
            padding: 2.5rem;
            text-align: center;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
        }
    </style>
</head>
<body>

<div class="error-card">
    <div class="text-danger mb-3">
        <i class="bi bi-exclamation-triangle-fill display-3"></i>
    </div>
    <h3 class="fw-bold mb-2 text-white"><?= htmlspecialchars($errorTitle) ?></h3>
    <p class="text-secondary mb-4"><?= htmlspecialchars($errorMessage) ?></p>

    <div class="p-3 bg-dark rounded border border-secondary text-start mb-4 small text-secondary">
        <div class="fw-semibold text-white mb-1"><i class="bi bi-wrench-adjustable me-1"></i>Troubleshooting Checklist:</div>
        <ul class="mb-0 ps-3">
            <li>Ensure MySQL service is started in XAMPP / MariaDB.</li>
            <li>Verify your <code>.env</code> file has valid <code>DATABASE_URL</code> and <code>DB_PASSWORD</code>.</li>
            <li>Run the automated installer at <a href="<?= url('install.php') ?>" class="text-info text-decoration-none">install.php</a> if setting up for the first time.</li>
        </ul>
    </div>

    <div class="d-flex justify-content-center gap-2">
        <a href="<?= url('install.php') ?>" class="btn btn-outline-info btn-sm">
            <i class="bi bi-tools me-1"></i>Run Installer
        </a>
        <a href="<?= url('index.php') ?>" class="btn btn-primary btn-sm">
            <i class="bi bi-arrow-repeat me-1"></i>Try Again
        </a>
    </div>
</div>

</body>
</html>
