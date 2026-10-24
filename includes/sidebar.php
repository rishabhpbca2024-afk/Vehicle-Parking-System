<?php
/**
 * Shared Application Sidebar Partial
 */

declare(strict_types=1);

$activeNav = $activeNav ?? 'dashboard';
$user = current_user();
?>
<aside class="app-sidebar">
    <div class="sidebar-brand">
        <div class="brand-logo-icon">
            <i class="bi bi-p-square-fill"></i>
        </div>
        <div>
            <h6 class="fw-bold mb-0 text-white tracking-wide">SmartPark</h6>
            <small class="text-secondary" style="font-size: 0.72rem;">PARKING MANAGEMENT</small>
        </div>
    </div>

    <div class="sidebar-nav">
        <div class="sidebar-heading">Core Operations</div>
        
        <a href="<?= url('dashboard/index.php') ?>" class="nav-link-custom <?= $activeNav === 'dashboard' ? 'active' : '' ?>">
            <i class="bi bi-grid-1x2-fill"></i>
            <span>Dashboard</span>
        </a>

        <a href="<?= url('vehicles/entry.php') ?>" class="nav-link-custom <?= $activeNav === 'entry' ? 'active' : '' ?>">
            <i class="bi bi-box-arrow-in-down-right"></i>
            <span>Vehicle Entry</span>
        </a>

        <a href="<?= url('vehicles/active.php') ?>" class="nav-link-custom <?= $activeNav === 'active' ? 'active' : '' ?>">
            <i class="bi bi-clock-history"></i>
            <span>Active Parking</span>
        </a>

        <a href="<?= url('slots/index.php') ?>" class="nav-link-custom <?= $activeNav === 'slots' ? 'active' : '' ?>">
            <i class="bi bi-p-circle-fill"></i>
            <span>Parking Slots</span>
        </a>

        <div class="sidebar-heading">Records & Billing</div>

        <a href="<?= url('vehicles/history.php') ?>" class="nav-link-custom <?= $activeNav === 'history' ? 'active' : '' ?>">
            <i class="bi bi-journal-text"></i>
            <span>Parking History</span>
        </a>

        <a href="<?= url('payments/index.php') ?>" class="nav-link-custom <?= $activeNav === 'payments' ? 'active' : '' ?>">
            <i class="bi bi-receipt"></i>
            <span>Payments Log</span>
        </a>

        <div class="sidebar-heading">Analytics & Reports</div>

        <a href="<?= url('reports/daily.php') ?>" class="nav-link-custom <?= $activeNav === 'reports_daily' ? 'active' : '' ?>">
            <i class="bi bi-calendar-check"></i>
            <span>Daily Report</span>
        </a>

        <a href="<?= url('reports/monthly.php') ?>" class="nav-link-custom <?= $activeNav === 'reports_monthly' ? 'active' : '' ?>">
            <i class="bi bi-bar-chart-line-fill"></i>
            <span>Monthly Report</span>
        </a>

        <?php if (is_admin()): ?>
            <div class="sidebar-heading">Administration</div>

            <a href="<?= url('rates/index.php') ?>" class="nav-link-custom <?= $activeNav === 'rates' ? 'active' : '' ?>">
                <i class="bi bi-currency-exchange"></i>
                <span>Parking Rates</span>
            </a>

            <a href="<?= url('users/index.php') ?>" class="nav-link-custom <?= $activeNav === 'users' ? 'active' : '' ?>">
                <i class="bi bi-people-fill"></i>
                <span>User Management</span>
            </a>
        <?php endif; ?>
    </div>

    <div class="sidebar-user">
        <div class="user-avatar">
            <?= strtoupper(substr($user['name'] ?? 'U', 0, 1)) ?>
        </div>
        <div class="flex-grow-1 overflow-hidden me-2">
            <div class="fw-semibold text-truncate small text-white"><?= e($user['name'] ?? 'User') ?></div>
            <div class="d-flex align-items-center">
                <span class="badge <?= is_admin() ? 'bg-danger' : 'bg-primary' ?> py-0 px-1" style="font-size: 0.68rem;">
                    <?= is_admin() ? 'ADMIN' : 'OPERATOR' ?>
                </span>
            </div>
        </div>
        <a href="<?= url('logout.php') ?>" class="text-secondary text-decoration-none hover-light" title="Sign Out">
            <i class="bi bi-box-arrow-right fs-5"></i>
        </a>
    </div>
</aside>
