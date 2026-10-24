<?php
/**
 * Financial Payments Transaction Log
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_login();

$pdo = get_db_connection(true);

// Filters
$filterMethod = sanitize($_GET['method'] ?? '');
$filterDate = sanitize($_GET['date'] ?? '');
$search = sanitize($_GET['search'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

$where = ["p.status = 'PAID'"];
$params = [];

if ($filterMethod !== '') {
    $where[] = "p.method = :method";
    $params[':method'] = $filterMethod;
}

if ($filterDate !== '') {
    $where[] = "DATE(p.paid_at) = :date";
    $params[':date'] = $filterDate;
}

if ($search !== '') {
    $where[] = "(v.registration_number LIKE :search OR p.id = :search_id)";
    $params[':search'] = '%' . $search . '%';
    $params[':search_id'] = (int)$search;
}

$whereSql = implode(' AND ', $where);

// Aggregates
$sumStmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_payments,
        COALESCE(SUM(p.amount), 0) as total_amount
    FROM payments p
    JOIN parking_sessions ps ON p.session_id = ps.id
    JOIN vehicles v ON ps.vehicle_id = v.id
    WHERE {$whereSql}
");
$sumStmt->execute($params);
$totals = $sumStmt->fetch() ?: ['total_payments' => 0, 'total_amount' => 0];

$totalRecords = (int)$totals['total_payments'];
$totalPages = max(1, (int)ceil($totalRecords / $perPage));

// Main Query
$sql = "
    SELECT 
        p.id as payment_id,
        p.amount,
        p.method,
        p.status,
        p.paid_at,
        ps.id as session_id,
        ps.entry_time,
        ps.exit_time,
        v.registration_number,
        v.vehicle_type,
        s.slot_number,
        u.name as operator_name
    FROM payments p
    JOIN parking_sessions ps ON p.session_id = ps.id
    JOIN vehicles v ON ps.vehicle_id = v.id
    JOIN parking_slots s ON ps.slot_id = s.id
    LEFT JOIN users u ON ps.created_by = u.id
    WHERE {$whereSql}
    ORDER BY p.paid_at DESC
    LIMIT {$perPage} OFFSET {$offset}
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$payments = $stmt->fetchAll();

$paymentMethods = get_payment_methods();
$vehicleTypesMap = get_vehicle_types();

$pageTitle = 'Payments Transaction Log';
$activeNav = 'payments';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
require_once __DIR__ . '/../includes/navbar.php';
?>

<!-- Header -->
<div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3 mb-4">
    <div>
        <h4 class="fw-bold text-white mb-1">Financial Payments Log</h4>
        <p class="text-secondary small mb-0">Audited transaction records for collected parking fees</p>
    </div>
</div>

<!-- Summary Cards -->
<div class="row g-3 mb-4">
    <div class="col-md-6">
        <div class="stat-card">
            <div>
                <small class="text-secondary text-uppercase fw-semibold" style="font-size: 0.72rem;">Total Successful Transactions</small>
                <h3 class="fw-bold text-white mb-0 mt-1"><?= $totalRecords ?></h3>
                <small class="text-muted">Filtered payment count</small>
            </div>
            <div class="stat-icon primary"><i class="bi bi-receipt-cutoff"></i></div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="stat-card">
            <div>
                <small class="text-secondary text-uppercase fw-semibold" style="font-size: 0.72rem;">Total Collected Revenue</small>
                <h3 class="fw-bold text-success mb-0 mt-1"><?= format_currency($totals['total_amount']) ?></h3>
                <small class="text-success"><i class="bi bi-shield-check me-1"></i>Verified PAID receipts</small>
            </div>
            <div class="stat-icon success"><i class="bi bi-cash-coin"></i></div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card-modern mb-4">
    <div class="card-modern-body py-3">
        <form method="GET" class="row g-2 align-items-center">
            <div class="col-md-4">
                <input type="text" class="form-control form-control-sm font-monospace" name="search" 
                       placeholder="Vehicle reg # or receipt #..." value="<?= e($search) ?>">
            </div>

            <div class="col-md-3 col-sm-6">
                <select name="method" class="form-select form-select-sm">
                    <option value="">All Payment Methods</option>
                    <?php foreach ($paymentMethods as $pmKey => $pm): ?>
                        <option value="<?= e($pmKey) ?>" <?= ($filterMethod === $pmKey) ? 'selected' : '' ?>><?= e($pm['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-3 col-sm-6">
                <input type="date" class="form-control form-control-sm" name="date" value="<?= e($filterDate) ?>" placeholder="Filter by date">
            </div>

            <div class="col-md-2 d-flex gap-1">
                <button type="submit" class="btn btn-dark btn-sm border-secondary flex-grow-1"><i class="bi bi-funnel me-1"></i>Filter</button>
                <a href="<?= url('payments/index.php') ?>" class="btn btn-outline-secondary btn-sm" title="Clear"><i class="bi bi-x-lg"></i></a>
            </div>
        </form>
    </div>
</div>

<!-- Payments Table -->
<div class="card-modern">
    <div class="table-responsive">
        <table class="table table-custom">
            <thead>
                <tr>
                    <th>Receipt #</th>
                    <th>Ticket ID</th>
                    <th>Vehicle Reg.</th>
                    <th>Slot</th>
                    <th>Method</th>
                    <th>Paid Timestamp</th>
                    <th>Operator</th>
                    <th>Amount</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($payments)): ?>
                    <tr>
                        <td colspan="9" class="text-center py-5 text-secondary">
                            <i class="bi bi-receipt display-6 d-block mb-2 text-muted"></i>
                            No payment transactions recorded for the given filters.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($payments as $pay): 
                        $pm = $paymentMethods[$pay['method']] ?? ['badge' => 'bg-secondary', 'label' => $pay['method']];
                    ?>
                        <tr>
                            <td>
                                <strong class="text-white font-monospace">RCP-<?= str_pad((string)$pay['payment_id'], 6, '0', STR_PAD_LEFT) ?></strong>
                            </td>
                            <td>
                                <span class="badge bg-dark border border-secondary text-info font-monospace">
                                    <?= generate_ticket_number((int)$pay['session_id']) ?>
                                </span>
                            </td>
                            <td>
                                <span class="fw-bold text-white font-monospace"><?= e($pay['registration_number']) ?></span>
                            </td>
                            <td>
                                <span class="badge bg-dark border border-secondary text-light"><?= e($pay['slot_number']) ?></span>
                            </td>
                            <td>
                                <span class="badge <?= $pm['badge'] ?>">
                                    <?= e($pm['label']) ?>
                                </span>
                            </td>
                            <td class="small text-secondary"><?= format_datetime($pay['paid_at']) ?></td>
                            <td class="small text-white"><?= e($pay['operator_name'] ?? 'System') ?></td>
                            <td>
                                <strong class="text-success font-monospace fs-6"><?= format_currency($pay['amount']) ?></strong>
                            </td>
                            <td class="text-end">
                                <a href="<?= url('payments/receipt.php?session_id=' . $pay['session_id']) ?>" class="btn btn-outline-success btn-sm" title="View Full Receipt">
                                    <i class="bi bi-receipt me-1"></i>Receipt
                                </a>
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
            <small class="text-secondary">Page <?= $page ?> of <?= $totalPages ?></small>
            <nav>
                <ul class="pagination pagination-sm mb-0">
                    <li class="page-item <?= ($page <= 1) ? 'disabled' : '' ?>">
                        <a class="page-link bg-dark text-secondary border-secondary" href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>&method=<?= urlencode($filterMethod) ?>&date=<?= urlencode($filterDate) ?>">Prev</a>
                    </li>
                    <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                        <li class="page-item <?= ($p === $page) ? 'active' : '' ?>">
                            <a class="page-link <?= ($p === $page) ? 'bg-primary border-primary text-white' : 'bg-dark text-secondary border-secondary' ?>" 
                               href="?page=<?= $p ?>&search=<?= urlencode($search) ?>&method=<?= urlencode($filterMethod) ?>&date=<?= urlencode($filterDate) ?>"><?= $p ?></a>
                        </li>
                    <?php endfor; ?>
                    <li class="page-item <?= ($page >= $totalPages) ? 'disabled' : '' ?>">
                        <a class="page-link bg-dark text-secondary border-secondary" href="?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>&method=<?= urlencode($filterMethod) ?>&date=<?= urlencode($filterDate) ?>">Next</a>
                    </li>
                </ul>
            </nav>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
