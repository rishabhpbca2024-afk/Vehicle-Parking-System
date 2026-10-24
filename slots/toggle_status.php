<?php
/**
 * Toggle Slot Status Action (Available <-> Out of Service)
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('slots/index.php');
}

verify_csrf_or_die();

$slotId = isset($_POST['slot_id']) ? (int)$_POST['slot_id'] : 0;
if ($slotId <= 0) {
    redirect('slots/index.php');
}

try {
    $pdo = get_db_connection(true);
    $stmt = $pdo->prepare("SELECT id, slot_number, status FROM parking_slots WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $slotId]);
    $slot = $stmt->fetch();

    if (!$slot) {
        set_flash('danger', 'Parking slot not found.');
        redirect('slots/index.php');
    }

    if ($slot['status'] === 'OCCUPIED') {
        set_flash('warning', "Slot {$slot['slot_number']} is currently occupied and cannot be marked Out of Service.");
        redirect('slots/index.php');
    }

    $newStatus = ($slot['status'] === 'AVAILABLE') ? 'OUT_OF_SERVICE' : 'AVAILABLE';

    $updateStmt = $pdo->prepare("UPDATE parking_slots SET status = :status, updated_at = NOW() WHERE id = :id");
    $updateStmt->execute([':status' => $newStatus, ':id' => $slotId]);

    $label = ($newStatus === 'OUT_OF_SERVICE') ? 'marked Out of Service' : 'returned to Available service';
    set_flash('success', "Slot {$slot['slot_number']} has been {$label}.");
} catch (PDOException $e) {
    error_log("Failed to toggle slot status: " . $e->getMessage());
    set_flash('danger', 'An error occurred while updating the slot status.');
}

redirect('slots/index.php');
