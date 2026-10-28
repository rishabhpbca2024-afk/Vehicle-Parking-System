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
            <div class="card-modern-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <h5 class="fw-bold mb-0 receipt-data-text">
                        <i class="bi bi-box-arrow-right me-2 text-danger"></i>Vehicle Exit & Billing Checkout
                    </h5>
                    <small class="text-secondary">Verify duration, review authoritative charge, and finalize receipt</small>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <span class="badge bg-danger-subtle text-danger fs-6 px-3 py-2">Ticket #<?= generate_ticket_number((int)$session['id']) ?></span>
                    <button type="submit" form="checkout-form" class="btn btn-success fw-bold px-3 py-2 shadow-sm d-flex align-items-center gap-1">
                        <i class="bi bi-check2-circle fs-5"></i>
                        <span>Next / Checkout ➔</span>
                    </button>
                </div>
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
                            <span class="fw-bold receipt-data-text fs-5 font-monospace"><?= e($session['registration_number']) ?></span>
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
                                <div class="fw-bold receipt-data-text small mt-1">
                                    <i class="bi bi-box-arrow-in-down-right text-success me-1"></i>
                                    <?= format_datetime($session['entry_time']) ?>
                                </div>
                            </div>
                            <div class="col-sm-4">
                                <small class="text-secondary d-block">Exit Timestamp (Server)</small>
                                <div class="fw-bold receipt-data-text small mt-1">
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
                <h6 class="fw-bold receipt-data-text text-uppercase small mb-2">Authoritative Billing Calculation:</h6>
                <div class="table-responsive mb-4">
                    <table class="table table-dark table-bordered border-secondary">
                        <tbody>
                            <tr>
                                <td class="text-secondary">Base Rate Tariff:</td>
                                <td class="text-end fw-semibold receipt-data-text"><?= format_currency($previewBilling['rate_per_hour']) ?> / hour</td>
                            </tr>
                            <tr>
                                <td class="text-secondary">Elapsed Duration:</td>
                                <td class="text-end font-monospace receipt-data-text"><?= $previewBilling['duration_text'] ?> (<?= $previewBilling['duration_minutes'] ?> mins)</td>
                            </tr>
                            <tr>
                                <td class="text-secondary">
                                    Billable Hours:
                                    <small class="text-muted d-block" style="font-size: 0.72rem;">
                                        Min charge: <?= $previewBilling['min_hours'] ?> hr | Billing increment: <?= $previewBilling['increment_mins'] ?> mins
                                    </small>
                                </td>
                                <td class="text-end font-monospace receipt-data-text"><?= number_format($previewBilling['billable_hours'], 2) ?> hrs</td>
                            </tr>
                            <tr class="table-primary border-primary">
                                <td class="fs-5 fw-bold receipt-data-text align-middle">Total Amount Due:</td>
                                <td class="text-end fs-3 fw-bold text-success align-middle font-monospace">
                                    <?= format_currency($previewBilling['amount']) ?>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Payment Form -->
                <form method="POST" id="checkout-form">
                    <?= csrf_field() ?>

                    <div class="mb-4">
                        <label class="form-label fw-bold receipt-data-text mb-2">Select Payment Method</label>
                        <div class="row g-2">
                            <?php foreach ($paymentMethods as $mKey => $mMeta): ?>
                                <div class="col-sm-4">
                                    <label class="p-3 border rounded border-secondary d-block cursor-pointer bg-dark h-100 hover-border-primary">
                                        <div class="d-flex align-items-center mb-1">
                                            <input type="radio" name="payment_method" value="<?= e($mKey) ?>" 
                                                   class="form-check-input me-2 payment-method-radio" <?= ($mKey === 'CASH') ? 'checked' : '' ?>>
                                            <span class="fw-semibold receipt-data-text"><?= e($mMeta['label']) ?></span>
                                        </div>
                                        <small class="text-secondary ps-4 d-block"><i class="bi <?= $mMeta['icon'] ?> me-1"></i>Manual Record</small>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <?php 
                        $qrExtensions = ['png', 'jpg', 'jpeg', 'webp'];
                        $qrCheckoutSrc = null;
                        foreach ($qrExtensions as $ext) {
                            if (file_exists(__DIR__ . '/../assets/images/payment_qr.' . $ext)) {
                                $qrCheckoutSrc = asset('images/payment_qr.' . $ext);
                                break;
                            }
                        }
                    ?>
                    <?php if ($qrCheckoutSrc): ?>
                        <div id="upi-qr-box" class="p-3 bg-dark border border-primary rounded text-center my-3" style="display: none;">
                            <div class="text-info fw-bold mb-2"><i class="bi bi-qr-code-scan me-2"></i>Scan to Pay via Any UPI App (GPay, PhonePe, Paytm)</div>
                            <div class="p-2 bg-white rounded d-inline-block shadow-sm">
                                <img src="<?= $qrCheckoutSrc ?>" alt="UPI QR" style="width: 160px; height: 160px; object-fit: contain;">
                            </div>
                            <div class="mt-2 text-white font-monospace fw-bold fs-5">
                                Total Due: <span class="text-success"><?= format_currency($billing['charge']) ?></span>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="mt-4 pt-3 border-top border-secondary-subtle">
                        <button type="submit" id="btn-checkout-submit" class="btn btn-success btn-lg fw-bold py-3 w-100 shadow-lg fs-5 mb-3">
                            <i class="bi bi-arrow-right-circle-fill me-2"></i>Next: Confirm Payment & Release Slot (Press Enter ⏎)
                        </button>
                        <div class="d-flex justify-content-between align-items-center">
                            <a href="<?= url('vehicles/active.php') ?>" class="btn btn-outline-secondary">
                                <i class="bi bi-arrow-left me-1"></i>Back to Active Parked Vehicles
                            </a>
                            <span class="badge bg-secondary-subtle text-secondary">Shortcut: Press Enter to proceed</span>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Interactive Customer UPI Payment Console Modal -->
<div class="modal fade" id="upiPaymentModal" tabindex="-1" aria-labelledby="upiPaymentModalLabel" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content card-modern border-primary shadow-lg">
            <div class="modal-header border-bottom border-secondary-subtle">
                <div class="d-flex align-items-center gap-2">
                    <div class="p-2 bg-primary-subtle text-primary rounded-circle">
                        <i class="bi bi-qr-code-scan fs-4"></i>
                    </div>
                    <div>
                        <h5 class="modal-title fw-bold receipt-data-text mb-0" id="upiPaymentModalLabel">Customer UPI Payment</h5>
                        <small class="text-secondary">Scan via PhonePe, Google Pay, Paytm, or BHIM</small>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center p-4">
                <div class="mb-3">
                    <span class="badge bg-secondary-subtle text-info font-monospace fs-6 px-3 py-1">
                        Vehicle: <?= e($session['registration_number']) ?>
                    </span>
                </div>

                <!-- QR Display Container -->
                <?php if ($qrCheckoutSrc): ?>
                    <div class="p-3 bg-white rounded-3 d-inline-block shadow border border-secondary mb-3">
                        <img src="<?= $qrCheckoutSrc ?>" alt="UPI QR Code" style="width: 200px; height: 200px; object-fit: contain;" class="d-block mx-auto mb-1">
                        <div class="small fw-bold text-dark font-monospace" style="letter-spacing: 0.5px;">PHONEPE UPI SCANNER</div>
                    </div>
                <?php endif; ?>

                <div class="my-2">
                    <small class="text-secondary d-block">Amount Payable</small>
                    <div class="fs-2 fw-bold text-success font-monospace" id="modal-pay-amount">
                        <?= format_currency($previewBilling['amount']) ?>
                    </div>
                </div>

                <!-- Live Status Indicator -->
                <div class="p-2 rounded bg-dark border border-secondary my-3 d-flex align-items-center justify-content-center gap-2" id="upi-status-box">
                    <div class="spinner-grow spinner-grow-sm text-info" id="upi-spinner" role="status"></div>
                    <span class="small receipt-data-text font-monospace" id="upi-status-text">
                        Awaiting scan from customer... (<span id="upi-timer">02:59</span>)
                    </span>
                </div>

                <!-- Simulation Success Alert (Hidden initially) -->
                <div class="alert alert-success d-none py-2 px-3 small text-start mb-2" id="upi-success-alert">
                    <div class="d-flex align-items-center gap-2">
                        <i class="bi bi-check-circle-fill fs-3 text-success"></i>
                        <div>
                            <strong class="d-block text-success">Payment Received via UPI!</strong>
                            <span class="text-secondary font-monospace" id="upi-ref-id">Ref #UPI-<?= rand(10000000, 99999999) ?></span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-top border-secondary-subtle d-flex flex-column gap-2">
                <button type="button" id="btn-confirm-upi-paid" class="btn btn-success btn-lg w-100 fw-bold shadow">
                    <i class="bi bi-check2-circle me-1"></i>Customer Paid <?= format_currency($previewBilling['amount']) ?> — Generate Bill
                </button>
                <div class="d-flex justify-content-between w-100">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle me-1"></i>Cancel
                    </button>
                    <button type="button" id="btn-simulate-upi" class="btn btn-sm btn-outline-info">
                        <i class="bi bi-lightning-charge-fill me-1"></i>Auto-Detect / Simulate Scan (Demo)
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const paymentRadios = document.querySelectorAll('.payment-method-radio');
    const upiBox = document.getElementById('upi-qr-box');
    const checkoutForm = document.getElementById('checkout-form');
    const upiModalEl = document.getElementById('upiPaymentModal');
    const upiModal = upiModalEl ? new bootstrap.Modal(upiModalEl) : null;
    let upiTimerInterval = null;
    let isUpiVerified = false;

    if (upiBox) {
        paymentRadios.forEach(radio => {
            radio.addEventListener('change', () => {
                upiBox.style.display = (radio.value === 'UPI') ? 'block' : 'none';
            });
            if (radio.checked && radio.value === 'UPI') {
                upiBox.style.display = 'block';
            }
        });
    }

    function startUpiTimer() {
        let totalSeconds = 179;
        const timerEl = document.getElementById('upi-timer');
        if (upiTimerInterval) clearInterval(upiTimerInterval);
        
        upiTimerInterval = setInterval(() => {
            if (totalSeconds <= 0) {
                clearInterval(upiTimerInterval);
                return;
            }
            totalSeconds--;
            const m = Math.floor(totalSeconds / 60);
            const s = totalSeconds % 60;
            if (timerEl) {
                timerEl.textContent = `${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
            }
        }, 1000);
    }

    function getSelectedMethod() {
        const checked = document.querySelector('input[name="payment_method"]:checked');
        return checked ? checked.value : 'CASH';
    }

    // Intercept form submission when UPI is selected
    if (checkoutForm) {
        checkoutForm.addEventListener('submit', (e) => {
            const method = getSelectedMethod();
            if (method === 'UPI' && !isUpiVerified) {
                e.preventDefault();
                if (upiModal) {
                    startUpiTimer();
                    upiModal.show();
                }
            }
        });
    }

    // Modal: Customer Paid Button
    const btnConfirmUpiPaid = document.getElementById('btn-confirm-upi-paid');
    if (btnConfirmUpiPaid && checkoutForm) {
        btnConfirmUpiPaid.addEventListener('click', () => {
            isUpiVerified = true;
            if (upiTimerInterval) clearInterval(upiTimerInterval);
            if (upiModal) upiModal.hide();
            checkoutForm.submit();
        });
    }

    // Modal: Auto-Detect / Simulate Scan Button (for Client Demo)
    const btnSimulateUpi = document.getElementById('btn-simulate-upi');
    if (btnSimulateUpi && checkoutForm) {
        btnSimulateUpi.addEventListener('click', () => {
            const statusText = document.getElementById('upi-status-text');
            const spinner = document.getElementById('upi-spinner');
            const successAlert = document.getElementById('upi-success-alert');

            if (spinner) spinner.className = 'spinner-border spinner-border-sm text-success';
            if (statusText) statusText.innerHTML = '<strong class="text-success">Customer QR Scan Detected! Verifying with Bank...</strong>';
            
            setTimeout(() => {
                if (successAlert) successAlert.classList.remove('d-none');
                if (statusText) statusText.innerHTML = '<strong class="text-success">Payment Received! Generating Bill...</strong>';
                
                setTimeout(() => {
                    isUpiVerified = true;
                    if (upiTimerInterval) clearInterval(upiTimerInterval);
                    if (upiModal) upiModal.hide();
                    checkoutForm.submit();
                }, 1200);
            }, 1000);
        });
    }

    // Keyboard Shortcut: Press Enter to submit
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            const activeTag = document.activeElement ? document.activeElement.tagName : '';
            if (activeTag !== 'A' && activeTag !== 'BUTTON') {
                if (checkoutForm) {
                    e.preventDefault();
                    checkoutForm.requestSubmit();
                }
            }
        }
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
