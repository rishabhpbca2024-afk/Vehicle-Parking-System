<?php
/**
 * User Profile & Password Management
 */

declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/functions.php';

require_login();

$pdo = get_db_connection(true);
$user = current_user();

$userId = (int)$user['id'];

// Fetch latest user details from DB
$stmt = $pdo->prepare("SELECT id, name, email, role, is_active, created_at, updated_at FROM users WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $userId]);
$profile = $stmt->fetch();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_die();

    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        $name = sanitize($_POST['name'] ?? '');

        if ($name === '' || strlen($name) > 100) {
            $errors[] = 'Full name is required and cannot exceed 100 characters.';
        } else {
            try {
                $upStmt = $pdo->prepare("UPDATE users SET name = :name, updated_at = NOW() WHERE id = :id");
                $upStmt->execute([':name' => $name, ':id' => $userId]);

                $_SESSION['user_name'] = $name;
                set_flash('success', 'Profile information updated successfully.');
                redirect('profile.php');
            } catch (PDOException $e) {
                error_log("Failed to update profile: " . $e->getMessage());
                $errors[] = 'Database error while saving profile updates.';
            }
        }
    } elseif ($action === 'change_password') {
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        // Fetch current password hash
        $pwdStmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = :id LIMIT 1");
        $pwdStmt->execute([':id' => $userId]);
        $currentHash = (string)$pwdStmt->fetchColumn();

        if (!password_verify($currentPassword, $currentHash)) {
            $errors[] = 'Your current password was entered incorrectly.';
        } elseif (strlen($newPassword) < 8) {
            $errors[] = 'New password must be at least 8 characters long.';
        } elseif ($newPassword !== $confirmPassword) {
            $errors[] = 'New password confirmation does not match.';
        } else {
            try {
                $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
                $upStmt = $pdo->prepare("UPDATE users SET password_hash = :hash, updated_at = NOW() WHERE id = :id");
                $upStmt->execute([':hash' => $newHash, ':id' => $userId]);

                set_flash('success', 'Password changed successfully.');
                redirect('profile.php');
            } catch (PDOException $e) {
                error_log("Failed to update password: " . $e->getMessage());
                $errors[] = 'Database error while updating password.';
            }
        }
    }
}

$pageTitle = 'My Profile & Security';
$activeNav = '';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
require_once __DIR__ . '/includes/navbar.php';
?>

<div class="row g-4 justify-content-center">
    <div class="col-lg-5 col-md-6">
        <!-- Profile Information Card -->
        <div class="card-modern">
            <div class="card-modern-header">
                <div>
                    <h5 class="fw-bold text-white mb-0"><i class="bi bi-person-circle me-2 text-primary"></i>Profile Details</h5>
                    <small class="text-secondary">Your account identification</small>
                </div>
                <span class="badge <?= is_admin() ? 'bg-danger-subtle text-danger' : 'bg-primary-subtle text-primary' ?>">
                    <?= is_admin() ? 'ADMIN' : 'OPERATOR' ?>
                </span>
            </div>

            <div class="card-modern-body">
                <?php if (!empty($errors) && ($_POST['action'] ?? '') === 'update_profile'): ?>
                    <div class="alert alert-danger small mb-3">
                        <ul class="mb-0 ps-3">
                            <?php foreach ($errors as $err): ?>
                                <li><?= htmlspecialchars($err) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update_profile">

                    <div class="mb-3">
                        <label class="form-label">Full Name</label>
                        <input type="text" class="form-control" name="name" required value="<?= e($profile['name']) ?>">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Email Address (Read-only)</label>
                        <input type="email" class="form-control bg-dark opacity-75" value="<?= e($profile['email']) ?>" readonly>
                        <small class="text-secondary">Email changes require administrative action.</small>
                    </div>

                    <div class="mb-4">
                        <label class="form-label">Account Created</label>
                        <input type="text" class="form-control bg-dark opacity-75" value="<?= format_datetime($profile['created_at']) ?>" readonly>
                    </div>

                    <button type="submit" class="btn btn-primary w-100"><i class="bi bi-save me-1"></i>Save Profile</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-5 col-md-6">
        <!-- Change Password Card -->
        <div class="card-modern">
            <div class="card-modern-header">
                <div>
                    <h5 class="fw-bold text-white mb-0"><i class="bi bi-key-fill me-2 text-warning"></i>Change Password</h5>
                    <small class="text-secondary">Update your access credentials</small>
                </div>
            </div>

            <div class="card-modern-body">
                <?php if (!empty($errors) && ($_POST['action'] ?? '') === 'change_password'): ?>
                    <div class="alert alert-danger small mb-3">
                        <ul class="mb-0 ps-3">
                            <?php foreach ($errors as $err): ?>
                                <li><?= htmlspecialchars($err) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="change_password">

                    <div class="mb-3">
                        <label class="form-label">Current Password</label>
                        <input type="password" class="form-control" name="current_password" required placeholder="Enter current password">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">New Password</label>
                        <input type="password" class="form-control" name="new_password" required placeholder="Min 8 characters">
                    </div>

                    <div class="mb-4">
                        <label class="form-label">Confirm New Password</label>
                        <input type="password" class="form-control" name="confirm_password" required placeholder="Re-type new password">
                    </div>

                    <button type="submit" class="btn btn-warning w-100 fw-semibold"><i class="bi bi-shield-check me-1"></i>Update Password</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
