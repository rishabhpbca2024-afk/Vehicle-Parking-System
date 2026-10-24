<?php
/**
 * Comprehensive Parking Session History & Audit Trail
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_login();

$pdo = get_db_connection(true);

// Filter Parameters
$search = sanitize($_GET['search'] ?? '');
$filterType = sanitize($_GET['vehicle_type'] ?? '');
$filterStatus = sanitize($_GET['status'] ?? '');
$filterPaymentStatus = sanitize($_GET['payment_status'] ?? '');
$startDate = sanitize($_GET['start_date'] ?? '');
$endDate = sanitize($_GET['end_date'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

$where = ["1=1"];
$params = [];

if ($search !== '') {
    $where[] = "(v.registration_number LIKE :search OR s.slot_number LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

if ($filterType !== '') {
    $where[] = "v.vehicle_type = :vtype";
    $params[':vtype'] = $filterType;
}

if ($filterStatus !== '') {
    $where[] = "ps.status = :status";
    $params[':status'] = $filterStatus;
}

if ($filterPaymentStatus !== '') {
    $where[] = "p.status = :pay_status";
    $params[':pay_status'] = $filterPaymentStatus;
}

if ($startDate !== '') {
    $where[] = "DATE(ps.entry_time) >= :start_date";
    $params[':start_date'] = $startDate;
}

if ($endDate !== '') {
    $where[] = "DATE(ps.entry_time) <= :end_date";
    $params[':end_date'] = $endDate;
}

$whereSql = implode(' AND ', $where);

// Aggregates for filtered result
$aggStmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_count,
        COALESCE(SUM(CASE WHEN ps.status = 'COMPLETED' THEN 1 ELSE 0 END), 0) as completed_count,
        COALESCE(SUM(CASE WHEN p.status = 'PAID' THEN p.amount ELSE 0 END), 0) as total_revenue
    FROM parking_sessions ps
    JOIN vehicles v ON ps.vehicle_id = v.id
    JOIN parking_slots s ON ps.slot_id = s.id
    LEFT JOIN payments p ON ps.id = p.session_id
    WHERE {$whereSql}
");
$aggStmt->execute($params);
$agg = $aggStmt->fetch() ?: ['total_count' => 0, 'completed_count' => 0, 'total_revenue' => 0];

$totalRecords = (int)$agg['total_count'];
$totalPages = max(1, (int)ceil($totalRecords / $perPage));

// Main Query
$sql = "
    SELECT 
        ps.id,
        ps.entry_time,
        ps.exit_time,
        ps.rate_per_hour,
        ps.amount,
        ps.status as session_status,
        v.registration_number,
        v.vehicle_type,
        s.slot_number,
        s.floor_number,
        p.id as payment_id,
        p.amount as payment_amount,
        p.method as payment_method,
        p.status as payment_status
    FROM parking_sessions ps
    JOIN vehicles v ON ps.vehicle_id = v.id
    JOIN parking_slots s ON ps.slot_id = s.id
    LEFT JOIN payments p ON ps.id = p.session_id
    WHERE {$whereSql}
    ORDER BY ps.id DESC
    LIMIT {$perPage} OFFSET {$offset}
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$historyRecords = $stmt->fetchAll();

$vehicleTypesMap = get_vehicle_types();
$paymentMethods = get_payment_methods();

$pageTitle = 'Parking Sessions History';
$activeNav = 'history';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
require_once __DIR__ . '/../includes/navbar.php';
?>

<!-- Header -->
<div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3 mb-4">
    <div>
        <h4 class="fw-bold text-white mb-1">Parking History & Logs</h4>
        <p class="text-secondary small mb-0">Search and audit all completed and active parking sessions</p>
    </div>
</div>

<!-- Aggregates Bar -->
<div class="row g-3 mb-4">
    <div class="col-sm-4">
        <div class="stat-card py-3">
            <div>
                <small class="text-secondary text-uppercase" style="font-size: 0.7rem;">Matching Records</small>
                <h4 class="fw-bold text-white mb-0 mt-1"><?= $totalRecords ?></h4>
            </div>
            <div class="stat-icon primary fs-4"><i class="bi bi-list-check"></i></div>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="stat-card py-3">
            <div>
                <small class="text-secondary text-uppercase" style="font-size: 0.7rem;">Completed Sessions</small>
                <h4 class="fw-bold text-white mb-0 mt-1"><?= (int)$agg['completed_count'] ?></h4>
            </div>
            <div class="stat-icon info fs-4"><i class="bi bi-check-all"></i></div>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="stat-card py-3">
            <div>
                <small class="text-secondary text-uppercase" style="font-size: 0.7rem;">Revenue (Filtered)</small>
                <h4 class="fw-bold text-success mb-0 mt-1"><?= format_currency($agg['total_revenue']) ?></h4>
            </div>
            <div class="stat-icon success fs-4"><i class="bi bi-wallet2"></i></div>
        </div>
    </div>
</div>

<!-- Advanced Filter Form -->
<div class="card-modern mb-4">
    <div class="card-modern-body py-3">
        <form method="GET" class="row g-2 align-items-center">
            <!-- Search Text -->
            <div class="col-lg-3 col-md-6">
                <input type="text" class="form-control form-control-sm font-monospace" name="search" 
                       placeholder="Vehicle reg # or slot #..." value="<?= e($search) ?>">
            </div>

            <!-- Vehicle Type -->
            <div class="col-lg-2 col-md-3 col-sm-6">
                <select name="vehicle_type" class="form-select form-select-sm">
                    <option value="">All Vehicle Types</option>
                    <?php foreach ($vehicleTypesMap as $vk => $vm): ?>
                        <option value="<?= e($vk) ?>" <?= ($filterType === $vk) ? 'selected' : '' ?>><?= e($vm['short']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Session Status -->
            <div class="col-lg-2 col-md-3 col-sm-6">
                <select name="status" class="form-select form-select-sm">
                    <option value="">All Session Statuses</option>
                    <option value="ACTIVE" <?= ($filterStatus === 'ACTIVE') ? 'selected' : '' ?>>Active Only</option>
                    <option value="COMPLETED" <?= ($filterStatus === 'COMPLETED') ? 'selected' : '' ?>>Completed Only</option>
                </select>
            </div>

            <!-- Date Range From -->
            <div class="col-lg-2 col-md-4 col-sm-6">
                <input type="date" class="form-control form-control-sm" name="start_date" 
                       value="<?= e($startDate) ?>" placeholder="From date">
            </div>

            <!-- Date Range To -->
            <div class="col-lg-2 col-md-4 col-sm-6">
                <input type="date" class="form-control form-control-sm" name="end_date" 
                       value="<?= e($endDate) ?>" placeholder="To date">
            </div>

            <!-- Action Buttons -->
            <div class="col-lg-1 col-md-4 d-flex gap-1">
                <button type="submit" class="btn btn-dark btn-sm border-secondary w-100" title="Apply Filter">
                    <i class="bi bi-funnel"></i>
                </button>
                <a href="<?= url('vehicles/history.php') ?>" class="btn btn-outline-secondary btn-sm" title="Clear Filters">
                    <i class="bi bi-x"></i>
                </a>
            </div>
        </form>
    </div>
</div>

<!-- History Table -->
<div class="card-modern">
    <div class="table-responsive">
        <table class="table table-custom">
            <thead>
                <tr>
                    <th>Ticket ID</th>
                    <th>Vehicle Reg.</th>
                    <th>Type</th>
                    <th>Slot</th>
                    <th>Entry Time</th>
                    <th>Exit Time</th>
                    <th>Duration</th>
                    <th>Rate</th>
                    <th>Final Amount</th>
                    <th>Payment</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($historyRecords)): ?>
                    <tr>
                        <td colspan="11" class="text-center py-5 text-secondary">
                            <i class="bi bi-journal-x display-6 d-block mb-2 text-muted"></i>
                            No parking records match the selected criteria.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($historyRecords as $item): 
                        $vMeta = $vehicleTypesMap[$item['vehicle_type']] ?? ['badge' => 'badge-car', 'short' => $item['vehicle_type'], 'icon' => 'bi-car-front'];
                        $dur = calculate_parking_duration($item['entry_time'], $item['exit_time']);
                    ?>
                        <tr>
                            <td>
                                <span class="badge bg-dark border border-secondary text-info font-monospace">
                                    <?= generate_ticket_number((int)$item['id']) ?>
                                </span>
                            </td>
                            <td>
                                <span class="fw-bold text-white font-monospace"><?= e($item['registration_number']) ?></span>
                            </td>
                            <td>
                                <span class="badge <?= $vMeta['badge'] ?>">
                                    <i class="bi <?= $vMeta['icon'] ?> me-1"></i><?= $vMeta['short'] ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge bg-dark border border-secondary text-light">
                                    <?= e($item['slot_number']) ?>
                                </span>
                            </td>
                            <td class="small text-secondary"><?= format_datetime($item['entry_time']) ?></td>
                            <td class="small text-secondary"><?= $item['exit_time'] ? format_datetime($item['exit_time']) : '<span class="text-warning">— In Progress —</span>' ?></td>
                            <td>
                                <small class="text-light font-monospace"><?= $dur['formatted'] ?></small>
                            </td>
                            <td>
                                <small class="text-secondary"><?= format_currency($item['rate_per_hour']) ?>/hr</small>
                            </td>
                            <td>
                                <?php if ($item['session_status'] === 'COMPLETED'): ?>
                                    <strong class="text-success"><?= format_currency($item['amount']) ?></strong>
                                <?php else: ?>
                                    <span class="badge bg-warning-subtle text-warning">Active</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($item['payment_status'] === 'PAID'): ?>
                                    <span class="badge bg-success-subtle text-success">
                                        <i class="bi bi-check-circle me-1"></i>PAID (<?= e($item['payment_method'] ?? 'CASH') ?>)
                                    </span>
                                <?php elseif ($item['session_status'] === 'ACTIVE'): ?>
                                    <span class="badge bg-secondary">Unbilled</span>
                                <?php else: ?>
                                    <span class="badge bg-danger">Pending</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <a href="<?= url('vehicles/ticket.php?id=' . $item['id']) ?>" class="btn btn-outline-secondary btn-sm" title="View/Print Ticket">
                                    <i class="bi bi-ticket-perforated"></i>
                                </a>
                                <?php if ($item['session_status'] === 'COMPLETED'): ?>
                                    <a href="<?= url('payments/receipt.php?session_id=' . $item['id']) ?>" class="btn btn-outline-success btn-sm ms-1" title="View/Print Receipt">
                                        <i class="bi bi-receipt"></i>
                                    </a>
                                <?php else: ?>
                                    <a href="<?= url('vehicles/checkout.php?id=' . $item['id']) ?>" class="btn btn-primary btn-sm ms-1" title="Checkout">
                                        <i class="bi bi-box-arrow-right"></i>
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
        <div class="card-modern-header border-top border-secondary-subtle">
            <small class="text-secondary">Showing page <?= $page ?> of <?= $totalPages ?> (<?= $totalRecords ?> total records)</small>
            <nav aria-label="History navigation">
                <ul class="pagination pagination-sm mb-0">
                    <li class="page-item <?= ($page <= 1) ? 'disabled' : '' ?>">
                        <a class="page-link bg-dark text-secondary border-secondary" href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>&vehicle_type=<?= urlencode($filterType) ?>&status=<?= urlencode($filterStatus) ?>&start_date=<?= urlencode($startDate) ?>&end_date=<?= urlencode($endDate) ?>">Prev</a>
                    </li>
                    <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                        <li class="page-item <?= ($p === $page) ? 'active' : '' ?>">
                            <a class="page-link <?= ($p === $page) ? 'bg-primary border-primary text-white' : 'bg-dark text-secondary border-secondary' ?>" 
                               href="?page=<?= $p ?>&search=<?= urlencode($search) ?>&vehicle_type=<?= urlencode($filterType) ?>&status=<?= urlencode($filterStatus) ?>&start_date=<?= urlencode($startDate) ?>&end_date=<?= urlencode($endDate) ?>"><?= $p ?></a>
                        </li>
                    <?php endfor; ?>
                    <li class="page-item <?= ($page >= $totalPages) ? 'disabled' : '' ?>">
                        <a class="page-link bg-dark text-secondary border-secondary" href="?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>&vehicle_type=<?= urlencode($filterType) ?>&status=<?= urlencode($filterStatus) ?>&start_date=<?= urlencode($startDate) ?>&end_date=<?= urlencode($endDate) ?>">Next</a>
                    </li>
                </ul>
            </nav>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
