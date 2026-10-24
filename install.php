<?php
/**
 * Automated Database Installer & Migration Wizard
 * 
 * Sets up the database, creates required tables, constraints, indexes,
 * seeds default parking rates, and performs self-verification.
 * Works seamlessly via both Web Browser UI and CLI.
 */

declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';

$isCli = (php_sapi_name() === 'cli');

// Check if already installed with an existing admin
$alreadyInstalled = false;
$adminExists = false;
try {
    if (is_database_installed()) {
        $alreadyInstalled = true;
        if (is_admin_created()) {
            $adminExists = true;
        }
    }
} catch (Throwable) {
    // Database or tables do not exist yet
}

$executionResult = null;
$action = $_POST['action'] ?? ($isCli ? 'install' : null);

// If action requested and admin already exists, lock installer!
if ($action === 'install' && $adminExists) {
    $executionResult = [
        'success' => false,
        'locked'  => true,
        'message' => 'The system has already been initialized and an administrator account exists. Re-installation is locked for security.'
    ];
} elseif ($action === 'install') {
    $steps = [];
    $hasError = false;
    $errorMessage = '';
    $diagnosticHelp = '';

    try {
        $dbConfig = parse_database_config();
        $targetDbName = $dbConfig['name'];

        // Step 1: Validate database identifier safely
        if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $targetDbName)) {
            throw new RuntimeException("Invalid database name '{$targetDbName}'. Only alphanumeric characters, dashes, and underscores are allowed.");
        }

        // Step 2: Attempt connection to MySQL server
        $serverConnected = false;
        $dbCreated = false;
        $dbExistedAlready = false;

        try {
            $serverPdo = get_db_connection(false);
            $serverConnected = true;
            $steps[] = ['title' => 'Server Connection', 'status' => 'success', 'info' => "Connected to MySQL host '{$dbConfig['host']}:{$dbConfig['port']}' as user '{$dbConfig['user']}'."];

            // Try creating the database
            try {
                $serverPdo->exec("CREATE DATABASE IF NOT EXISTS `{$targetDbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                $dbCreated = true;
                $steps[] = ['title' => 'Database Creation', 'status' => 'success', 'info' => "Database `{$targetDbName}` ensured (character set utf8mb4, collation utf8mb4_unicode_ci)."];
            } catch (PDOException $createEx) {
                // Check if user lacks CREATE DATABASE privilege
                $steps[] = [
                    'title'  => 'Database Creation Warning',
                    'status' => 'warning',
                    'info'   => "Could not execute CREATE DATABASE (Error: " . $createEx->getMessage() . "). Attempting to connect directly to `{$targetDbName}` if already created by host..."
                ];
            }
        } catch (PDOException $serverEx) {
            // Some cloud hosts do not permit server-level connection without dbname in DSN
            $steps[] = [
                'title'  => 'Direct Database Connection',
                'status' => 'info',
                'info'   => "Direct server connection without database skipped. Connecting directly to `{$targetDbName}`..."
            ];
        }

        // Step 3: Connect to the target database
        try {
            $targetPdo = get_db_connection(true);
            $steps[] = ['title' => 'Target Database Connection', 'status' => 'success', 'info' => "Successfully connected to target database `{$targetDbName}`."];
        } catch (PDOException $dbEx) {
            $hasError = true;
            $errorMessage = "Unable to connect to database `{$targetDbName}`: " . $dbEx->getMessage();
            $diagnosticHelp = "If your hosting provider does not permit the web application to create databases automatically, please create a MySQL database named '{$targetDbName}' manually via cPanel or phpMyAdmin and grant all privileges to '{$dbConfig['user']}'.";
            throw new RuntimeException($errorMessage);
        }

        // Step 4: Execute Schema DDL
        $schemaFile = __DIR__ . '/database/schema.sql';
        if (!file_exists($schemaFile)) {
            throw new RuntimeException("Schema file database/schema.sql is missing!");
        }

        $sqlContent = file_get_contents($schemaFile);
        if ($sqlContent === false) {
            throw new RuntimeException("Failed to read database/schema.sql");
        }

        // Execute schema queries
        $targetPdo->exec($sqlContent);
        $steps[] = ['title' => 'Schema Installation', 'status' => 'success', 'info' => "Installed tables: users, parking_slots, vehicles, parking_rates, parking_sessions, payments with foreign keys and indexes."];

        // Step 5: Verify table installation and queryability
        $requiredTables = ['users', 'parking_slots', 'vehicles', 'parking_rates', 'parking_sessions', 'payments'];
        $verifiedCount = 0;
        foreach ($requiredTables as $table) {
            $stmt = $targetPdo->query("SHOW TABLES LIKE '{$table}'");
            if ($stmt && $stmt->rowCount() > 0) {
                // Test a simple SELECT to ensure table is queryable
                $targetPdo->query("SELECT 1 FROM `{$table}` LIMIT 1");
                $verifiedCount++;
            } else {
                throw new RuntimeException("Verification failed: Table `{$table}` was not created.");
            }
        }
        $steps[] = ['title' => 'Schema Verification', 'status' => 'success', 'info' => "All {$verifiedCount} required tables verified and queryable."];

        // Step 6: Verify default parking rates
        $rateCountStmt = $targetPdo->query("SELECT COUNT(*) as count FROM `parking_rates`");
        $rateCount = (int) ($rateCountStmt ? $rateCountStmt->fetchColumn() : 0);
        $steps[] = ['title' => 'Default Rates Seed', 'status' => 'success', 'info' => "Verified {$rateCount} vehicle parking rates configured."];

        $executionResult = [
            'success' => true,
            'steps'   => $steps,
            'message' => "Installation completed successfully! You can now proceed to create the initial administrator account."
        ];
    } catch (Throwable $e) {
        $executionResult = [
            'success'        => false,
            'steps'          => $steps ?? [],
            'message'        => $e->getMessage(),
            'diagnosticHelp' => $diagnosticHelp ?? ''
        ];
    }
}

// CLI Output Mode
if ($isCli) {
    echo "\n=== SmartPark Database Installer ===\n\n";
    if ($adminExists) {
        echo "[LOCKED] System is already installed and an administrator exists.\n";
        exit(0);
    }
    if ($executionResult && $executionResult['success']) {
        foreach ($executionResult['steps'] as $s) {
            echo "[" . strtoupper($s['status']) . "] {$s['title']}: {$s['info']}\n";
        }
        echo "\nSUCCESS: " . $executionResult['message'] . "\n";
        echo "Next Step: Open setup-admin.php in your browser to create the administrator account.\n";
        exit(0);
    } else {
        echo "ERROR: " . ($executionResult['message'] ?? 'Unknown error occurred') . "\n";
        if (!empty($executionResult['diagnosticHelp'])) {
            echo "HELP: " . $executionResult['diagnosticHelp'] . "\n";
        }
        exit(1);
    }
}

// Check PHP requirements
$phpVersionOk = version_compare(PHP_VERSION, '8.1.0', '>=');
$pdoAvailable = extension_loaded('pdo');
$pdoMysqlAvailable = extension_loaded('pdo_mysql');
$requirementsMet = $phpVersionOk && $pdoAvailable && $pdoMysqlAvailable;

$dbConfig = parse_database_config();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Setup Wizard - <?= htmlspecialchars(APP_NAME) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        :root {
            --bs-body-font-family: 'Inter', system-ui, sans-serif;
            --primary-gradient: linear-gradient(135deg, #4f46e5 0%, #3b82f6 100%);
            --bg-surface: #0f172a;
        }
        body {
            background-color: #0b0f19;
            color: #f1f5f9;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 1rem;
            font-family: var(--bs-body-font-family);
        }
        .setup-card {
            background: #111827;
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 1.25rem;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
            max-width: 680px;
            width: 100%;
            overflow: hidden;
        }
        .setup-header {
            background: var(--primary-gradient);
            padding: 2.25rem 2rem;
            text-align: center;
            color: #ffffff;
        }
        .config-badge {
            background: #1f2937;
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 0.5rem;
            padding: 0.75rem 1rem;
            font-family: monospace;
            font-size: 0.875rem;
        }
        .step-item {
            background: #1e293b;
            border-left: 4px solid #3b82f6;
            padding: 0.85rem 1.2rem;
            border-radius: 0.5rem;
            margin-bottom: 0.75rem;
        }
        .step-item.success { border-left-color: #10b981; }
        .step-item.warning { border-left-color: #f59e0b; }
        .step-item.error { border-left-color: #ef4444; }
    </style>
</head>
<body>

<div class="setup-card">
    <div class="setup-header">
        <div class="mb-3">
            <i class="bi bi-shield-check display-4"></i>
        </div>
        <h2 class="fw-bold mb-1">Database Installation Wizard</h2>
        <p class="mb-0 text-white-50"><?= htmlspecialchars(APP_NAME) ?></p>
    </div>

    <div class="p-4 p-md-5">
        <?php if ($adminExists): ?>
            <div class="alert alert-info d-flex align-items-center mb-4">
                <i class="bi bi-info-circle-fill fs-3 me-3"></i>
                <div>
                    <h5 class="alert-heading mb-1">Application Already Installed</h5>
                    <p class="mb-0">The database schema has already been initialized and an administrator account exists. For security reasons, re-installation is locked.</p>
                </div>
            </div>
            <div class="d-grid gap-2">
                <a href="login.php" class="btn btn-primary btn-lg"><i class="bi bi-box-arrow-in-right me-2"></i>Go to Login</a>
            </div>

        <?php elseif ($executionResult && $executionResult['success']): ?>
            <div class="alert alert-success d-flex align-items-center mb-4">
                <i class="bi bi-check-circle-fill fs-3 me-3 text-success"></i>
                <div>
                    <h5 class="alert-heading mb-1">Installation Successful!</h5>
                    <p class="mb-0"><?= htmlspecialchars($executionResult['message']) ?></p>
                </div>
            </div>

            <h6 class="fw-bold text-uppercase text-secondary small mb-3">Installation Log:</h6>
            <div class="mb-4">
                <?php foreach ($executionResult['steps'] as $step): ?>
                    <div class="step-item <?= htmlspecialchars($step['status']) ?>">
                        <div class="fw-semibold text-white">
                            <?php if ($step['status'] === 'success'): ?>
                                <i class="bi bi-check-lg text-success me-1"></i>
                            <?php elseif ($step['status'] === 'warning'): ?>
                                <i class="bi bi-exclamation-triangle-fill text-warning me-1"></i>
                            <?php else: ?>
                                <i class="bi bi-info-circle-fill text-info me-1"></i>
                            <?php endif; ?>
                            <?= htmlspecialchars($step['title']) ?>
                        </div>
                        <div class="small text-white-50 mt-1"><?= htmlspecialchars($step['info']) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="d-grid gap-2">
                <a href="setup-admin.php" class="btn btn-success btn-lg">
                    <i class="bi bi-person-plus-fill me-2"></i>Proceed to Create Initial Administrator
                </a>
            </div>

        <?php else: ?>
            <?php if ($executionResult && !$executionResult['success']): ?>
                <div class="alert alert-danger mb-4">
                    <h6 class="alert-heading fw-bold mb-1"><i class="bi bi-x-circle-fill me-2"></i>Setup Failed</h6>
                    <p class="mb-2"><?= htmlspecialchars($executionResult['message']) ?></p>
                    <?php if (!empty($executionResult['diagnosticHelp'])): ?>
                        <hr>
                        <p class="small mb-0"><i class="bi bi-lightbulb me-1"></i><strong>Troubleshooting:</strong> <?= htmlspecialchars($executionResult['diagnosticHelp']) ?></p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- System Checks -->
            <h6 class="fw-bold text-uppercase text-secondary small mb-3">System Prerequisites:</h6>
            <div class="row g-2 mb-4">
                <div class="col-sm-4">
                    <div class="p-3 rounded bg-dark border <?= $phpVersionOk ? 'border-success' : 'border-danger' ?>">
                        <small class="text-secondary d-block">PHP Version</small>
                        <div class="fw-bold text-white"><?= PHP_VERSION ?></div>
                        <small class="<?= $phpVersionOk ? 'text-success' : 'text-danger' ?>">
                            <i class="bi bi-<?= $phpVersionOk ? 'check' : 'x' ?>"></i> <?= $phpVersionOk ? '>= 8.1 Required' : 'Upgrade Required' ?>
                        </small>
                    </div>
                </div>
                <div class="col-sm-4">
                    <div class="p-3 rounded bg-dark border <?= $pdoAvailable ? 'border-success' : 'border-danger' ?>">
                        <small class="text-secondary d-block">PDO Extension</small>
                        <div class="fw-bold text-white"><?= $pdoAvailable ? 'Enabled' : 'Disabled' ?></div>
                        <small class="<?= $pdoAvailable ? 'text-success' : 'text-danger' ?>">
                            <i class="bi bi-<?= $pdoAvailable ? 'check' : 'x' ?>"></i> <?= $pdoAvailable ? 'Ready' : 'Missing' ?>
                        </small>
                    </div>
                </div>
                <div class="col-sm-4">
                    <div class="p-3 rounded bg-dark border <?= $pdoMysqlAvailable ? 'border-success' : 'border-danger' ?>">
                        <small class="text-secondary d-block">PDO MySQL</small>
                        <div class="fw-bold text-white"><?= $pdoMysqlAvailable ? 'Enabled' : 'Disabled' ?></div>
                        <small class="<?= $pdoMysqlAvailable ? 'text-success' : 'text-danger' ?>">
                            <i class="bi bi-<?= $pdoMysqlAvailable ? 'check' : 'x' ?>"></i> <?= $pdoMysqlAvailable ? 'Ready' : 'Missing' ?>
                        </small>
                    </div>
                </div>
            </div>

            <!-- Connection Details Preview -->
            <h6 class="fw-bold text-uppercase text-secondary small mb-2">Configuration Source:</h6>
            <div class="config-badge mb-4">
                <div class="row">
                    <div class="col-4 text-secondary">Host:</div>
                    <div class="col-8 text-white"><?= htmlspecialchars($dbConfig['host']) ?>:<?= htmlspecialchars((string)$dbConfig['port']) ?></div>
                </div>
                <div class="row mt-1">
                    <div class="col-4 text-secondary">Database:</div>
                    <div class="col-8 text-white fw-bold text-info"><?= htmlspecialchars($dbConfig['name']) ?></div>
                </div>
                <div class="row mt-1">
                    <div class="col-4 text-secondary">Username:</div>
                    <div class="col-8 text-white"><?= htmlspecialchars($dbConfig['user']) ?></div>
                </div>
                <div class="row mt-1">
                    <div class="col-4 text-secondary">Password:</div>
                    <div class="col-8 text-white"><?= empty($dbConfig['pass']) ? '<span class="text-muted">(None / Empty)</span>' : '••••••••' ?></div>
                </div>
            </div>

            <div class="alert alert-warning small mb-4">
                <i class="bi bi-info-circle me-1"></i> The installer will automatically create the database if permissions allow, build all required tables, setup relationships, and initialize default parking rates safely.
            </div>

            <form method="POST">
                <input type="hidden" name="action" value="install">
                <div class="d-grid gap-2">
                    <button type="submit" class="btn btn-primary btn-lg" <?= !$requirementsMet ? 'disabled' : '' ?>>
                        <i class="bi bi-play-circle-fill me-2"></i>Run Automated Database Setup
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
