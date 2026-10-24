<?php
/**
 * User Accounts & Role-Based Access Control (Admin Only)
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

// Handle User Creation POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_die();

    $action = $_POST['action'] ?? '';

    if ($action === 'create_user') {
        $name = sanitize($_POST['name'] ?? '');
        $email = sanitize($_POST['email'] ?? '');
        $role = sanitize($_POST['role'] ?? 'OPERATOR');
        $password = $_POST['password'] ?? '';

        $errors = [];
        if ($name === '' || strlen($name) > 100) {
            $errors[] = 'Full name is required (max 100 characters).';
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'A valid email address is required.';
        }
        if (!in_array($role, ['ADMIN', 'OPERATOR'], true)) {
            $errors[] = 'Invalid user role selected.';
        }
        if (strlen($password) < 8) {
            $errors[] = 'Initial password must be at least 8 characters long.';
        }

        if (empty($errors)) {
            try {
                $checkStmt = $pdo->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
                $checkStmt->execute([':email' => $email]);
                if ($checkStmt->fetch()) {
                    set_flash('danger', "Email address '{$email}' is already registered.");
                } else {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $ins = $pdo->prepare("
                        INSERT INTO users (name, email, password_hash, role, is_active, created_at)
                        VALUES (:name, :email, :hash, :role, 1, NOW())
                    ");
                    $ins->execute([
                        ':name'  => $name,
                        ':email' => $email,
                        ':hash'  => $hash,
                        ':role'  => $role
                    ]);

                    set_flash('success', "User '{$name}' ({$role}) created successfully.");
                }
            } catch (PDOException $e) {
                error_log("Failed to create user: " . $e->getMessage());
                set_flash('danger', 'An error occurred while creating the user account.');
            }
        } else {
            set_flash('danger', implode(' ', $errors));
        }

        redirect('users/index.php');
    }
}

// Fetch all users
$stmt = $pdo->query("SELECT id, name, email, role, is_active, created_at FROM users ORDER BY id ASC");
$allUsers = $stmt->fetchAll();

$pageTitle = 'User Management';
$activeNav = 'users';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
require_once __DIR__ . '/../includes/navbar.php';
?>

<!-- Header -->
<div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3 mb-4">
    <div>
        <h4 class="fw-bold text-white mb-1">User & Role Management</h4>
        <p class="text-secondary small mb-0">Manage operator permissions, administrator accounts, and access status</p>
    </div>
    <div>
        <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addUserModal">
            <i class="bi bi-person-plus-fill me-1"></i>Add New User
        </button>
    </div>
</div>

<!-- Users Table -->
<div class="card-modern">
    <div class="table-responsive">
        <table class="table table-custom">
            <thead>
                <tr>
                    <th>User ID</th>
                    <th>Name</th>
                    <th>Email Address</th>
                    <th>System Role</th>
                    <th>Account Status</th>
                    <th>Member Since</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($allUsers as $u): 
                    $isSelf = ((int)$u['id'] === (int)$currentUser['id']);
                ?>
                    <tr>
                        <td class="font-monospace text-secondary">#USR-<?= str_pad((string)$u['id'], 3, '0', STR_PAD_LEFT) ?></td>
                        <td>
                            <div class="fw-bold text-white">
                                <?= e($u['name']) ?>
                                <?php if ($isSelf): ?>
                                    <span class="badge bg-secondary-subtle text-secondary ms-1 small">You</span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <span class="font-monospace text-light"><?= e($u['email']) ?></span>
                        </td>
                        <td>
                            <?php if ($u['role'] === 'ADMIN'): ?>
                                <span class="badge bg-danger-subtle text-danger"><i class="bi bi-shield-lock-fill me-1"></i>ADMIN</span>
                            <?php else: ?>
                                <span class="badge bg-primary-subtle text-primary"><i class="bi bi-person me-1"></i>OPERATOR</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($u['is_active']): ?>
                                <span class="badge bg-success-subtle text-success"><i class="bi bi-check-circle me-1"></i>Active</span>
                            <?php else: ?>
                                <span class="badge bg-danger-subtle text-danger"><i class="bi bi-slash-circle me-1"></i>Deactivated</span>
                            <?php endif; ?>
                        </td>
                        <td class="small text-secondary"><?= format_datetime($u['created_at']) ?></td>
                        <td class="text-end">
                            <a href="<?= url('users/edit.php?id=' . $u['id']) ?>" class="btn btn-outline-secondary btn-sm" title="Edit User">
                                <i class="bi bi-pencil me-1"></i>Edit
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Add User Modal -->
<div class="modal fade" id="addUserModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content bg-dark text-white border-secondary">
            <div class="modal-header border-secondary">
                <h5 class="modal-title fw-bold"><i class="bi bi-person-plus me-2 text-primary"></i>Provision User Account</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create_user">

                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Full Name</label>
                        <input type="text" class="form-control" name="name" required placeholder="e.g. John Doe">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Email Address (Login Username)</label>
                        <input type="email" class="form-control" name="email" required placeholder="operator@smartpark.local">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">System Role</label>
                        <select class="form-select" name="role" required>
                            <option value="OPERATOR" selected>OPERATOR (Entry, Checkout, Tickets, Reports)</option>
                            <option value="ADMIN">ADMINISTRATOR (Full system control, rates, users)</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <label class="form-label mb-0">Initial Password</label>
                            <button type="button" class="btn btn-outline-info btn-xs py-0 px-2" id="fillDefaultPassBtn" style="font-size: 0.75rem;">
                                <i class="bi bi-magic me-1"></i>Set Default (Operator@123)
                            </button>
                        </div>
                        <input type="text" class="form-control font-monospace" id="new_user_password" name="password" required placeholder="Minimum 8 characters">
                        <small class="text-secondary d-block mt-1">
                            <i class="bi bi-info-circle me-1"></i> Yeh password aap Operator ko denge login karne ke liye. Operator login ke baad "My Profile" se ise kabhi bhi change kar sakta hai.
                        </small>
                    </div>
                </div>

                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-check-lg me-1"></i>Create User</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
