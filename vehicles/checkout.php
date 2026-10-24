<?php
/**
 * Vehicle Exit, Billing Calculation & Payment Finalization Console
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

require_login();

$sessionId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($sessionId <= 0) {
    redirect('vehicles/active.php');
}

$pdo = get_db_connection(true);

// Fetch Session
$stmt = $pdo->prepare("
    SELECT 
        ps.id,
        ps.entry_time,
        ps.exit_time,
        ps.rate_per_hour,
        ps.amount,
        ps.status,
        v.registration_number,
        v.vehicle_type,
        s.id as slot_id,
        s.slot_number,
        s.floor_number
    FROM parking_sessions ps
    JOIN vehicles v ON ps.vehicle_id = v.id
    JOIN parking_slots s ON ps.slot_id = s.id
    WHERE ps.id = :id
    LIMIT 1
");
$stmt->execute([':id' => $sessionId]);
$session = $stmt->fetch();

if (!$session) {
    set_flash('danger', 'Parking session not found.');
    redirect('vehicles/active.php');
}

// If already completed, redirect to receipt
if ($session['status'] === 'COMPLETED') {
    set_flash('info', 'This parking session has already been checked out and completed.');
    redirect('payments/receipt.php?session_id=' . $sessionId);
}

$paymentMethods = get_payment_methods();
$vehicleTypesMap = get_vehicle_types();
$vMeta = $vehicleTypesMap[$session['vehicle_type']] ?? ['short' => $session['vehicle_type'], 'label' => $session['vehicle_type']];

$errors = [];

// Handle Checkout Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_die();

    $paymentMethod = sanitize($_POST['payment_method'] ?? 'CASH');

    if (!array_key_exists($paymentMethod, $paymentMethods)) {
        $errors[] = 'Please select a valid payment method.';
    }

    if (empty($errors)) {
        try {
            // Begin Transaction
            $pdo->beginTransaction();

            // Lock the session row
            $lockStmt = $pdo->prepare("
                SELECT id, slot_id, status, entry_time, rate_per_hour 
                FROM parking_sessions 
                WHERE id = :id 
                FOR UPDATE
            ");
            $lockStmt->execute([':id' => $sessionId]);
            $lockedSession = $lockStmt->fetch();

            if (!$lockedSession || $lockedSession['status'] !== 'ACTIVE') {
                $pdo->rollBack();
                $errors[] = 'This parking session is no longer active or has already been checked out by another operator.';
            } else {
                $nowTime = date('Y-m-d H:i:s');

                // Authoritative server-side charge calculation
                $billing = calculate_parking_charge(
                    $lockedSession['entry_time'],
                    $nowTime,
                    (float)$lockedSession['rate_per_hour']
                );
                $finalAmount = $billing['amount'];
                $slotId = (int)$lockedSession['slot_id'];

                // 1. Mark session as COMPLETED with exit_time and authoritative amount
                $updateSessionStmt = $pdo->prepare("
                    UPDATE parking_sessions 
                    SET exit_time = :exit,
                        amount = :amt,
                        status = 'COMPLETED',
                        updated_at = NOW() 
                    WHERE id = :id
                ");
                $updateSessionStmt->execute([
                    ':exit' => $nowTime,
                    ':amt'  => $finalAmount,
                    ':id'   => $sessionId
                ]);

                // 2. Insert Final Payment Record
                $insertPayStmt = $pdo->prepare("
                    INSERT INTO payments (session_id, amount, method, status, paid_at, created_at)
                    VALUES (:sid, :amt, :method, 'PAID', :paid_at, NOW())
                ");
                $insertPayStmt->execute([
                    ':sid'     => $sessionId,
                    ':amt'     => $finalAmount,
                    ':method'  => $paymentMethod,
                    ':paid_at' => $nowTime
                ]);

                // 3. Release Parking Slot back to AVAILABLE
                $releaseSlotStmt = $pdo->prepare("
                    UPDATE parking_slots 
                    SET status = 'AVAILABLE', updated_at = NOW() 
                    WHERE id = :sid
                ");
                $releaseSlotStmt->execute([':sid' => $slotId]);

                // Commit Transaction
                $pdo->commit();

                set_flash('success', "Checkout successful! Payment of " . format_currency($finalAmount) . " recorded and slot released.");
                redirect('payments/receipt.php?session_id=' . $sessionId);
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Checkout error: " . $e->getMessage());
            $errors[] = "An error occurred during checkout processing: " . $e->getMessage();
        }
    }
}

// Compute live preview billing for initial render
$previewBilling = calculate_parking_charge($session['entry_time'], null, (float)$session['rate_per_hour']);

$pageTitle = 'Vehicle Checkout & Billing';
$activeNav = 'active';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
require_once __DIR__ . '/../includes/navbar.php';
?>

<div class="row justify-content-center">
    <div class="col-lg-8 col-md-10">
        <div class="card-modern">
            <div class="card-modern-header">
                <div>
                    <h5 class="fw-bold text-white mb-0">
                        <i class="bi bi-box-arrow-right me-2 text-danger"></i>Vehicle Exit & Billing Checkout
                    </h5>
                    <small class="text-secondary">Verify duration, review authoritative charge, and finalize receipt</small>
                </div>
                <span class="badge bg-danger-subtle text-danger">Ticket #<?= generate_ticket_number((int)$session['id']) ?></span>
            </div>

            <div class="card-modern-body">
                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger mb-4">
                        <ul class="mb-0 ps-3 small">
                            <?php foreach ($errors as $err): ?>
                                <li><?= htmlspecialchars($err) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <!-- Vehicle Info Banner -->
                <div class="bg-dark p-3 rounded border border-secondary mb-4">
                    <div class="row align-items-center g-3">
                        <div class="col-sm-4 border-end-sm border-secondary">
                            <small class="text-secondary d-block">Vehicle Registration</small>
                            <span class="fw-bold text-white fs-5 font-monospace"><?= e($session['registration_number']) ?></span>
                        </div>
                        <div class="col-sm-4 border-end-sm border-secondary">
                            <small class="text-secondary d-block">Vehicle Type</small>
                            <span class="fw-semibold text-info"><?= e($vMeta['label']) ?></span>
                        </div>
                        <div class="col-sm-4">
                            <small class="text-secondary d-block">Occupied Space</small>
                            <span class="fw-bold text-warning font-monospace"><?= e($session['slot_number']) ?></span>
                            <small class="text-secondary">(<?= e($session['floor_number']) ?>)</small>
                        </div>
                    </div>
                </div>

                <!-- Timing Breakdown -->
                <div class="card-modern mb-4 border-secondary-subtle">
                    <div class="card-modern-body">
                        <div class="row g-3 text-center text-sm-start">
                            <div class="col-sm-4">
                                <small class="text-secondary d-block">Check-In Time</small>
                                <div class="fw-semibold text-white small mt-1">
                                    <i class="bi bi-box-arrow-in-down-right text-success me-1"></i>
                                    <?= format_datetime($session['entry_time']) ?>
                                </div>
                            </div>
                            <div class="col-sm-4">
                                <small class="text-secondary d-block">Exit Timestamp (Server)</small>
                                <div class="fw-semibold text-white small mt-1">
                                    <i class="bi bi-box-arrow-right text-danger me-1"></i>
                                    <?= date('d M Y, h:i A') ?>
                                </div>
                            </div>
                            <div class="col-sm-4">
                                <small class="text-secondary d-block">Total Elapsed Time</small>
                                <div class="fw-bold text-info fs-6 mt-1">
                                    <i class="bi bi-stopwatch me-1"></i><?= $previewBilling['duration_text'] ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Billing Breakdown Table -->
                <h6 class="fw-bold text-white text-uppercase small mb-2">Authoritative Billing Calculation:</h6>
                <div class="table-responsive mb-4">
                    <table class="table table-dark table-bordered border-secondary">
                        <tbody>
                            <tr>
                                <td class="text-secondary">Base Rate Tariff:</td>
                                <td class="text-end fw-semibold text-white"><?= format_currency($previewBilling['rate_per_hour']) ?> / hour</td>
                            </tr>
                            <tr>
                                <td class="text-secondary">Elapsed Duration:</td>
                                <td class="text-end font-monospace text-white"><?= $previewBilling['duration_text'] ?> (<?= $previewBilling['duration_minutes'] ?> mins)</td>
                            </tr>
                            <tr>
                                <td class="text-secondary">
                                    Billable Hours:
                                    <small class="text-muted d-block" style="font-size: 0.72rem;">
                                        Min charge: <?= $previewBilling['min_hours'] ?> hr | Billing increment: <?= $previewBilling['increment_mins'] ?> mins
                                    </small>
                                </td>
                                <td class="text-end font-monospace text-white"><?= number_format($previewBilling['billable_hours'], 2) ?> hrs</td>
                            </tr>
                            <tr class="table-primary border-primary">
                                <td class="fs-5 fw-bold text-white align-middle">Total Amount Due:</td>
                                <td class="text-end fs-3 fw-bold text-success align-middle font-monospace">
                                    <?= format_currency($previewBilling['amount']) ?>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Payment Form -->
                <form method="POST">
                    <?= csrf_field() ?>

                    <div class="mb-4">
                        <label class="form-label fw-bold text-white mb-2">Select Payment Method</label>
                        <div class="row g-2">
                            <?php foreach ($paymentMethods as $mKey => $mMeta): ?>
                                <div class="col-sm-4">
                                    <label class="p-3 border rounded border-secondary d-block cursor-pointer bg-dark h-100 hover-border-primary">
                                        <div class="d-flex align-items-center mb-1">
                                            <input type="radio" name="payment_method" value="<?= e($mKey) ?>" 
                                                   class="form-check-input me-2" <?= ($mKey === 'CASH') ? 'checked' : '' ?>>
                                            <span class="fw-semibold text-white"><?= e($mMeta['label']) ?></span>
                                        </div>
                                        <small class="text-secondary ps-4 d-block"><i class="bi <?= $mMeta['icon'] ?> me-1"></i>Manual Record</small>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="d-flex justify-content-between align-items-center mt-4 pt-3 border-top border-secondary-subtle">
                        <a href="<?= url('vehicles/active.php') ?>" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-left me-1"></i>Back to Active Parked
                        </a>
                        <button type="submit" class="btn btn-success btn-lg fw-bold px-4" data-confirm="Confirm payment and release slot?">
                            <i class="bi bi-check2-circle me-1"></i>Confirm Payment & Release Slot
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
