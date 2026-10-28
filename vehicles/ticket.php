<?php
/**
 * Printable Vehicle Entry Ticket View
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_login();

$sessionId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($sessionId <= 0) {
    redirect('vehicles/entry.php');
}

$pdo = get_db_connection(true);

$stmt = $pdo->prepare("
    SELECT 
        ps.id,
        ps.entry_time,
        ps.rate_per_hour,
        ps.status,
        v.registration_number,
        v.vehicle_type,
        s.slot_number,
        s.floor_number,
        u.name as operator_name,
        u.role as operator_role
    FROM parking_sessions ps
    JOIN vehicles v ON ps.vehicle_id = v.id
    JOIN parking_slots s ON ps.slot_id = s.id
    LEFT JOIN users u ON ps.created_by = u.id
    WHERE ps.id = :id
    LIMIT 1
");
$stmt->execute([':id' => $sessionId]);
$ticket = $stmt->fetch();

if (!$ticket) {
    set_flash('danger', 'Parking session ticket not found.');
    redirect('vehicles/active.php');
}

$roleRaw = strtoupper($ticket['operator_role'] ?? 'OPERATOR');
$roleLabel = ($roleRaw === 'ADMIN') ? 'Admin' : (($roleRaw === 'OPERATOR') ? 'Operator' : ucfirst(strtolower($roleRaw)));
$issuerName = $ticket['operator_name'] ?? 'System';

$vehicleTypesMap = get_vehicle_types();
$vMeta = $vehicleTypesMap[$ticket['vehicle_type']] ?? ['short' => $ticket['vehicle_type'], 'label' => $ticket['vehicle_type']];

$pageTitle = 'Parking Ticket #' . generate_ticket_number((int)$ticket['id']);
$activeNav = 'entry';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
require_once __DIR__ . '/../includes/navbar.php';
?>

<div class="row justify-content-center">
    <div class="col-md-6 col-lg-5">
        <!-- Actions Toolbar (Hidden during print) -->
        <div class="d-flex justify-content-between align-items-center mb-3 no-print">
            <a href="<?= url('vehicles/entry.php') ?>" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i>New Entry
            </a>
            <div class="d-flex gap-2">
                <a href="<?= url('vehicles/active.php') ?>" class="btn btn-dark btn-sm border-secondary">
                    <i class="bi bi-clock-history me-1"></i>Active Parked
                </a>
                <button type="button" class="btn btn-primary btn-sm" data-action="print">
                    <i class="bi bi-printer me-1"></i>Print Ticket
                </button>
            </div>
        </div>

        <!-- Printable Parking Ticket Card -->
        <div class="card-modern printable-ticket-card text-center p-4">
            <div class="border-bottom border-secondary-subtle pb-3 mb-3">
                <div class="d-flex align-items-center justify-content-center gap-2 mb-1">
                    <i class="bi bi-p-square-fill fs-3 text-primary"></i>
                    <h5 class="fw-bold mb-0 receipt-data-text"><?= htmlspecialchars(APP_NAME) ?></h5>
                </div>
                <small class="text-secondary">Official Parking Access Ticket</small>
            </div>

            <!-- Ticket ID & Barcode simulation -->
            <div class="my-2">
                <span class="badge bg-dark border border-secondary text-info fs-6 font-monospace px-3 py-1">
                    <?= generate_ticket_number((int)$ticket['id']) ?>
                </span>
                <div class="font-monospace text-secondary mt-1" style="letter-spacing: 4px; font-size: 0.85rem;">
                    |||||| | |||| ||| ||||||| ||| |||
                </div>
            </div>

            <!-- Vehicle & Slot Primary Details -->
            <div class="bg-dark p-3 rounded border border-secondary my-3 text-start">
                <div class="row g-2">
                    <div class="col-6">
                        <small class="text-secondary d-block">Vehicle Number</small>
                        <span class="fw-bold fs-5 text-white font-monospace"><?= e($ticket['registration_number']) ?></span>
                    </div>
                    <div class="col-6 text-end">
                        <small class="text-secondary d-block">Vehicle Type</small>
                        <span class="fw-semibold text-info"><?= e($vMeta['short']) ?></span>
                    </div>
                    <div class="col-6 border-top border-secondary pt-2">
                        <small class="text-secondary d-block">Assigned Slot</small>
                        <span class="fw-bold fs-4 text-warning font-monospace"><?= e($ticket['slot_number']) ?></span>
                    </div>
                    <div class="col-6 text-end border-top border-secondary pt-2">
                        <small class="text-secondary d-block">Floor / Level</small>
                        <span class="fw-semibold text-white"><?= e($ticket['floor_number']) ?></span>
                    </div>
                </div>
            </div>

            <!-- Check-in Timings & Rate -->
            <div class="text-start small mb-3">
                <div class="d-flex justify-content-between py-1 border-bottom border-secondary-subtle">
                    <span class="text-secondary">Entry Timestamp:</span>
                    <strong class="receipt-data-text"><?= format_datetime($ticket['entry_time']) ?></strong>
                </div>
                <div class="d-flex justify-content-between py-1 border-bottom border-secondary-subtle">
                    <span class="text-secondary">Applicable Tariff:</span>
                    <strong class="text-success"><?= format_currency($ticket['rate_per_hour']) ?> / hour</strong>
                </div>
                <div class="d-flex justify-content-between py-1 border-bottom border-secondary-subtle">
                    <span class="text-secondary">Issued By <?= e($roleLabel) ?>:</span>
                    <span class="receipt-data-text fw-bold"><?= e($issuerName) ?></span>
                </div>
            </div>

            <!-- Footer Notice -->
            <div class="pt-2 text-secondary" style="font-size: 0.75rem;">
                <p class="mb-1">Please keep this ticket with you. Present this ticket at exit counter for billing and checkout.</p>
                <p class="mb-0">&bull; Parking at owner's risk &bull; Lost ticket charges may apply &bull;</p>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
