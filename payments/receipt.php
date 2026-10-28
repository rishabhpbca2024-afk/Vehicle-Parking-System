<?php
/**
 * Printable Official Payment Receipt View
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_login();

$sessionId = isset($_GET['session_id']) ? (int)$_GET['session_id'] : 0;
if ($sessionId <= 0) {
    redirect('vehicles/history.php');
}

$pdo = get_db_connection(true);

$stmt = $pdo->prepare("
    SELECT 
        ps.id as session_id,
        ps.entry_time,
        ps.exit_time,
        ps.rate_per_hour,
        ps.amount as session_amount,
        ps.status as session_status,
        v.registration_number,
        v.vehicle_type,
        s.slot_number,
        s.floor_number,
        p.id as payment_id,
        p.amount as payment_amount,
        p.method as payment_method,
        p.status as payment_status,
        p.paid_at,
        u.name as operator_name,
        u.role as operator_role
    FROM parking_sessions ps
    JOIN vehicles v ON ps.vehicle_id = v.id
    JOIN parking_slots s ON ps.slot_id = s.id
    LEFT JOIN payments p ON ps.id = p.session_id
    LEFT JOIN users u ON ps.created_by = u.id
    WHERE ps.id = :sid
    LIMIT 1
");
$stmt->execute([':sid' => $sessionId]);
$receipt = $stmt->fetch();

if (!$receipt) {
    set_flash('danger', 'Payment receipt record not found.');
    redirect('vehicles/history.php');
}

$roleRaw = strtoupper($receipt['operator_role'] ?? 'OPERATOR');
$roleLabel = ($roleRaw === 'ADMIN') ? 'Admin' : (($roleRaw === 'OPERATOR') ? 'Operator' : ucfirst(strtolower($roleRaw)));
$issuerName = $receipt['operator_name'] ?? 'Counter';

$duration = calculate_parking_duration($receipt['entry_time'], $receipt['exit_time']);
$vehicleTypesMap = get_vehicle_types();
$paymentMethods = get_payment_methods();

$vMeta = $vehicleTypesMap[$receipt['vehicle_type']] ?? ['short' => $receipt['vehicle_type'], 'label' => $receipt['vehicle_type']];
$mMeta = $paymentMethods[$receipt['payment_method'] ?? 'CASH'] ?? ['label' => $receipt['payment_method'] ?? 'Cash'];

$pageTitle = 'Payment Receipt — ' . generate_ticket_number((int)$receipt['session_id']);
$activeNav = 'payments';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
require_once __DIR__ . '/../includes/navbar.php';
?>

<div class="row justify-content-center">
    <div class="col-md-6 col-lg-5">
        <!-- Action Buttons (Hidden during printing) -->
        <div class="d-flex justify-content-between align-items-center mb-3 no-print">
            <a href="<?= url('vehicles/history.php') ?>" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i>Parking History
            </a>
            <div class="d-flex gap-2">
                <a href="<?= url('vehicles/entry.php') ?>" class="btn btn-dark btn-sm border-secondary">
                    <i class="bi bi-plus-circle me-1"></i>New Entry
                </a>
                <button type="button" class="btn btn-primary btn-sm" data-action="print">
                    <i class="bi bi-printer me-1"></i>Print Receipt
                </button>
            </div>
        </div>

        <!-- Printable Receipt Card -->
        <div class="card-modern printable-receipt-card text-center p-4">
            <div class="border-bottom border-secondary-subtle pb-3 mb-3">
                <div class="d-flex align-items-center justify-content-center gap-2 mb-1">
                    <i class="bi bi-p-square-fill fs-3 text-success"></i>
                    <h5 class="fw-bold mb-0 receipt-data-text"><?= htmlspecialchars(APP_NAME) ?></h5>
                </div>
                <small class="text-secondary">Official Parking Payment Tax Invoice</small>
            </div>

            <!-- Receipt & Ticket IDs -->
            <div class="d-flex justify-content-between align-items-center py-2 px-3 bg-dark rounded border border-secondary mb-3 small">
                <div class="text-start">
                    <span class="text-secondary d-block">Receipt #</span>
                    <strong class="text-white font-monospace">RCP-<?= str_pad((string)($receipt['payment_id'] ?? $receipt['session_id']), 6, '0', STR_PAD_LEFT) ?></strong>
                </div>
                <div class="text-end">
                    <span class="text-secondary d-block">Ticket #</span>
                    <strong class="text-info font-monospace"><?= generate_ticket_number((int)$receipt['session_id']) ?></strong>
                </div>
            </div>

            <!-- Vehicle Summary -->
            <div class="text-start mb-3">
                <div class="d-flex justify-content-between py-1 border-bottom border-secondary-subtle small">
                    <span class="text-secondary">Vehicle Number:</span>
                    <strong class="receipt-data-text font-monospace fs-6"><?= e($receipt['registration_number']) ?></strong>
                </div>
                <div class="d-flex justify-content-between py-1 border-bottom border-secondary-subtle small">
                    <span class="text-secondary">Vehicle Category:</span>
                    <span class="receipt-data-text"><?= e($vMeta['label']) ?></span>
                </div>
                <div class="d-flex justify-content-between py-1 border-bottom border-secondary-subtle small">
                    <span class="text-secondary">Slot & Level:</span>
                    <span class="text-warning font-monospace fw-bold"><?= e($receipt['slot_number']) ?> (<?= e($receipt['floor_number']) ?>)</span>
                </div>
            </div>

            <!-- Timings Breakdown -->
            <div class="text-start mb-3">
                <div class="d-flex justify-content-between py-1 border-bottom border-secondary-subtle small">
                    <span class="text-secondary">Check-In:</span>
                    <span class="receipt-data-text"><?= format_datetime($receipt['entry_time']) ?></span>
                </div>
                <div class="d-flex justify-content-between py-1 border-bottom border-secondary-subtle small">
                    <span class="text-secondary">Check-Out:</span>
                    <span class="receipt-data-text"><?= format_datetime($receipt['exit_time']) ?></span>
                </div>
                <div class="d-flex justify-content-between py-1 border-bottom border-secondary-subtle small">
                    <span class="text-secondary">Total Duration:</span>
                    <strong class="text-info font-monospace"><?= $duration['formatted'] ?></strong>
                </div>
                <div class="d-flex justify-content-between py-1 border-bottom border-secondary-subtle small">
                    <span class="text-secondary">Applied Tariff Rate:</span>
                    <span class="receipt-data-text"><?= format_currency($receipt['rate_per_hour']) ?> / hour</span>
                </div>
            </div>

            <!-- Total Paid Box -->
            <div class="p-3 bg-dark rounded border border-success my-3">
                <div class="d-flex justify-content-between align-items-center">
                    <span class="text-uppercase fw-bold text-secondary">Total Paid:</span>
                    <span class="fs-3 fw-bold text-success font-monospace">
                        <?= format_currency($receipt['payment_amount'] ?? $receipt['session_amount']) ?>
                    </span>
                </div>
                <div class="d-flex justify-content-between align-items-center mt-2 pt-2 border-top border-secondary small">
                    <span class="text-secondary">Payment Method:</span>
                    <span class="badge bg-secondary"><?= e($mMeta['label']) ?></span>
                </div>
                <div class="d-flex justify-content-between align-items-center mt-1 small">
                    <span class="text-secondary">Transaction Status:</span>
                    <span class="badge bg-success-subtle text-success"><i class="bi bi-check-circle me-1"></i>PAID</span>
                </div>
            </div>

            <!-- Payment / Receipt QR Code -->
            <?php 
                $qrExtensions = ['png', 'jpg', 'jpeg', 'webp'];
                $qrSrc = null;
                foreach ($qrExtensions as $ext) {
                    $testPath = __DIR__ . '/../assets/images/payment_qr.' . $ext;
                    if (file_exists($testPath)) {
                        $qrSrc = asset('images/payment_qr.' . $ext);
                        break;
                    }
                }
            ?>
            <?php if ($qrSrc): ?>
                <div class="my-3 p-2 bg-white rounded text-dark d-inline-block border shadow-sm">
                    <img src="<?= $qrSrc ?>" alt="Payment QR Code" style="width: 130px; height: 130px; object-fit: contain;" class="d-block mx-auto mb-1">
                    <small class="fw-bold d-block text-muted" style="font-size: 0.68rem; letter-spacing: 0.5px;">SCAN TO PAY / VERIFY</small>
                </div>
            <?php endif; ?>

            <!-- Operator and Barcode Notice -->
            <div class="text-secondary small mt-3" style="font-size: 0.75rem;">
                <p class="mb-1">Issued by <?= e($roleLabel) ?>: <strong><?= e($issuerName) ?></strong></p>
                <div class="font-monospace text-secondary my-1" style="letter-spacing: 3px;">
                    ||| | ||||| || |||||| | |||
                </div>
                <p class="mb-0">Thank you for parking with us! Have a safe journey.</p>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
