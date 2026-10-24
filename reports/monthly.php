<?php
/**
 * Monthly Financial & Operations Volume Report
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_login();

$pdo = get_db_connection(true);

$selectedMonth = (int)($_GET['month'] ?? date('n'));
$selectedYear = (int)($_GET['year'] ?? date('Y'));

if ($selectedMonth < 1 || $selectedMonth > 12) {
    $selectedMonth = (int)date('n');
}
if ($selectedYear < 2020 || $selectedYear > 2050) {
    $selectedYear = (int)date('Y');
}

$yearMonthStr = sprintf('%04d-%02d', $selectedYear, $selectedMonth);
$daysInMonth = (int)date('t', strtotime("{$yearMonthStr}-01"));

// 1. Total Monthly Revenue
$revStmt = $pdo->prepare("
    SELECT COALESCE(SUM(amount), 0)
    FROM payments
    WHERE status = 'PAID' AND DATE_FORMAT(paid_at, '%Y-%m') = :ym
");
$revStmt->execute([':ym' => $yearMonthStr]);
$monthlyRevenue = (float)$revStmt->fetchColumn();

// 2. Total Monthly Entries
$entryStmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM parking_sessions
    WHERE DATE_FORMAT(entry_time, '%Y-%m') = :ym
");
$entryStmt->execute([':ym' => $yearMonthStr]);
$monthlyEntries = (int)$entryStmt->fetchColumn();

// 3. Total Completed Exits
$exitStmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM parking_sessions
    WHERE status = 'COMPLETED' AND DATE_FORMAT(exit_time, '%Y-%m') = :ym
");
$exitStmt->execute([':ym' => $yearMonthStr]);
$monthlyExits = (int)$exitStmt->fetchColumn();

// 4. Day-by-Day Aggregates for the whole month
$dailyEntriesQuery = $pdo->prepare("
    SELECT DATE(entry_time) as dt, COUNT(*) as cnt 
    FROM parking_sessions 
    WHERE DATE_FORMAT(entry_time, '%Y-%m') = :ym 
    GROUP BY DATE(entry_time)
");
$dailyEntriesQuery->execute([':ym' => $yearMonthStr]);
$entriesByDay = $dailyEntriesQuery->fetchAll(PDO::FETCH_KEY_PAIR);

$dailyExitsQuery = $pdo->prepare("
    SELECT DATE(exit_time) as dt, COUNT(*) as cnt 
    FROM parking_sessions 
    WHERE status = 'COMPLETED' AND DATE_FORMAT(exit_time, '%Y-%m') = :ym 
    GROUP BY DATE(exit_time)
");
$dailyExitsQuery->execute([':ym' => $yearMonthStr]);
$exitsByDay = $dailyExitsQuery->fetchAll(PDO::FETCH_KEY_PAIR);

$dailyRevQuery = $pdo->prepare("
    SELECT DATE(paid_at) as dt, SUM(amount) as total 
    FROM payments 
    WHERE status = 'PAID' AND DATE_FORMAT(paid_at, '%Y-%m') = :ym 
    GROUP BY DATE(paid_at)
");
$dailyRevQuery->execute([':ym' => $yearMonthStr]);
$revByDay = $dailyRevQuery->fetchAll(PDO::FETCH_KEY_PAIR);

$monthName = date('F', mktime(0, 0, 0, $selectedMonth, 10));

$pageTitle = "Monthly Report — {$monthName} {$selectedYear}";
$activeNav = 'reports_monthly';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
require_once __DIR__ . '/../includes/navbar.php';
?>

<!-- Header & Period Filter -->
<div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3 mb-4">
    <div>
        <h4 class="fw-bold text-white mb-1">Monthly Financial Report</h4>
        <p class="text-secondary small mb-0">Full performance and audit breakdown for <strong><?= $monthName ?> <?= $selectedYear ?></strong></p>
    </div>
    <div class="d-flex align-items-center gap-2 no-print">
        <form method="GET" class="d-flex align-items-center gap-2">
            <select name="month" class="form-select form-select-sm">
                <?php for ($m = 1; $m <= 12; $m++): ?>
                    <option value="<?= $m ?>" <?= ($m === $selectedMonth) ? 'selected' : '' ?>>
                        <?= date('F', mktime(0, 0, 0, $m, 10)) ?>
                    </option>
                <?php endfor; ?>
            </select>
            <select name="year" class="form-select form-select-sm">
                <?php for ($y = date('Y') + 1; $y >= 2022; $y--): ?>
                    <option value="<?= $y ?>" <?= ($y === $selectedYear) ? 'selected' : '' ?>><?= $y ?></option>
                <?php endfor; ?>
            </select>
            <button type="submit" class="btn btn-dark btn-sm border-secondary"><i class="bi bi-calendar3 me-1"></i>View</button>
        </form>
        <button type="button" class="btn btn-primary btn-sm" data-action="print">
            <i class="bi bi-printer me-1"></i>Print Report
        </button>
    </div>
</div>

<!-- Monthly KPI Cards -->
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="stat-card">
            <div>
                <small class="text-secondary text-uppercase fw-semibold" style="font-size: 0.72rem;">Monthly Revenue</small>
                <h3 class="fw-bold text-success mb-0 mt-1"><?= format_currency($monthlyRevenue) ?></h3>
                <small class="text-success"><i class="bi bi-check-circle-fill me-1"></i>Verified collections</small>
            </div>
            <div class="stat-icon success"><i class="bi bi-currency-rupee"></i></div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-card">
            <div>
                <small class="text-secondary text-uppercase fw-semibold" style="font-size: 0.72rem;">Total Inbound Vehicles</small>
                <h3 class="fw-bold text-white mb-0 mt-1"><?= $monthlyEntries ?></h3>
                <small class="text-info"><i class="bi bi-arrow-down-right me-1"></i>Total monthly entries</small>
            </div>
            <div class="stat-icon info"><i class="bi bi-car-front-fill"></i></div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-card">
            <div>
                <small class="text-secondary text-uppercase fw-semibold" style="font-size: 0.72rem;">Completed Exits</small>
                <h3 class="fw-bold text-white mb-0 mt-1"><?= $monthlyExits ?></h3>
                <small class="text-secondary"><i class="bi bi-arrow-up-right me-1"></i>Billed and checked out</small>
            </div>
            <div class="stat-icon primary"><i class="bi bi-check2-all"></i></div>
        </div>
    </div>
</div>

<!-- Day-by-Day Table -->
<div class="card-modern">
    <div class="card-modern-header">
        <h6 class="fw-bold text-white mb-0">Daily Breakdown for <?= $monthName ?> <?= $selectedYear ?></h6>
        <span class="badge bg-dark border border-secondary text-secondary"><?= $daysInMonth ?> Days</span>
    </div>
    <div class="table-responsive">
        <table class="table table-custom">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Day of Week</th>
                    <th class="text-center">Entries</th>
                    <th class="text-center">Exits</th>
                    <th class="text-end">Collections</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $grandTotalEntries = 0;
                $grandTotalExits = 0;
                $grandTotalRevenue = 0.0;

                for ($d = 1; $d <= $daysInMonth; $d++): 
                    $dayStr = sprintf('%s-%02d', $yearMonthStr, $d);
                    $dayEntries = (int)($entriesByDay[$dayStr] ?? 0);
                    $dayExits = (int)($exitsByDay[$dayStr] ?? 0);
                    $dayRev = (float)($revByDay[$dayStr] ?? 0.0);

                    $grandTotalEntries += $dayEntries;
                    $grandTotalExits += $dayExits;
                    $grandTotalRevenue += $dayRev;

                    $dayOfWeek = date('l', strtotime($dayStr));
                    $isWeekend = ($dayOfWeek === 'Saturday' || $dayOfWeek === 'Sunday');
                ?>
                    <tr class="<?= ($dayRev > 0) ? '' : 'text-secondary' ?>">
                        <td class="font-monospace fw-semibold <?= ($dayRev > 0) ? 'text-white' : '' ?>">
                            <?= date('d M Y', strtotime($dayStr)) ?>
                        </td>
                        <td>
                            <span class="<?= $isWeekend ? 'text-warning' : 'text-secondary' ?>">
                                <?= $dayOfWeek ?>
                            </span>
                        </td>
                        <td class="text-center">
                            <?php if ($dayEntries > 0): ?>
                                <span class="badge bg-dark border border-secondary text-info"><?= $dayEntries ?></span>
                            <?php else: ?>
                                <span class="text-secondary">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?php if ($dayExits > 0): ?>
                                <span class="badge bg-dark border border-secondary text-light"><?= $dayExits ?></span>
                            <?php else: ?>
                                <span class="text-secondary">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end font-monospace">
                            <?php if ($dayRev > 0): ?>
                                <strong class="text-success"><?= format_currency($dayRev) ?></strong>
                            <?php else: ?>
                                <span class="text-secondary"><?= format_currency(0) ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endfor; ?>
            </tbody>
            <tfoot>
                <tr class="table-dark fw-bold border-top border-secondary">
                    <td colspan="2" class="text-white">Monthly Summary Total:</td>
                    <td class="text-center text-info font-monospace"><?= $grandTotalEntries ?></td>
                    <td class="text-center text-white font-monospace"><?= $grandTotalExits ?></td>
                    <td class="text-end text-success font-monospace fs-5"><?= format_currency($grandTotalRevenue) ?></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
