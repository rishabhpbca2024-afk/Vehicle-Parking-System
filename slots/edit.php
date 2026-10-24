<?php
/**
 * Edit Parking Slot
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

require_admin();

$pdo = get_db_connection(true);

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    redirect('slots/index.php');
}

$stmt = $pdo->prepare("SELECT * FROM parking_slots WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $id]);
$slot = $stmt->fetch();

if (!$slot) {
    set_flash('danger', 'Parking slot not found.');
    redirect('slots/index.php');
}

$isOccupied = ($slot['status'] === 'OCCUPIED');
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_die();

    $floor = sanitize($_POST['floor_number'] ?? '');
    $slotNumber = strtoupper(sanitize($_POST['slot_number'] ?? ''));
    $vehicleType = sanitize($_POST['vehicle_type'] ?? $slot['vehicle_type']);
    $status = sanitize($_POST['status'] ?? $slot['status']);

    $allowedTypes = array_keys(get_vehicle_types());
    $allowedStatuses = ['AVAILABLE', 'OUT_OF_SERVICE'];

    if ($floor === '' || $slotNumber === '') {
        $errors[] = 'Floor and Slot Number are required.';
    }

    if ($isOccupied) {
        // Enforce immutability of type and status while occupied
        $vehicleType = $slot['vehicle_type'];
        $status = 'OCCUPIED';
    } else {
        if (!in_array($vehicleType, $allowedTypes, true)) {
            $errors[] = 'Invalid vehicle type selected.';
        }
        if (!in_array($status, $allowedStatuses, true)) {
            $errors[] = 'Invalid status selected.';
        }
    }

    if (empty($errors)) {
        try {
            // Check uniqueness of floor + slot_number excluding self
            $uniqueStmt = $pdo->prepare("SELECT id FROM parking_slots WHERE floor_number = :floor AND slot_number = :slot AND id != :id LIMIT 1");
            $uniqueStmt->execute([':floor' => $floor, ':slot' => $slotNumber, ':id' => $id]);
            if ($uniqueStmt->fetch()) {
                $errors[] = "Another slot with identifier '{$slotNumber}' already exists on floor '{$floor}'.";
            } else {
                $updateStmt = $pdo->prepare("
                    UPDATE parking_slots 
                    SET floor_number = :floor,
                        slot_number = :slot,
                        vehicle_type = :vtype,
                        status = :status,
                        updated_at = NOW()
                    WHERE id = :id
                ");
                $updateStmt->execute([
                    ':floor'  => $floor,
                    ':slot'   => $slotNumber,
                    ':vtype'  => $vehicleType,
                    ':status' => $status,
                    ':id'     => $id
                ]);

                set_flash('success', "Parking slot {$slotNumber} updated successfully.");
                redirect('slots/index.php');
            }
        } catch (PDOException $e) {
            error_log("Failed to update slot: " . $e->getMessage());
            $errors[] = 'Database error occurred while updating the slot.';
        }
    }
}

$vehicleTypesMap = get_vehicle_types();
$slotStatuses = get_slot_statuses();

$pageTitle = 'Edit Parking Slot #' . $slot['slot_number'];
$activeNav = 'slots';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
require_once __DIR__ . '/../includes/navbar.php';
?>

<div class="row justify-content-center">
    <div class="col-lg-6 col-md-8">
        <div class="card-modern">
            <div class="card-modern-header">
                <div>
                    <h5 class="fw-bold text-white mb-0">Edit Parking Slot</h5>
                    <small class="text-secondary">Modify floor, identification, or availability</small>
                </div>
                <a href="<?= url('slots/index.php') ?>" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-arrow-left me-1"></i>Back
                </a>
            </div>

            <div class="card-modern-body">
                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger mb-4 small">
                        <ul class="mb-0 ps-3">
                            <?php foreach ($errors as $err): ?>
                                <li><?= htmlspecialchars($err) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <?php if ($isOccupied): ?>
                    <div class="alert alert-warning small mb-4">
                        <i class="bi bi-lock-fill me-1"></i> <strong>Occupied Slot:</strong> This slot currently has a parked vehicle. Vehicle type and status are locked until the vehicle has completed checkout.
                    </div>
                <?php endif; ?>

                <form method="POST">
                    <?= csrf_field() ?>

                    <div class="mb-3">
                        <label for="floor_number" class="form-label">Floor / Level</label>
                        <input type="text" class="form-control" id="floor_number" name="floor_number" required
                               value="<?= e($_POST['floor_number'] ?? $slot['floor_number']) ?>">
                    </div>

                    <div class="mb-3">
                        <label for="slot_number" class="form-label">Slot Identifier / Number</label>
                        <input type="text" class="form-control" id="slot_number" name="slot_number" required
                               value="<?= e($_POST['slot_number'] ?? $slot['slot_number']) ?>">
                    </div>

                    <div class="mb-3">
                        <label for="vehicle_type" class="form-label">Compatible Vehicle Type</label>
                        <select class="form-select" id="vehicle_type" name="vehicle_type" <?= $isOccupied ? 'disabled' : '' ?>>
                            <?php foreach ($vehicleTypesMap as $vk => $vm): ?>
                                <option value="<?= e($vk) ?>" <?= ($slot['vehicle_type'] === $vk) ? 'selected' : '' ?>>
                                    <?= e($vm['label']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($isOccupied): ?>
                            <input type="hidden" name="vehicle_type" value="<?= e($slot['vehicle_type']) ?>">
                        <?php endif; ?>
                    </div>

                    <div class="mb-4">
                        <label for="status" class="form-label">Slot Status</label>
                        <select class="form-select" id="status" name="status" <?= $isOccupied ? 'disabled' : '' ?>>
                            <?php if ($isOccupied): ?>
                                <option value="OCCUPIED" selected>Occupied (Locked)</option>
                            <?php else: ?>
                                <option value="AVAILABLE" <?= $slot['status'] === 'AVAILABLE' ? 'selected' : '' ?>>Available</option>
                                <option value="OUT_OF_SERVICE" <?= $slot['status'] === 'OUT_OF_SERVICE' ? 'selected' : '' ?>>Out of Service</option>
                            <?php endif; ?>
                        </select>
                        <?php if ($isOccupied): ?>
                            <input type="hidden" name="status" value="OCCUPIED">
                        <?php endif; ?>
                    </div>

                    <div class="d-flex justify-content-end gap-2">
                        <a href="<?= url('slots/index.php') ?>" class="btn btn-dark border-secondary">Cancel</a>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
