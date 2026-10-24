<?php
/**
 * Vehicle Entry / Check-in Console
 * 
 * Implements strict ACID transaction locking, slot assignment,
 * rate snapshotting, and duplicate active session prevention.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

require_login();

$pdo = get_db_connection(true);
$user = current_user();

$vehicleTypesMap = get_vehicle_types();

// Fetch active parking rates for all vehicle types
$ratesStmt = $pdo->query("SELECT vehicle_type, hourly_rate, minimum_charge_hours, billing_increment_minutes FROM parking_rates WHERE is_active = 1");
$activeRates = [];
while ($r = $ratesStmt->fetch()) {
    $activeRates[$r['vehicle_type']] = (float)$r['hourly_rate'];
}

// Fetch all available slots grouped by vehicle type
$slotsStmt = $pdo->query("
    SELECT id, slot_number, floor_number, vehicle_type 
    FROM parking_slots 
    WHERE status = 'AVAILABLE' 
    ORDER BY floor_number ASC, slot_number ASC
");
$availableSlots = $slotsStmt->fetchAll();

$errors = [];
$registrationNumber = '';
$selectedVehicleType = 'CAR';
$selectedSlotId = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_die();

    $registrationNumber = normalize_registration_number($_POST['registration_number'] ?? '');
    $selectedVehicleType = sanitize($_POST['vehicle_type'] ?? '');
    $selectedSlotId = isset($_POST['slot_id']) ? (int)$_POST['slot_id'] : 0;

    // Validation
    if ($registrationNumber === '') {
        $errors[] = 'Vehicle registration number is required.';
    } elseif (strlen($registrationNumber) < 3 || strlen($registrationNumber) > 20) {
        $errors[] = 'Please enter a valid registration number (3 to 20 characters).';
    }

    if (!array_key_exists($selectedVehicleType, $vehicleTypesMap)) {
        $errors[] = 'Please select a valid vehicle type.';
    }

    if (!isset($activeRates[$selectedVehicleType])) {
        $errors[] = "No active parking rate has been configured for vehicle type '{$selectedVehicleType}'. Please contact an administrator.";
    }

    if (empty($errors)) {
        $ratePerHour = $activeRates[$selectedVehicleType];

        try {
            // Begin Transaction with Exclusive Row-Level Locking
            $pdo->beginTransaction();

            // 1. Find or create the vehicle record
            $findVehStmt = $pdo->prepare("SELECT id, vehicle_type FROM vehicles WHERE registration_number = :reg LIMIT 1");
            $findVehStmt->execute([':reg' => $registrationNumber]);
            $existingVehicle = $findVehStmt->fetch();

            if ($existingVehicle) {
                $vehicleId = (int)$existingVehicle['id'];

                // Check for existing active parking session
                $activeCheckStmt = $pdo->prepare("
                    SELECT id FROM parking_sessions 
                    WHERE vehicle_id = :vid AND status = 'ACTIVE' 
                    LIMIT 1
                ");
                $activeCheckStmt->execute([':vid' => $vehicleId]);
                $activeSession = $activeCheckStmt->fetch();

                if ($activeSession) {
                    $pdo->rollBack();
                    $ticketNumber = generate_ticket_number((int)$activeSession['id']);
                    $errors[] = "Vehicle {$registrationNumber} is already parked inside with active {$ticketNumber}. Please checkout first before re-entering.";
                } else {
                    // Update vehicle type if operator selected a different type this visit
                    $upVehStmt = $pdo->prepare("UPDATE vehicles SET vehicle_type = :vt, updated_at = NOW() WHERE id = :vid");
                    $upVehStmt->execute([':vt' => $selectedVehicleType, ':vid' => $vehicleId]);
                }
            } else {
                $insertVehStmt = $pdo->prepare("
                    INSERT INTO vehicles (registration_number, vehicle_type, created_at)
                    VALUES (:reg, :vt, NOW())
                ");
                $insertVehStmt->execute([
                    ':reg' => $registrationNumber,
                    ':vt'  => $selectedVehicleType
                ]);
                $vehicleId = (int)$pdo->lastInsertId();
            }

            // If no active session conflict, proceed with slot assignment
            if (empty($errors)) {
                $allocatedSlot = null;

                if ($selectedSlotId > 0) {
                    // Lock the user-selected slot
                    $slotLockStmt = $pdo->prepare("
                        SELECT id, slot_number, floor_number, vehicle_type, status 
                        FROM parking_slots 
                        WHERE id = :sid 
                        FOR UPDATE
                    ");
                    $slotLockStmt->execute([':sid' => $selectedSlotId]);
                    $targetSlot = $slotLockStmt->fetch();

                    if (!$targetSlot || $targetSlot['status'] !== 'AVAILABLE') {
                        $pdo->rollBack();
                        $errors[] = 'The selected parking slot is no longer available. Please select another slot or use automatic allocation.';
                    } elseif ($targetSlot['vehicle_type'] !== $selectedVehicleType) {
                        $pdo->rollBack();
                        $errors[] = 'The selected parking slot is incompatible with this vehicle type.';
                    } else {
                        $allocatedSlot = $targetSlot;
                    }
                } else {
                    // Automatic Allocation: find nearest available slot for this vehicle type with FOR UPDATE
                    $autoSlotStmt = $pdo->prepare("
                        SELECT id, slot_number, floor_number, vehicle_type, status 
                        FROM parking_slots 
                        WHERE vehicle_type = :vt AND status = 'AVAILABLE' 
                        ORDER BY floor_number ASC, slot_number ASC 
                        LIMIT 1 
                        FOR UPDATE
                    ");
                    $autoSlotStmt->execute([':vt' => $selectedVehicleType]);
                    $allocatedSlot = $autoSlotStmt->fetch();

                    if (!$allocatedSlot) {
                        $pdo->rollBack();
                        $errors[] = "No available parking slots found for vehicle type '{$vehicleTypesMap[$selectedVehicleType]['short']}'. Lot is at full capacity for this type.";
                    }
                }

                if ($allocatedSlot && empty($errors)) {
                    $slotId = (int)$allocatedSlot['id'];

                    // Insert Active Parking Session with rate snapshot
                    $sessionInsert = $pdo->prepare("
                        INSERT INTO parking_sessions 
                            (vehicle_id, slot_id, created_by, entry_time, rate_per_hour, amount, status, created_at)
                        VALUES 
                            (:vid, :sid, :uid, NOW(), :rate, 0.00, 'ACTIVE', NOW())
                    ");
                    $sessionInsert->execute([
                        ':vid'  => $vehicleId,
                        ':sid'  => $slotId,
                        ':uid'  => $user['id'],
                        ':rate' => $ratePerHour
                    ]);
                    $sessionId = (int)$pdo->lastInsertId();

                    // Mark Slot as OCCUPIED
                    $updateSlotStmt = $pdo->prepare("
                        UPDATE parking_slots 
                        SET status = 'OCCUPIED', updated_at = NOW() 
                        WHERE id = :sid
                    ");
                    $updateSlotStmt->execute([':sid' => $slotId]);

                    // Commit Transaction
                    $pdo->commit();

                    set_flash('success', "Vehicle {$registrationNumber} checked in successfully at Slot {$allocatedSlot['slot_number']} ({$allocatedSlot['floor_number']}).");
                    redirect('vehicles/ticket.php?id=' . $sessionId);
                }
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Vehicle check-in error: " . $e->getMessage());
            $errors[] = "An unexpected error occurred during vehicle check-in: " . $e->getMessage();
        }
    }
}

$pageTitle = 'Vehicle Entry / Check-In';
$activeNav = 'entry';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
require_once __DIR__ . '/../includes/navbar.php';
?>

<div class="row justify-content-center">
    <div class="col-lg-7 col-md-10">
        <div class="card-modern">
            <div class="card-modern-header">
                <div>
                    <h5 class="fw-bold text-white mb-0"><i class="bi bi-box-arrow-in-down-right me-2 text-primary"></i>Vehicle Entry Form</h5>
                    <small class="text-secondary">Register inbound vehicle and allocate parking space</small>
                </div>
                <span class="badge bg-dark border border-secondary text-info">Live Allocation</span>
            </div>

            <div class="card-modern-body">
                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger mb-4">
                        <div class="fw-bold mb-1"><i class="bi bi-exclamation-triangle-fill me-2"></i>Entry Submission Error:</div>
                        <ul class="mb-0 ps-3 small">
                            <?php foreach ($errors as $err): ?>
                                <li><?= htmlspecialchars($err) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <form method="POST" autocomplete="off">
                    <?= csrf_field() ?>

                    <!-- Vehicle Registration Number -->
                    <div class="mb-4">
                        <label for="registration_number" class="form-label">Vehicle Registration / Plate Number</label>
                        <div class="input-group input-group-lg">
                            <span class="input-group-text bg-dark border-secondary text-primary">
                                <i class="bi bi-card-heading fs-4"></i>
                            </span>
                            <input type="text" class="form-control form-control-lg font-monospace fw-bold" 
                                   id="registration_number" name="registration_number" required autofocus
                                   placeholder="e.g. MH 02 AB 1234 or DL 1C 9999"
                                   data-transform="uppercase"
                                   value="<?= e($registrationNumber) ?>">
                        </div>
                        <small class="text-secondary">Registration number is automatically normalized and converted to uppercase.</small>
                    </div>

                    <!-- Vehicle Type Selection -->
                    <div class="mb-4">
                        <label for="entry_vehicle_type" class="form-label">Vehicle Type</label>
                        <select class="form-select form-select-lg" id="entry_vehicle_type" name="vehicle_type" required>
                            <?php foreach ($vehicleTypesMap as $vKey => $vMeta): 
                                $rate = $activeRates[$vKey] ?? 0.00;
                            ?>
                                <option value="<?= e($vKey) ?>" 
                                        data-rate="<?= format_currency($rate) ?> / hr" 
                                        <?= ($selectedVehicleType === $vKey) ? 'selected' : '' ?>>
                                    <?= e($vMeta['label']) ?> — <?= format_currency($rate) ?>/hr
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Slot Allocation -->
                    <div class="mb-4">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <label for="entry_slot_id" class="form-label mb-0">Parking Slot Assignment</label>
                            <span class="badge bg-secondary-subtle text-secondary small">Optional manual selection</span>
                        </div>
                        <select class="form-select" id="entry_slot_id" name="slot_id">
                            <option value="">⚡ Auto-Allocate Nearest Available Slot (Recommended)</option>
                            <?php foreach ($availableSlots as $slot): ?>
                                <option value="<?= $slot['id'] ?>" 
                                        data-vehicle-type="<?= e($slot['vehicle_type']) ?>"
                                        <?= ($selectedSlotId === (int)$slot['id']) ? 'selected' : '' ?>>
                                    [<?= e($slot['floor_number']) ?>] <?= e($slot['slot_number']) ?> (<?= e($vehicleTypesMap[$slot['vehicle_type']]['short']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-secondary">If no specific slot is selected, the system will instantly assign the best available slot.</small>
                    </div>

                    <!-- Pricing & Operator Info Card -->
                    <div class="p-3 rounded bg-dark border border-secondary mb-4">
                        <div class="row align-items-center">
                            <div class="col-sm-6 border-end-sm border-secondary">
                                <small class="text-secondary d-block">Applicable Rate Snapshot</small>
                                <div class="fw-bold text-success fs-5" id="entry_rate_display">
                                    <?= format_currency($activeRates[$selectedVehicleType] ?? 0.00) ?> / hr
                                </div>
                            </div>
                            <div class="col-sm-6 mt-2 mt-sm-0">
                                <small class="text-secondary d-block">Check-in Operator</small>
                                <div class="fw-semibold text-white">
                                    <i class="bi bi-person-circle me-1 text-primary"></i><?= e($user['name']) ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Submit Button -->
                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-primary btn-lg fw-bold py-3 shadow-lg">
                            <i class="bi bi-check-circle-fill me-2"></i>Issue Parking Ticket & Check In
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
