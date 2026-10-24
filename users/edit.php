<?php
/**
 * Edit User Account (Admin Only)
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

require_admin();

$pdo = get_db_connection(true);
$currentUser = current_user();

$userId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($userId <= 0) {
    redirect('users/index.php');
}

$stmt = $pdo->prepare("SELECT id, name, email, role, is_active, created_at FROM users WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $userId]);
$targetUser = $stmt->fetch();

if (!$targetUser) {
    set_flash('danger', 'User account not found.');
    redirect('users/index.php');
}

$isSelf = ((int)$targetUser['id'] === (int)$currentUser['id']);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_die();

    $name = sanitize($_POST['name'] ?? '');
    $email = sanitize($_POST['email'] ?? '');
    $role = sanitize($_POST['role'] ?? $targetUser['role']);
    $isActive = isset($_POST['is_active']) ? 1 : 0;
    $newPassword = $_POST['new_password'] ?? '';

    if ($name === '' || strlen($name) > 100) {
        $errors[] = 'Full name is required.';
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'A valid email address is required.';
    }

    if ($isSelf) {
        // Enforce that administrators cannot deactivate or demote themselves
        $role = 'ADMIN';
        $isActive = 1;
    }

    if ($newPassword !== '' && strlen($newPassword) < 8) {
        $errors[] = 'New password must be at least 8 characters long.';
    }

    if (empty($errors)) {
        try {
            // Check email uniqueness excluding self
            $checkStmt = $pdo->prepare("SELECT id FROM users WHERE email = :email AND id != :id LIMIT 1");
            $checkStmt->execute([':email' => $email, ':id' => $userId]);
            if ($checkStmt->fetch()) {
                $errors[] = "Email address '{$email}' is already in use by another user.";
            } else {
                if ($newPassword !== '') {
                    $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
                    $up = $pdo->prepare("
                        UPDATE users 
                        SET name = :name,
                            email = :email,
                            role = :role,
                            is_active = :active,
                            password_hash = :hash,
                            updated_at = NOW()
                        WHERE id = :id
                    ");
                    $up->execute([
                        ':name'   => $name,
                        ':email'  => $email,
                        ':role'   => $role,
                        ':active' => $isActive,
                        ':hash'   => $newHash,
                        ':id'     => $userId
                    ]);
                } else {
                    $up = $pdo->prepare("
                        UPDATE users 
                        SET name = :name,
                            email = :email,
                            role = :role,
                            is_active = :active,
                            updated_at = NOW()
                        WHERE id = :id
                    ");
                    $up->execute([
                        ':name'   => $name,
                        ':email'  => $email,
                        ':role'   => $role,
                        ':active' => $isActive,
                        ':id'     => $userId
                    ]);
                }

                // If editing self, update active session name and email
                if ($isSelf) {
                    $_SESSION['user_name'] = $name;
                    $_SESSION['user_email'] = $email;
                }

                set_flash('success', "User account '{$name}' updated successfully.");
                redirect('users/index.php');
            }
        } catch (PDOException $e) {
            error_log("Failed to update user: " . $e->getMessage());
            $errors[] = 'Database error while saving changes.';
        }
    }
}

$pageTitle = 'Edit User #' . $targetUser['id'];
$activeNav = 'users';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
require_once __DIR__ . '/../includes/navbar.php';
?>

<div class="row justify-content-center">
    <div class="col-lg-6 col-md-8">
        <div class="card-modern">
            <div class="card-modern-header">
                <div>
                    <h5 class="fw-bold text-white mb-0">Edit User Account</h5>
                    <small class="text-secondary"><?= e($targetUser['email']) ?></small>
                </div>
                <a href="<?= url('users/index.php') ?>" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-arrow-left me-1"></i>Back
                </a>
            </div>

            <div class="card-modern-body">
                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger mb-4 small">
                        <ul class="mb-0 ps-3">
                            <?php foreach ($errors as $err): ?>
                                <li><?= htmlspecialchars($err) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <?php if ($isSelf): ?>
                    <div class="alert alert-info small mb-4">
                        <i class="bi bi-info-circle me-1"></i> You are editing your own administrator profile. Self-deactivation and self-demotion are disabled for security.
                    </div>
                <?php endif; ?>

                <form method="POST">
                    <?= csrf_field() ?>

                    <div class="mb-3">
                        <label class="form-label">Full Name</label>
                        <input type="text" class="form-control" name="name" required 
                               value="<?= e($_POST['name'] ?? $targetUser['name']) ?>">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Email Address (Login Username)</label>
                        <input type="email" class="form-control" name="email" required 
                               value="<?= e($_POST['email'] ?? $targetUser['email']) ?>">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">System Role</label>
                        <select class="form-select" name="role" <?= $isSelf ? 'disabled' : '' ?>>
                            <option value="OPERATOR" <?= ($targetUser['role'] === 'OPERATOR') ? 'selected' : '' ?>>OPERATOR</option>
                            <option value="ADMIN" <?= ($targetUser['role'] === 'ADMIN') ? 'selected' : '' ?>>ADMINISTRATOR</option>
                        </select>
                        <?php if ($isSelf): ?>
                            <input type="hidden" name="role" value="ADMIN">
                        <?php endif; ?>
                    </div>

                    <div class="mb-4">
                        <label class="form-label">Reset Password <small class="text-secondary">(Leave blank to keep current password)</small></label>
                        <input type="password" class="form-control" name="new_password" placeholder="Enter new password if changing">
                    </div>

                    <div class="form-check form-switch mb-4">
                        <input class="form-check-input" type="checkbox" id="userActiveCheck" name="is_active" 
                               <?= ($targetUser['is_active'] ? 'checked' : '') ?> <?= $isSelf ? 'disabled' : '' ?>>
                        <label class="form-check-label" for="userActiveCheck">Account Active</label>
                        <?php if ($isSelf): ?>
                            <input type="hidden" name="is_active" value="1">
                        <?php endif; ?>
                    </div>

                    <div class="d-flex justify-content-end gap-2">
                        <a href="<?= url('users/index.php') ?>" class="btn btn-dark border-secondary">Cancel</a>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Save User Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
