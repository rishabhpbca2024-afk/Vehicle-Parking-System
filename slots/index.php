<?php
/**
 * Parking Slots Management & Real-time Grid View
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

require_login();

$pdo = get_db_connection(true);

// Process Filters
$filterFloor = sanitize($_GET['floor'] ?? '');
$filterType = sanitize($_GET['vehicle_type'] ?? '');
$filterStatus = sanitize($_GET['status'] ?? '');
$searchSlot = sanitize($_GET['search'] ?? '');
$viewMode = sanitize($_GET['view'] ?? 'table'); // table or grid

// Build Query
$where = [];
$params = [];

if ($filterFloor !== '') {
    $where[] = "ps.floor_number = :floor";
    $params[':floor'] = $filterFloor;
}

if ($filterType !== '') {
    $where[] = "ps.vehicle_type = :vtype";
    $params[':vtype'] = $filterType;
}

if ($filterStatus !== '') {
    $where[] = "ps.status = :status";
    $params[':status'] = $filterStatus;
}

if ($searchSlot !== '') {
    $where[] = "ps.slot_number LIKE :search";
    $params[':search'] = '%' . $searchSlot . '%';
}

$whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

$sql = "
    SELECT 
        ps.id,
        ps.slot_number,
        ps.floor_number,
        ps.vehicle_type,
        ps.status,
        ps.created_at,
        active_sess.id as active_session_id,
        active_veh.registration_number as active_reg_number
    FROM parking_slots ps
    LEFT JOIN parking_sessions active_sess 
        ON ps.id = active_sess.slot_id AND active_sess.status = 'ACTIVE'
    LEFT JOIN vehicles active_veh 
        ON active_sess.vehicle_id = active_veh.id
    {$whereClause}
    ORDER BY ps.floor_number ASC, ps.slot_number ASC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$slots = $stmt->fetchAll();

// Fetch distinct floors for filter dropdown
$floorsStmt = $pdo->query("SELECT DISTINCT floor_number FROM parking_slots ORDER BY floor_number ASC");
$allFloors = $floorsStmt->fetchAll(PDO::FETCH_COLUMN);

$vehicleTypesMap = get_vehicle_types();
$slotStatuses = get_slot_statuses();

$pageTitle = 'Parking Slots Management';
$activeNav = 'slots';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
require_once __DIR__ . '/../includes/navbar.php';
?>

<!-- Top Actions & Header -->
<div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3 mb-4">
    <div>
        <h4 class="fw-bold text-white mb-1">Parking Slots</h4>
        <p class="text-secondary small mb-0">Configure, monitor, and manage parking slots across all floors</p>
    </div>
    <div class="d-flex gap-2">
        <!-- View Toggle -->
        <div class="btn-group" role="group">
            <a href="?view=table<?= $filterFloor ? '&floor='.urlencode($filterFloor) : '' ?><?= $filterType ? '&vehicle_type='.urlencode($filterType) : '' ?><?= $filterStatus ? '&status='.urlencode($filterStatus) : '' ?>" 
               class="btn btn-sm btn-dark border-secondary <?= $viewMode === 'table' ? 'active text-primary' : 'text-secondary' ?>" title="Table View">
                <i class="bi bi-table"></i>
            </a>
            <a href="?view=grid<?= $filterFloor ? '&floor='.urlencode($filterFloor) : '' ?><?= $filterType ? '&vehicle_type='.urlencode($filterType) : '' ?><?= $filterStatus ? '&status='.urlencode($filterStatus) : '' ?>" 
               class="btn btn-sm btn-dark border-secondary <?= $viewMode === 'grid' ? 'active text-primary' : 'text-secondary' ?>" title="Grid Layout">
                <i class="bi bi-grid-3x3-gap"></i>
            </a>
        </div>
        
        <?php if (is_admin()): ?>
            <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addSlotModal">
                <i class="bi bi-plus-lg me-1"></i>Add New Slot
            </button>
        <?php endif; ?>
    </div>
</div>

<!-- Filters Bar -->
<div class="card-modern mb-4">
    <div class="card-modern-body py-3">
        <form method="GET" class="row g-2 align-items-center">
            <input type="hidden" name="view" value="<?= e($viewMode) ?>">

            <div class="col-md-3 col-sm-6">
                <div class="input-group input-group-sm">
                    <span class="input-group-text bg-dark border-secondary text-secondary"><i class="bi bi-search"></i></span>
                    <input type="text" class="form-control form-control-sm" name="search" placeholder="Search slot #..." value="<?= e($searchSlot) ?>">
                </div>
            </div>

            <div class="col-md-3 col-sm-6">
                <select name="floor" class="form-select form-select-sm">
                    <option value="">All Floors</option>
                    <?php foreach ($allFloors as $fl): ?>
                        <option value="<?= e($fl) ?>" <?= $filterFloor === $fl ? 'selected' : '' ?>><?= e($fl) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-3 col-sm-6">
                <select name="vehicle_type" class="form-select form-select-sm">
                    <option value="">All Vehicle Types</option>
                    <?php foreach ($vehicleTypesMap as $k => $v): ?>
                        <option value="<?= e($k) ?>" <?= $filterType === $k ? 'selected' : '' ?>><?= e($v['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-2 col-sm-4">
                <select name="status" class="form-select form-select-sm">
                    <option value="">All Statuses</option>
                    <?php foreach ($slotStatuses as $sk => $sm): ?>
                        <option value="<?= e($sk) ?>" <?= $filterStatus === $sk ? 'selected' : '' ?>><?= e($sm['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-1 col-sm-2 d-flex gap-1">
                <button type="submit" class="btn btn-dark btn-sm border-secondary w-100" title="Apply Filter">
                    <i class="bi bi-funnel"></i>
                </button>
                <a href="<?= url('slots/index.php') ?>" class="btn btn-outline-secondary btn-sm" title="Clear Filters">
                    <i class="bi bi-x"></i>
                </a>
            </div>
        </form>
    </div>
</div>

<?php if ($viewMode === 'grid'): ?>
    <!-- Visual Grid View -->
    <div class="row g-3">
        <?php if (empty($slots)): ?>
            <div class="col-12 text-center py-5 text-secondary">
                <i class="bi bi-inbox display-4 d-block mb-2 text-muted"></i>
                No parking slots match the selected filters.
            </div>
        <?php else: ?>
            <?php foreach ($slots as $slot): 
                $statusClass = strtolower(str_replace('_', '-', $slot['status']));
                $vMeta = $vehicleTypesMap[$slot['vehicle_type']] ?? ['badge' => 'badge-car', 'short' => $slot['vehicle_type'], 'icon' => 'bi-car-front'];
            ?>
                <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6">
                    <div class="slot-tile <?= $statusClass ?>">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <small class="text-secondary" style="font-size: 0.72rem;"><?= e($slot['floor_number']) ?></small>
                            <span class="badge <?= $vMeta['badge'] ?> py-0 px-1" style="font-size: 0.65rem;">
                                <?= $vMeta['short'] ?>
                            </span>
                        </div>
                        <h4 class="fw-bold my-2"><?= e($slot['slot_number']) ?></h4>
                        
                        <?php if ($slot['status'] === 'OCCUPIED'): ?>
                            <div class="font-monospace fw-bold text-white small my-1">
                                <i class="bi bi-car-front-fill me-1"></i><?= e($slot['active_reg_number']) ?>
                            </div>
                            <span class="badge bg-danger text-white py-0 px-2 small">Occupied</span>
                        <?php elseif ($slot['status'] === 'AVAILABLE'): ?>
                            <span class="badge bg-success text-white py-0 px-2 small">Available</span>
                        <?php else: ?>
                            <span class="badge bg-secondary text-white py-0 px-2 small">Out of Service</span>
                        <?php endif; ?>

                        <?php if (is_admin()): ?>
                            <div class="mt-2 pt-2 border-top border-secondary-subtle d-flex justify-content-center gap-2">
                                <a href="<?= url('slots/edit.php?id=' . $slot['id']) ?>" class="btn btn-dark btn-xs text-secondary hover-light py-0 px-1" title="Edit Slot">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <?php if ($slot['status'] !== 'OCCUPIED'): ?>
                                    <form method="POST" action="<?= url('slots/toggle_status.php') ?>" class="d-inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="slot_id" value="<?= $slot['id'] ?>">
                                        <button type="submit" class="btn btn-dark btn-xs text-secondary hover-light py-0 px-1" title="Toggle Out of Service">
                                            <i class="bi bi-power"></i>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

<?php else: ?>
    <!-- Table View -->
    <div class="card-modern">
        <div class="table-responsive">
            <table class="table table-custom">
                <thead>
                    <tr>
                        <th>Floor</th>
                        <th>Slot Number</th>
                        <th>Vehicle Type</th>
                        <th>Status</th>
                        <th>Current Vehicle</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($slots)): ?>
                        <tr>
                            <td colspan="6" class="text-center py-5 text-secondary">
                                <i class="bi bi-inbox display-6 d-block mb-2 text-muted"></i>
                                No parking slots found matching your criteria.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($slots as $slot): 
                            $vMeta = $vehicleTypesMap[$slot['vehicle_type']] ?? ['badge' => 'badge-car', 'short' => $slot['vehicle_type'], 'icon' => 'bi-car-front'];
                            $sMeta = $slotStatuses[$slot['status']] ?? ['badge' => 'bg-secondary', 'label' => $slot['status']];
                        ?>
                            <tr>
                                <td class="fw-semibold text-white"><?= e($slot['floor_number']) ?></td>
                                <td>
                                    <span class="badge bg-dark border border-secondary text-info fs-6 font-monospace px-2 py-1">
                                        <?= e($slot['slot_number']) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge <?= $vMeta['badge'] ?>">
                                        <i class="bi <?= $vMeta['icon'] ?> me-1"></i><?= $vMeta['short'] ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge <?= $sMeta['badge'] ?>">
                                        <?= $sMeta['label'] ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($slot['status'] === 'OCCUPIED' && !empty($slot['active_reg_number'])): ?>
                                        <a href="<?= url('vehicles/checkout.php?id=' . $slot['active_session_id']) ?>" class="text-info fw-bold font-monospace text-decoration-none">
                                            <i class="bi bi-car-front-fill me-1"></i><?= e($slot['active_reg_number']) ?>
                                        </a>
                                    <?php else: ?>
                                        <span class="text-secondary">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <?php if (is_admin()): ?>
                                        <div class="d-inline-flex gap-1">
                                            <a href="<?= url('slots/edit.php?id=' . $slot['id']) ?>" class="btn btn-outline-secondary btn-sm" title="Edit Slot">
                                                <i class="bi bi-pencil"></i>
                                            </a>

                                            <?php if ($slot['status'] !== 'OCCUPIED'): ?>
                                                <!-- Toggle Out of Service -->
                                                <form method="POST" action="<?= url('slots/toggle_status.php') ?>" class="d-inline">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="slot_id" value="<?= $slot['id'] ?>">
                                                    <button type="submit" class="btn btn-outline-warning btn-sm" title="<?= $slot['status'] === 'AVAILABLE' ? 'Mark Out of Service' : 'Return to Service' ?>">
                                                        <i class="bi bi-power"></i>
                                                    </button>
                                                </form>

                                                <!-- Delete Slot (Prevented if occupied or has history) -->
                                                <form method="POST" action="<?= url('slots/delete.php') ?>" class="d-inline" data-confirm="Are you sure you want to delete slot <?= e($slot['slot_number']) ?>?">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="slot_id" value="<?= $slot['id'] ?>">
                                                    <button type="submit" class="btn btn-outline-danger btn-sm" title="Delete Slot">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <button class="btn btn-outline-secondary btn-sm opacity-50" disabled title="Slot is occupied and cannot be modified or deleted">
                                                    <i class="bi bi-lock-fill"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-secondary small">View Only</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<!-- Add Slot Modal (Admin only) -->
<?php if (is_admin()): ?>
<div class="modal fade" id="addSlotModal" tabindex="-1" aria-labelledby="addSlotModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content bg-dark text-white border-secondary">
            <div class="modal-header border-secondary">
                <h5 class="modal-title fw-bold" id="addSlotModalLabel"><i class="bi bi-plus-circle me-2 text-primary"></i>Add Parking Slot</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="<?= url('slots/create.php') ?>">
                <?= csrf_field() ?>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="floor_number" class="form-label">Floor / Level</label>
                        <input type="text" class="form-control" id="floor_number" name="floor_number" required placeholder="e.g. Ground, 1st Floor, Basement 1">
                        <small class="text-secondary">E.g., "Ground", "Floor 1", "Basement"</small>
                    </div>

                    <div class="mb-3">
                        <label for="slot_number" class="form-label">Slot Identifier / Number</label>
                        <input type="text" class="form-control" id="slot_number" name="slot_number" required placeholder="e.g. A-101, G-01, TW-05">
                        <small class="text-secondary">Unique for this floor</small>
                    </div>

                    <div class="mb-3">
                        <label for="vehicle_type" class="form-label">Vehicle Compatibility</label>
                        <select class="form-select" id="vehicle_type" name="vehicle_type" required>
                            <?php foreach ($vehicleTypesMap as $vtKey => $vtMeta): ?>
                                <option value="<?= e($vtKey) ?>"><?= e($vtMeta['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-check-lg me-1"></i>Create Slot</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
