<?php
/**
 * Delete Parking Slot Action
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

    $stmt = $pdo->prepare("SELECT id, slot_number, floor_number, status FROM parking_slots WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $slotId]);
    $slot = $stmt->fetch();

    if (!$slot) {
        set_flash('danger', 'Parking slot not found.');
        redirect('slots/index.php');
    }

    if ($slot['status'] === 'OCCUPIED') {
        set_flash('danger', "Slot {$slot['slot_number']} is currently occupied and cannot be deleted.");
        redirect('slots/index.php');
    }

    // Check for historical parking sessions referencing this slot
    $histStmt = $pdo->prepare("SELECT COUNT(*) FROM parking_sessions WHERE slot_id = :id");
    $histStmt->execute([':id' => $slotId]);
    $historyCount = (int)$histStmt->fetchColumn();

    if ($historyCount > 0) {
        set_flash('warning', "Slot {$slot['slot_number']} has {$historyCount} historical parking session records and cannot be permanently deleted. You can mark it as 'Out of Service' instead.");
        redirect('slots/index.php');
    }

    $delStmt = $pdo->prepare("DELETE FROM parking_slots WHERE id = :id");
    $delStmt->execute([':id' => $slotId]);

    set_flash('success', "Parking slot {$slot['slot_number']} has been deleted.");
} catch (PDOException $e) {
    error_log("Failed to delete slot: " . $e->getMessage());
    set_flash('danger', 'Failed to delete parking slot due to database constraints.');
}

redirect('slots/index.php');
