<?php
/**
 * Real-time Operations & Analytics Dashboard
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_login();

$pdo = get_db_connection(true);

// 1. Parking Slots Metrics
$slotStatsQuery = $pdo->query("
    SELECT 
        COUNT(*) as total_slots,
        COALESCE(SUM(CASE WHEN status = 'AVAILABLE' THEN 1 ELSE 0 END), 0) as available_slots,
        COALESCE(SUM(CASE WHEN status = 'OCCUPIED' THEN 1 ELSE 0 END), 0) as occupied_slots,
        COALESCE(SUM(CASE WHEN status = 'OUT_OF_SERVICE' THEN 1 ELSE 0 END), 0) as out_of_service_slots
    FROM parking_slots
");
$slotStats = $slotStatsQuery->fetch() ?: ['total_slots' => 0, 'available_slots' => 0, 'occupied_slots' => 0, 'out_of_service_slots' => 0];

$totalSlots = (int)$slotStats['total_slots'];
$availableSlots = (int)$slotStats['available_slots'];
$occupiedSlots = (int)$slotStats['occupied_slots'];
$outOfServiceSlots = (int)$slotStats['out_of_service_slots'];

$occupancyRate = $totalSlots > 0 ? round(($occupiedSlots / $totalSlots) * 100, 1) : 0;

// 2. Active Parked Vehicles Count
$activeVehiclesStmt = $pdo->query("SELECT COUNT(*) FROM parking_sessions WHERE status = 'ACTIVE'");
$currentlyParked = (int)$activeVehiclesStmt->fetchColumn();

// 3. Today's Entries
$todayEntriesStmt = $pdo->query("SELECT COUNT(*) FROM parking_sessions WHERE DATE(entry_time) = CURDATE()");
$todayEntries = (int)$todayEntriesStmt->fetchColumn();

// 4. Today's Exits
$todayExitsStmt = $pdo->query("SELECT COUNT(*) FROM parking_sessions WHERE status = 'COMPLETED' AND DATE(exit_time) = CURDATE()");
$todayExits = (int)$todayExitsStmt->fetchColumn();

// 5. Today's Collected Revenue (Strictly PAID payments)
$todayRevStmt = $pdo->query("
    SELECT COALESCE(SUM(amount), 0) 
    FROM payments 
    WHERE status = 'PAID' AND DATE(paid_at) = CURDATE()
");
$todayRevenue = (float)$todayRevStmt->fetchColumn();

// 6. Occupancy by Vehicle Type
$typeOccupancyStmt = $pdo->query("
    SELECT 
        vehicle_type,
        COUNT(*) as total,
        COALESCE(SUM(CASE WHEN status = 'OCCUPIED' THEN 1 ELSE 0 END), 0) as occupied,
        COALESCE(SUM(CASE WHEN status = 'AVAILABLE' THEN 1 ELSE 0 END), 0) as available
    FROM parking_slots
    GROUP BY vehicle_type
");
$typeOccupancyRows = $typeOccupancyStmt->fetchAll();
$vehicleTypesMap = get_vehicle_types();

// 7. Last 7 Days Revenue Trend for Chart.js
$revenue7DaysStmt = $pdo->query("
    SELECT 
        DATE(paid_at) as pay_date,
        COALESCE(SUM(amount), 0) as daily_total
    FROM payments
    WHERE status = 'PAID' AND paid_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
    GROUP BY DATE(paid_at)
    ORDER BY pay_date ASC
");
$revMap = [];
while ($row = $revenue7DaysStmt->fetch()) {
    $revMap[$row['pay_date']] = (float)$row['daily_total'];
}

$chartLabels = [];
$chartRevenueData = [];
for ($i = 6; $i >= 0; $i--) {
    $dateKey = date('Y-m-d', strtotime("-{$i} days"));
    $chartLabels[] = date('D, M j', strtotime($dateKey));
    $chartRevenueData[] = $revMap[$dateKey] ?? 0.00;
}

// 8. Vehicle Type Distribution for Chart.js
$typeChartLabels = [];
$typeChartData = [];
$typeChartColors = ['#06b6d4', '#6366f1', '#10b981', '#f59e0b'];
$colorIndex = 0;
foreach ($vehicleTypesMap as $vKey => $vMeta) {
    $count = 0;
    foreach ($typeOccupancyRows as $tRow) {
        if ($tRow['vehicle_type'] === $vKey) {
            $count = (int)$tRow['occupied'];
            break;
        }
    }
    $typeChartLabels[] = $vMeta['short'];
    $typeChartData[] = $count;
}

// 9. Recent Parking Activity
$recentStmt = $pdo->query("
    SELECT 
        ps.id,
        ps.entry_time,
        ps.exit_time,
        ps.rate_per_hour,
        ps.amount,
        ps.status,
        v.registration_number,
        v.vehicle_type,
        s.slot_number,
        s.floor_number
    FROM parking_sessions ps
    JOIN vehicles v ON ps.vehicle_id = v.id
    JOIN parking_slots s ON ps.slot_id = s.id
    ORDER BY ps.id DESC
    LIMIT 8
");
$recentActivities = $recentStmt->fetchAll();

$pageTitle = 'Dashboard Overview';
$activeNav = 'dashboard';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
require_once __DIR__ . '/../includes/navbar.php';
?>

<!-- Statistics Summary Grid -->
<div class="row g-3 mb-4">
    <!-- Total Capacity -->
    <div class="col-xl-3 col-sm-6">
        <div class="stat-card">
            <div>
                <small class="text-secondary fw-medium text-uppercase" style="font-size: 0.72rem;">Total Capacity</small>
                <h3 class="fw-bold mb-0 text-white mt-1"><?= $totalSlots ?></h3>
                <small class="text-muted"><?= $availableSlots ?> slots available</small>
            </div>
            <div class="stat-icon primary">
                <i class="bi bi-grid-fill"></i>
            </div>
        </div>
    </div>

    <!-- Active Parked Vehicles -->
    <div class="col-xl-3 col-sm-6">
        <div class="stat-card">
            <div>
                <small class="text-secondary fw-medium text-uppercase" style="font-size: 0.72rem;">Parked Now</small>
                <h3 class="fw-bold mb-0 text-white mt-1"><?= $currentlyParked ?></h3>
                <small class="text-info"><?= $occupancyRate ?>% lot occupancy</small>
            </div>
            <div class="stat-icon warning">
                <i class="bi bi-car-front-fill"></i>
            </div>
        </div>
    </div>

    <!-- Today's Activity -->
    <div class="col-xl-3 col-sm-6">
        <div class="stat-card">
            <div>
                <small class="text-secondary fw-medium text-uppercase" style="font-size: 0.72rem;">Today's In / Out</small>
                <h3 class="fw-bold mb-0 text-white mt-1"><?= $todayEntries ?> <span class="fs-6 text-secondary fw-normal">/</span> <?= $todayExits ?></h3>
                <small class="text-success"><i class="bi bi-arrow-up-right me-1"></i><?= $todayEntries ?> entries today</small>
            </div>
            <div class="stat-icon info">
                <i class="bi bi-arrow-left-right"></i>
            </div>
        </div>
    </div>

    <!-- Today's Collected Revenue -->
    <div class="col-xl-3 col-sm-6">
        <div class="stat-card">
            <div>
                <small class="text-secondary fw-medium text-uppercase" style="font-size: 0.72rem;">Today's Revenue</small>
                <h3 class="fw-bold mb-0 text-white mt-1"><?= format_currency($todayRevenue) ?></h3>
                <small class="text-success"><i class="bi bi-check2-circle me-1"></i>Paid receipts</small>
            </div>
            <div class="stat-icon success">
                <i class="bi bi-wallet2"></i>
            </div>
        </div>
    </div>
</div>

<!-- Slots Status Mini Bar -->
<div class="card-modern mb-4">
    <div class="card-modern-body py-3">
        <div class="row align-items-center g-3 text-center text-sm-start">
            <div class="col-sm-3 border-end border-secondary-subtle">
                <span class="badge bg-success-subtle text-success me-2"><i class="bi bi-check-circle me-1"></i>Available</span>
                <span class="fw-bold text-white fs-5"><?= $availableSlots ?></span>
            </div>
            <div class="col-sm-3 border-end border-secondary-subtle">
                <span class="badge bg-danger-subtle text-danger me-2"><i class="bi bi-car-front me-1"></i>Occupied</span>
                <span class="fw-bold text-white fs-5"><?= $occupiedSlots ?></span>
            </div>
            <div class="col-sm-3 border-end border-secondary-subtle">
                <span class="badge bg-secondary me-2"><i class="bi bi-slash-circle me-1"></i>Out of Service</span>
                <span class="fw-bold text-white fs-5"><?= $outOfServiceSlots ?></span>
            </div>
            <div class="col-sm-3 text-sm-end">
                <a href="<?= url('slots/index.php') ?>" class="btn btn-outline-light btn-sm">
                    <i class="bi bi-grid-3x3-gap me-1"></i>View Slot Layout
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Charts Section -->
<div class="row g-3 mb-4">
    <!-- 7-Day Revenue Trend -->
    <div class="col-lg-8">
        <div class="card-modern h-100">
            <div class="card-modern-header">
                <div>
                    <h6 class="fw-bold text-white mb-0">7-Day Revenue Trend</h6>
                    <small class="text-secondary">Daily collections in <?= htmlspecialchars(CURRENCY_CODE) ?></small>
                </div>
                <span class="badge bg-dark border border-secondary text-info">Live Trends</span>
            </div>
            <div class="card-modern-body">
                <canvas id="revenueChart" style="max-height: 280px;"></canvas>
            </div>
        </div>
    </div>

    <!-- Active Occupancy by Vehicle Type -->
    <div class="col-lg-4">
        <div class="card-modern h-100">
            <div class="card-modern-header">
                <h6 class="fw-bold text-white mb-0">Vehicle Occupancy</h6>
                <span class="badge bg-dark border border-secondary text-secondary">Currently Parked</span>
            </div>
            <div class="card-modern-body d-flex flex-column justify-content-between">
                <div style="position: relative; max-height: 200px; margin: 0 auto; width: 100%;">
                    <canvas id="occupancyDoughnut"></canvas>
                </div>
                <div class="mt-3 pt-3 border-top border-secondary-subtle">
                    <div class="row g-2 text-center">
                        <?php foreach ($vehicleTypesMap as $typeKey => $meta): 
                            $occ = 0; $tot = 0;
                            foreach ($typeOccupancyRows as $tRow) {
                                if ($tRow['vehicle_type'] === $typeKey) {
                                    $occ = (int)$tRow['occupied'];
                                    $tot = (int)$tRow['total'];
                                    break;
                                }
                            }
                        ?>
                            <div class="col-6 mb-2 text-start">
                                <div class="d-flex align-items-center mb-1">
                                    <i class="bi <?= $meta['icon'] ?> me-1 text-secondary"></i>
                                    <small class="text-secondary text-truncate"><?= $meta['short'] ?></small>
                                </div>
                                <div class="fw-bold text-white small"><?= $occ ?> / <?= $tot ?> <span class="text-muted fw-normal">slots</span></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Recent Activity Table -->
<div class="card-modern">
    <div class="card-modern-header">
        <div>
            <h6 class="fw-bold text-white mb-0">Recent Parking Activity</h6>
            <small class="text-secondary">Latest entries, ongoing sessions, and checkouts</small>
        </div>
        <div class="d-flex gap-2">
            <a href="<?= url('vehicles/active.php') ?>" class="btn btn-sm btn-dark border-secondary">
                <i class="bi bi-car-front me-1 text-primary"></i>Active Parked
            </a>
            <a href="<?= url('vehicles/history.php') ?>" class="btn btn-sm btn-dark border-secondary">
                <i class="bi bi-clock-history me-1 text-info"></i>Full History
            </a>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-custom">
            <thead>
                <tr>
                    <th>Ticket ID</th>
                    <th>Vehicle Reg.</th>
                    <th>Type</th>
                    <th>Slot</th>
                    <th>Entry Time</th>
                    <th>Status</th>
                    <th>Rate / Bill</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($recentActivities)): ?>
                    <tr>
                        <td colspan="8" class="text-center py-5 text-secondary">
                            <i class="bi bi-inbox display-6 d-block mb-2 text-muted"></i>
                            No parking activity recorded yet. Click "New Entry" to park the first vehicle.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($recentActivities as $item): 
                        $typeMeta = $vehicleTypesMap[$item['vehicle_type']] ?? ['badge' => 'badge-car', 'short' => $item['vehicle_type'], 'icon' => 'bi-car-front'];
                    ?>
                        <tr>
                            <td class="fw-semibold text-white font-monospace"><?= generate_ticket_number((int)$item['id']) ?></td>
                            <td>
                                <span class="fw-bold text-white font-monospace"><?= e($item['registration_number']) ?></span>
                            </td>
                            <td>
                                <span class="badge <?= $typeMeta['badge'] ?>">
                                    <i class="bi <?= $typeMeta['icon'] ?> me-1"></i><?= $typeMeta['short'] ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge bg-dark border border-secondary text-light">
                                    <?= e($item['floor_number']) ?> &bull; <?= e($item['slot_number']) ?>
                                </span>
                            </td>
                            <td class="small text-secondary"><?= format_datetime($item['entry_time']) ?></td>
                            <td>
                                <?php if ($item['status'] === 'ACTIVE'): ?>
                                    <span class="badge bg-danger-subtle text-danger"><i class="bi bi-clock me-1"></i>Active</span>
                                <?php else: ?>
                                    <span class="badge bg-success-subtle text-success"><i class="bi bi-check2-all me-1"></i>Completed</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($item['status'] === 'ACTIVE'): ?>
                                    <small class="text-secondary"><?= format_currency($item['rate_per_hour']) ?> / hr</small>
                                <?php else: ?>
                                    <strong class="text-success"><?= format_currency($item['amount']) ?></strong>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <a href="<?= url('vehicles/ticket.php?id=' . $item['id']) ?>" class="btn btn-outline-secondary btn-sm" title="Print Ticket">
                                    <i class="bi bi-printer"></i>
                                </a>
                                <?php if ($item['status'] === 'ACTIVE'): ?>
                                    <a href="<?= url('vehicles/checkout.php?id=' . $item['id']) ?>" class="btn btn-primary btn-sm ms-1" title="Checkout">
                                        <i class="bi bi-box-arrow-right me-1"></i>Checkout
                                    </a>
                                <?php else: ?>
                                    <a href="<?= url('payments/receipt.php?session_id=' . $item['id']) ?>" class="btn btn-outline-success btn-sm ms-1" title="Receipt">
                                        <i class="bi bi-receipt"></i>
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Chart Scripts Initialization -->
<script>
document.addEventListener('DOMContentLoaded', () => {
    // 1. Revenue 7-Day Chart
    const revCtx = document.getElementById('revenueChart');
    if (revCtx) {
        new Chart(revCtx, {
            type: 'line',
            data: {
                labels: <?= json_encode($chartLabels) ?>,
                datasets: [{
                    label: 'Revenue (<?= e(CURRENCY_CODE) ?>)',
                    data: <?= json_encode($chartRevenueData) ?>,
                    borderColor: '#6366f1',
                    backgroundColor: 'rgba(99, 102, 241, 0.15)',
                    borderWidth: 2,
                    fill: true,
                    tension: 0.35,
                    pointBackgroundColor: '#818cf8',
                    pointBorderColor: '#ffffff',
                    pointRadius: 4,
                    pointHoverRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#1e293b',
                        titleColor: '#ffffff',
                        bodyColor: '#38bdf8',
                        borderColor: 'rgba(255,255,255,0.1)',
                        borderWidth: 1,
                        padding: 10,
                        callbacks: {
                            label: (ctx) => ` <?= e(CURRENCY_SYMBOL) ?> ${ctx.raw.toFixed(2)}`
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { color: 'rgba(255, 255, 255, 0.05)' },
                        ticks: { color: '#94a3b8', font: { size: 11 } }
                    },
                    y: {
                        beginAtZero: true,
                        grid: { color: 'rgba(255, 255, 255, 0.05)' },
                        ticks: {
                            color: '#94a3b8',
                            font: { size: 11 },
                            callback: (val) => '<?= e(CURRENCY_SYMBOL) ?>' + val
                        }
                    }
                }
            }
        });
    }

    // 2. Vehicle Occupancy Doughnut Chart
    const occCtx = document.getElementById('occupancyDoughnut');
    if (occCtx) {
        const occData = <?= json_encode($typeChartData) ?>;
        const totalParked = occData.reduce((a, b) => a + b, 0);

        new Chart(occCtx, {
            type: 'doughnut',
            data: {
                labels: <?= json_encode($typeChartLabels) ?>,
                datasets: [{
                    data: totalParked > 0 ? occData : [1],
                    backgroundColor: totalParked > 0 ? ['#06b6d4', '#6366f1', '#10b981', '#f59e0b'] : ['#334155'],
                    borderWidth: 0,
                    hoverOffset: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '72%',
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#1e293b',
                        callbacks: {
                            label: (ctx) => totalParked > 0 ? ` ${ctx.label}: ${ctx.raw} parked` : ' No vehicles parked'
                        }
                    }
                }
            }
        });
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
