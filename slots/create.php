<?php
/**
 * Create Parking Slot Action
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

$floor = sanitize($_POST['floor_number'] ?? '');
$slotNumber = strtoupper(sanitize($_POST['slot_number'] ?? ''));
$vehicleType = sanitize($_POST['vehicle_type'] ?? '');

$allowedTypes = array_keys(get_vehicle_types());

if ($floor === '' || $slotNumber === '' || !in_array($vehicleType, $allowedTypes, true)) {
    set_flash('danger', 'Invalid slot parameters provided. Please fill out all required fields.');
    redirect('slots/index.php');
}

try {
    $pdo = get_db_connection(true);

    // Check for duplicate slot number on the same floor
    $checkStmt = $pdo->prepare("SELECT id FROM parking_slots WHERE floor_number = :floor AND slot_number = :slot LIMIT 1");
    $checkStmt->execute([':floor' => $floor, ':slot' => $slotNumber]);
    if ($checkStmt->fetch()) {
        set_flash('danger', "Slot '{$slotNumber}' already exists on floor '{$floor}'.");
        redirect('slots/index.php');
    }

    $insertStmt = $pdo->prepare("
        INSERT INTO parking_slots (floor_number, slot_number, vehicle_type, status, created_at)
        VALUES (:floor, :slot, :vtype, 'AVAILABLE', NOW())
    ");
    $insertStmt->execute([
        ':floor' => $floor,
        ':slot'  => $slotNumber,
        ':vtype' => $vehicleType
    ]);

    set_flash('success', "Parking slot {$slotNumber} ({$floor}) created successfully.");
} catch (PDOException $e) {
    error_log("Failed to create slot: " . $e->getMessage());
    set_flash('danger', "An error occurred while creating the slot.");
}

redirect('slots/index.php');
