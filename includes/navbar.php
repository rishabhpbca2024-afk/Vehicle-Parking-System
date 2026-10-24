<?php
/**
 * Shared Application Top Navbar Partial
 */

declare(strict_types=1);

$user = current_user();
$pageTitle = $pageTitle ?? 'Dashboard';
?>
<main class="app-main">
    <header class="app-navbar">
        <div class="d-flex align-items-center">
            <button id="sidebar-toggle" class="btn btn-dark btn-sm d-lg-none me-3" type="button" aria-label="Toggle navigation">
                <i class="bi bi-list fs-5"></i>
            </button>
            <h5 class="fw-bold mb-0 text-white d-none d-sm-block"><?= e($pageTitle) ?></h5>
        </div>

        <div class="d-flex align-items-center gap-3">
            <!-- Digital Real-time Clock -->
            <div class="live-clock-badge d-none d-md-flex align-items-center">
                <i class="bi bi-clock me-2"></i>
                <span id="live-clock">Loading time...</span>
            </div>

            <!-- Theme Toggle Button (Dark / Light) -->
            <button id="theme-toggle" class="btn btn-dark btn-sm border-secondary d-flex align-items-center" type="button" title="Toggle Light / Dark Mode">
                <i class="bi bi-moon-stars-fill" id="theme-icon"></i>
            </button>

            <!-- Quick Vehicle Check-in Action -->
            <a href="<?= url('vehicles/entry.php') ?>" class="btn btn-primary btn-sm d-flex align-items-center">
                <i class="bi bi-plus-circle me-1"></i>
                <span class="d-none d-sm-inline">New Entry</span>
            </a>

            <!-- User Dropdown Menu -->
            <div class="dropdown">
                <button class="btn btn-dark btn-sm dropdown-toggle border-secondary" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="bi bi-person-circle me-1 text-primary"></i>
                    <span class="d-none d-sm-inline"><?= e($user['name'] ?? 'Account') ?></span>
                </button>
                <ul class="dropdown-menu dropdown-menu-dark dropdown-menu-end shadow-lg border-secondary">
                    <li class="px-3 py-2 border-bottom border-secondary">
                        <div class="fw-semibold text-white"><?= e($user['name'] ?? '') ?></div>
                        <div class="small text-secondary"><?= e($user['email'] ?? '') ?></div>
                    </li>
                    <li>
                        <a class="dropdown-item py-2" href="<?= url('profile.php') ?>">
                            <i class="bi bi-person-gear me-2 text-info"></i>My Profile
                        </a>
                    </li>
                    <li><hr class="dropdown-divider border-secondary"></li>
                    <li>
                        <a class="dropdown-item py-2 text-danger" href="<?= url('logout.php') ?>">
                            <i class="bi bi-box-arrow-right me-2"></i>Sign Out
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </header>

    <div class="content-body">
        <?php
        // Render any pending flash messages
        $flashMessages = get_flash_messages();
        foreach ($flashMessages as $flash):
        ?>
            <div class="alert alert-<?= htmlspecialchars($flash['type']) ?> alert-dismissible fade show d-flex align-items-center" role="alert">
                <?php if ($flash['type'] === 'success'): ?>
                    <i class="bi bi-check-circle-fill me-2 fs-5"></i>
                <?php elseif ($flash['type'] === 'danger'): ?>
                    <i class="bi bi-exclamation-octagon-fill me-2 fs-5"></i>
                <?php elseif ($flash['type'] === 'warning'): ?>
                    <i class="bi bi-exclamation-triangle-fill me-2 fs-5"></i>
                <?php else: ?>
                    <i class="bi bi-info-circle-fill me-2 fs-5"></i>
                <?php endif; ?>
                <div><?= htmlspecialchars($flash['message']) ?></div>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endforeach; ?>
