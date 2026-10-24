<?php
/**
 * Active Parked Vehicles Real-Time Roster
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_login();

$pdo = get_db_connection(true);

// Search & Filter parameters
$search = sanitize($_GET['search'] ?? '');
$filterType = sanitize($_GET['vehicle_type'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;
$offset = ($page - 1) * $perPage;

$where = ["ps.status = 'ACTIVE'"];
$params = [];

if ($search !== '') {
    $where[] = "(v.registration_number LIKE :search OR s.slot_number LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

if ($filterType !== '') {
    $where[] = "v.vehicle_type = :vtype";
    $params[':vtype'] = $filterType;
}

$whereSql = implode(' AND ', $where);

// Total count for pagination
$countStmt = $pdo->prepare("
    SELECT COUNT(*) 
    FROM parking_sessions ps
    JOIN vehicles v ON ps.vehicle_id = v.id
    JOIN parking_slots s ON ps.slot_id = s.id
    WHERE {$whereSql}
");
$countStmt->execute($params);
$totalRecords = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRecords / $perPage));

// Fetch active sessions
$sql = "
    SELECT 
        ps.id,
        ps.entry_time,
        ps.rate_per_hour,
        v.registration_number,
        v.vehicle_type,
        s.slot_number,
        s.floor_number
    FROM parking_sessions ps
    JOIN vehicles v ON ps.vehicle_id = v.id
    JOIN parking_slots s ON ps.slot_id = s.id
    WHERE {$whereSql}
    ORDER BY ps.entry_time ASC
    LIMIT {$perPage} OFFSET {$offset}
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$activeSessions = $stmt->fetchAll();

$vehicleTypesMap = get_vehicle_types();

$pageTitle = 'Currently Parked Vehicles';
$activeNav = 'active';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
require_once __DIR__ . '/../includes/navbar.php';
?>

<!-- Header & Quick Actions -->
<div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3 mb-4">
    <div>
        <h4 class="fw-bold text-white mb-1">Active Parking Sessions</h4>
        <p class="text-secondary small mb-0">Total of <strong><?= $totalRecords ?></strong> vehicles currently inside the premises</p>
    </div>
    <div>
        <a href="<?= url('vehicles/entry.php') ?>" class="btn btn-primary btn-sm">
            <i class="bi bi-plus-circle me-1"></i>New Vehicle Entry
        </a>
    </div>
</div>

<!-- Search & Filter Card -->
<div class="card-modern mb-4">
    <div class="card-modern-body py-3">
        <form method="GET" class="row g-2 align-items-center">
            <div class="col-md-5 col-sm-8">
                <div class="input-group input-group-sm">
                    <span class="input-group-text bg-dark border-secondary text-secondary"><i class="bi bi-search"></i></span>
                    <input type="text" class="form-control form-control-sm font-monospace" name="search" 
                           placeholder="Search vehicle reg # or slot #..." value="<?= e($search) ?>">
                </div>
            </div>

            <div class="col-md-4 col-sm-4">
                <select name="vehicle_type" class="form-select form-select-sm">
                    <option value="">All Vehicle Types</option>
                    <?php foreach ($vehicleTypesMap as $vk => $vm): ?>
                        <option value="<?= e($vk) ?>" <?= $filterType === $vk ? 'selected' : '' ?>><?= e($vm['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-dark btn-sm border-secondary flex-grow-1">
                    <i class="bi bi-funnel me-1"></i>Filter
                </button>
                <a href="<?= url('vehicles/active.php') ?>" class="btn btn-outline-secondary btn-sm" title="Clear">
                    <i class="bi bi-x-lg"></i>
                </a>
            </div>
        </form>
    </div>
</div>

<!-- Active Vehicles Table -->
<div class="card-modern">
    <div class="table-responsive">
        <table class="table table-custom">
            <thead>
                <tr>
                    <th>Ticket ID</th>
                    <th>Vehicle Reg.</th>
                    <th>Type</th>
                    <th>Slot & Floor</th>
                    <th>Entry Timestamp</th>
                    <th>Elapsed Time</th>
                    <th>Current Est. Bill</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($activeSessions)): ?>
                    <tr>
                        <td colspan="8" class="text-center py-5 text-secondary">
                            <i class="bi bi-car-front display-6 d-block mb-2 text-muted"></i>
                            No active parked vehicles matching your search criteria.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($activeSessions as $sess): 
                        $vMeta = $vehicleTypesMap[$sess['vehicle_type']] ?? ['badge' => 'badge-car', 'short' => $sess['vehicle_type'], 'icon' => 'bi-car-front'];
                        // Real-time calculation using entry time and current time
                        $charge = calculate_parking_charge($sess['entry_time'], null, (float)$sess['rate_per_hour']);
                    ?>
                        <tr>
                            <td>
                                <span class="badge bg-dark border border-secondary text-info font-monospace">
                                    <?= generate_ticket_number((int)$sess['id']) ?>
                                </span>
                            </td>
                            <td>
                                <span class="fw-bold text-white font-monospace fs-6"><?= e($sess['registration_number']) ?></span>
                            </td>
                            <td>
                                <span class="badge <?= $vMeta['badge'] ?>">
                                    <i class="bi <?= $vMeta['icon'] ?> me-1"></i><?= $vMeta['short'] ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge bg-dark border border-secondary text-light">
                                    <i class="bi bi-p-square me-1 text-warning"></i><?= e($sess['slot_number']) ?> 
                                    <small class="text-secondary">(<?= e($sess['floor_number']) ?>)</small>
                                </span>
                            </td>
                            <td class="small text-secondary"><?= format_datetime($sess['entry_time']) ?></td>
                            <td>
                                <span class="badge bg-dark text-info border border-secondary font-monospace">
                                    <i class="bi bi-stopwatch me-1"></i><?= $charge['duration_text'] ?>
                                </span>
                            </td>
                            <td>
                                <span class="fw-bold text-success font-monospace fs-6">
                                    <?= format_currency($charge['amount']) ?>
                                </span>
                                <small class="text-muted d-block" style="font-size: 0.72rem;">@ <?= format_currency($sess['rate_per_hour']) ?>/hr</small>
                            </td>
                            <td class="text-end">
                                <a href="<?= url('vehicles/ticket.php?id=' . $sess['id']) ?>" class="btn btn-outline-secondary btn-sm" title="Print Parking Ticket">
                                    <i class="bi bi-printer"></i>
                                </a>
                                <a href="<?= url('vehicles/checkout.php?id=' . $sess['id']) ?>" class="btn btn-primary btn-sm ms-1">
                                    <i class="bi bi-box-arrow-right me-1"></i>Checkout
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
            <small class="text-secondary">Showing page <?= $page ?> of <?= $totalPages ?> (Total: <?= $totalRecords ?>)</small>
            <nav aria-label="Page navigation">
                <ul class="pagination pagination-sm mb-0">
                    <li class="page-item <?= ($page <= 1) ? 'disabled' : '' ?>">
                        <a class="page-link bg-dark text-secondary border-secondary" href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>&vehicle_type=<?= urlencode($filterType) ?>">Prev</a>
                    </li>
                    <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                        <li class="page-item <?= ($p === $page) ? 'active' : '' ?>">
                            <a class="page-link <?= ($p === $page) ? 'bg-primary border-primary text-white' : 'bg-dark text-secondary border-secondary' ?>" 
                               href="?page=<?= $p ?>&search=<?= urlencode($search) ?>&vehicle_type=<?= urlencode($filterType) ?>"><?= $p ?></a>
                        </li>
                    <?php endfor; ?>
                    <li class="page-item <?= ($page >= $totalPages) ? 'disabled' : '' ?>">
                        <a class="page-link bg-dark text-secondary border-secondary" href="?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>&vehicle_type=<?= urlencode($filterType) ?>">Next</a>
                    </li>
                </ul>
            </nav>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
