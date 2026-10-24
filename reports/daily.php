<?php
/**
 * Daily Operations & Revenue Audit Report
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_login();

$pdo = get_db_connection(true);

$selectedDate = sanitize($_GET['report_date'] ?? date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate)) {
    $selectedDate = date('Y-m-d');
}

// 1. Daily Core Metrics
$entryCountStmt = $pdo->prepare("SELECT COUNT(*) FROM parking_sessions WHERE DATE(entry_time) = :d");
$entryCountStmt->execute([':d' => $selectedDate]);
$dailyEntries = (int)$entryCountStmt->fetchColumn();

$exitCountStmt = $pdo->prepare("SELECT COUNT(*) FROM parking_sessions WHERE status = 'COMPLETED' AND DATE(exit_time) = :d");
$exitCountStmt->execute([':d' => $selectedDate]);
$dailyExits = (int)$exitCountStmt->fetchColumn();

// Only count successful PAID transactions
$revStmt = $pdo->prepare("
    SELECT COALESCE(SUM(amount), 0) 
    FROM payments 
    WHERE status = 'PAID' AND DATE(paid_at) = :d
");
$revStmt->execute([':d' => $selectedDate]);
$dailyRevenue = (float)$revStmt->fetchColumn();

// 2. Breakdown by Vehicle Type for the selected day
$typeStmt = $pdo->prepare("
    SELECT 
        v.vehicle_type,
        COUNT(ps.id) as sessions_count,
        COALESCE(SUM(p.amount), 0) as total_rev
    FROM parking_sessions ps
    JOIN vehicles v ON ps.vehicle_id = v.id
    LEFT JOIN payments p ON ps.id = p.session_id AND p.status = 'PAID'
    WHERE DATE(ps.entry_time) = :d
    GROUP BY v.vehicle_type
");
$typeStmt->execute([':d' => $selectedDate]);
$typeBreakdown = $typeStmt->fetchAll();

// 3. Breakdown by Payment Method
$methodStmt = $pdo->prepare("
    SELECT 
        method,
        COUNT(*) as tx_count,
        COALESCE(SUM(amount), 0) as total_amount
    FROM payments
    WHERE status = 'PAID' AND DATE(paid_at) = :d
    GROUP BY method
");
$methodStmt->execute([':d' => $selectedDate]);
$methodBreakdown = $methodStmt->fetchAll();

// 4. Session Transactions on this day
$listStmt = $pdo->prepare("
    SELECT 
        ps.id,
        ps.entry_time,
        ps.exit_time,
        ps.amount,
        ps.status,
        v.registration_number,
        v.vehicle_type,
        s.slot_number,
        p.method as payment_method
    FROM parking_sessions ps
    JOIN vehicles v ON ps.vehicle_id = v.id
    JOIN parking_slots s ON ps.slot_id = s.id
    LEFT JOIN payments p ON ps.id = p.session_id AND p.status = 'PAID'
    WHERE DATE(ps.entry_time) = :entry_d OR DATE(ps.exit_time) = :exit_d
    ORDER BY ps.id DESC
");
$listStmt->execute([':entry_d' => $selectedDate, ':exit_d' => $selectedDate]);
$sessions = $listStmt->fetchAll();

$vehicleTypesMap = get_vehicle_types();
$paymentMethods = get_payment_methods();

$pageTitle = 'Daily Report — ' . date('d M Y', strtotime($selectedDate));
$activeNav = 'reports_daily';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
require_once __DIR__ . '/../includes/navbar.php';
?>

<!-- Header & Date Selector -->
<div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3 mb-4">
    <div>
        <h4 class="fw-bold text-white mb-1">Daily Operations Report</h4>
        <p class="text-secondary small mb-0">Summary of entries, exits, vehicle classes, and revenue for <?= date('l, F j, Y', strtotime($selectedDate)) ?></p>
    </div>
    <div class="d-flex align-items-center gap-2 no-print">
        <form method="GET" class="d-flex align-items-center gap-2">
            <input type="date" class="form-control form-control-sm" name="report_date" value="<?= e($selectedDate) ?>">
            <button type="submit" class="btn btn-dark btn-sm border-secondary"><i class="bi bi-calendar3 me-1"></i>Load</button>
        </form>
        <button type="button" class="btn btn-primary btn-sm" data-action="print">
            <i class="bi bi-printer me-1"></i>Print Report
        </button>
    </div>
</div>

<!-- Daily Summary KPIs -->
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="stat-card">
            <div>
                <small class="text-secondary text-uppercase fw-semibold" style="font-size: 0.72rem;">Vehicle Entries</small>
                <h3 class="fw-bold text-white mb-0 mt-1"><?= $dailyEntries ?></h3>
                <small class="text-info"><i class="bi bi-box-arrow-in-down-right me-1"></i>Recorded check-ins</small>
            </div>
            <div class="stat-icon info"><i class="bi bi-arrow-down-right-circle"></i></div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-card">
            <div>
                <small class="text-secondary text-uppercase fw-semibold" style="font-size: 0.72rem;">Vehicle Exits</small>
                <h3 class="fw-bold text-white mb-0 mt-1"><?= $dailyExits ?></h3>
                <small class="text-secondary"><i class="bi bi-box-arrow-right me-1"></i>Completed checkouts</small>
            </div>
            <div class="stat-icon primary"><i class="bi bi-arrow-up-right-circle"></i></div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-card">
            <div>
                <small class="text-secondary text-uppercase fw-semibold" style="font-size: 0.72rem;">Collected Revenue</small>
                <h3 class="fw-bold text-success mb-0 mt-1"><?= format_currency($dailyRevenue) ?></h3>
                <small class="text-success"><i class="bi bi-check-circle-fill me-1"></i>Verified PAID receipts</small>
            </div>
            <div class="stat-icon success"><i class="bi bi-wallet2"></i></div>
        </div>
    </div>
</div>

<!-- Categorical Breakdowns -->
<div class="row g-3 mb-4">
    <!-- Vehicle Type Breakdown -->
    <div class="col-lg-6">
        <div class="card-modern h-100">
            <div class="card-modern-header">
                <h6 class="fw-bold text-white mb-0">Vehicle Category Activity</h6>
            </div>
            <div class="table-responsive">
                <table class="table table-custom">
                    <thead>
                        <tr>
                            <th>Category</th>
                            <th>Vehicles Checked-in</th>
                            <th class="text-end">Revenue Collected</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($typeBreakdown)): ?>
                            <tr><td colspan="3" class="text-center text-secondary py-3">No activity recorded for this date.</td></tr>
                        <?php else: ?>
                            <?php foreach ($typeBreakdown as $tb): 
                                $vm = $vehicleTypesMap[$tb['vehicle_type']] ?? ['short' => $tb['vehicle_type']];
                            ?>
                                <tr>
                                    <td class="fw-semibold text-white"><?= e($vm['short']) ?></td>
                                    <td><span class="badge bg-dark border border-secondary text-info"><?= $tb['sessions_count'] ?></span></td>
                                    <td class="text-end text-success fw-bold font-monospace"><?= format_currency($tb['total_rev']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Payment Methods Breakdown -->
    <div class="col-lg-6">
        <div class="card-modern h-100">
            <div class="card-modern-header">
                <h6 class="fw-bold text-white mb-0">Collections by Payment Instrument</h6>
            </div>
            <div class="table-responsive">
                <table class="table table-custom">
                    <thead>
                        <tr>
                            <th>Payment Channel</th>
                            <th>Transactions</th>
                            <th class="text-end">Total Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($methodBreakdown)): ?>
                            <tr><td colspan="3" class="text-center text-secondary py-3">No payments recorded for this date.</td></tr>
                        <?php else: ?>
                            <?php foreach ($methodBreakdown as $mb): 
                                $pm = $paymentMethods[$mb['method']] ?? ['label' => $mb['method']];
                            ?>
                                <tr>
                                    <td class="fw-semibold text-white"><?= e($pm['label']) ?></td>
                                    <td><span class="badge bg-dark border border-secondary text-light"><?= $mb['tx_count'] ?></span></td>
                                    <td class="text-end text-success fw-bold font-monospace"><?= format_currency($mb['total_amount']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Detailed Daily Activity Log -->
<div class="card-modern">
    <div class="card-modern-header">
        <h6 class="fw-bold text-white mb-0">Audit Log for <?= date('d M Y', strtotime($selectedDate)) ?></h6>
        <span class="badge bg-dark border border-secondary text-secondary"><?= count($sessions) ?> Sessions</span>
    </div>
    <div class="table-responsive">
        <table class="table table-custom">
            <thead>
                <tr>
                    <th>Ticket</th>
                    <th>Vehicle Reg.</th>
                    <th>Type</th>
                    <th>Slot</th>
                    <th>Entry</th>
                    <th>Exit</th>
                    <th>Status</th>
                    <th>Method</th>
                    <th class="text-end">Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($sessions)): ?>
                    <tr><td colspan="9" class="text-center text-secondary py-4">No parking sessions found on <?= date('d M Y', strtotime($selectedDate)) ?>.</td></tr>
                <?php else: ?>
                    <?php foreach ($sessions as $s): 
                        $vm = $vehicleTypesMap[$s['vehicle_type']] ?? ['short' => $s['vehicle_type']];
                    ?>
                        <tr>
                            <td class="font-monospace text-info"><?= generate_ticket_number((int)$s['id']) ?></td>
                            <td class="fw-bold text-white font-monospace"><?= e($s['registration_number']) ?></td>
                            <td><?= e($vm['short']) ?></td>
                            <td><?= e($s['slot_number']) ?></td>
                            <td class="small text-secondary"><?= format_datetime($s['entry_time'], 'h:i A') ?></td>
                            <td class="small text-secondary"><?= $s['exit_time'] ? format_datetime($s['exit_time'], 'h:i A') : '—' ?></td>
                            <td>
                                <?php if ($s['status'] === 'ACTIVE'): ?>
                                    <span class="badge bg-danger-subtle text-danger">Active</span>
                                <?php else: ?>
                                    <span class="badge bg-success-subtle text-success">Completed</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <small class="text-secondary"><?= e($s['payment_method'] ?? '—') ?></small>
                            </td>
                            <td class="text-end fw-bold font-monospace text-success">
                                <?= ($s['status'] === 'COMPLETED') ? format_currency($s['amount']) : '—' ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
