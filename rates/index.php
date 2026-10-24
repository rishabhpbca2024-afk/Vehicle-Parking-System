<?php
/**
 * Parking Rates & Billing Rules Management (Admin Only)
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

require_admin();

$pdo = get_db_connection(true);

// Process Rate Update Form
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_die();

    $action = $_POST['action'] ?? '';

    if ($action === 'update_rate') {
        $rateId = (int)($_POST['rate_id'] ?? 0);
        $hourlyRate = (float)($_POST['hourly_rate'] ?? 0.0);
        $minHours = max(1, (int)($_POST['minimum_charge_hours'] ?? 1));
        $incrementMins = max(15, (int)($_POST['billing_increment_minutes'] ?? 60));
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if ($rateId <= 0 || $hourlyRate <= 0.0) {
            set_flash('danger', 'Hourly rate must be greater than zero.');
        } else {
            try {
                $upStmt = $pdo->prepare("
                    UPDATE parking_rates 
                    SET hourly_rate = :rate,
                        minimum_charge_hours = :min_h,
                        billing_increment_minutes = :inc_m,
                        is_active = :active,
                        updated_at = NOW()
                    WHERE id = :id
                ");
                $upStmt->execute([
                    ':rate'   => $hourlyRate,
                    ':min_h'  => $minHours,
                    ':inc_m'  => $incrementMins,
                    ':active' => $isActive,
                    ':id'     => $rateId
                ]);

                set_flash('success', 'Parking rate and billing policy updated successfully.');
            } catch (PDOException $e) {
                error_log("Failed to update rate: " . $e->getMessage());
                set_flash('danger', 'Database error while updating parking rate.');
            }
        }
        redirect('rates/index.php');
    }
}

// Fetch all rates
$ratesStmt = $pdo->query("SELECT * FROM parking_rates ORDER BY hourly_rate ASC");
$rates = $ratesStmt->fetchAll();

$vehicleTypesMap = get_vehicle_types();

$pageTitle = 'Parking Rates Configuration';
$activeNav = 'rates';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
require_once __DIR__ . '/../includes/navbar.php';
?>

<!-- Header -->
<div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3 mb-4">
    <div>
        <h4 class="fw-bold text-white mb-1">Parking Rates & Tariffs</h4>
        <p class="text-secondary small mb-0">Configure hourly pricing, minimum billing floors, and increment rules per vehicle category</p>
    </div>
</div>

<!-- Snapshot Integrity Banner -->
<div class="alert alert-info d-flex align-items-center mb-4">
    <i class="bi bi-shield-lock-fill fs-3 me-3 text-info"></i>
    <div>
        <h6 class="alert-heading fw-bold mb-1">Rate Snapshot Guarantee</h6>
        <p class="mb-0 small">
            Modifications made here will immediately apply to all new vehicle entries. In accordance with system audit requirements, existing active sessions and completed parking records preserve their entry-time snapshot rate and will not be retroactively altered.
        </p>
    </div>
</div>

<!-- Rates Table -->
<div class="card-modern">
    <div class="table-responsive">
        <table class="table table-custom">
            <thead>
                <tr>
                    <th>Vehicle Type</th>
                    <th>Hourly Tariff</th>
                    <th>Minimum Hours</th>
                    <th>Billing Increment</th>
                    <th>Policy Status</th>
                    <th>Last Updated</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rates as $r): 
                    $vMeta = $vehicleTypesMap[$r['vehicle_type']] ?? ['badge' => 'badge-car', 'label' => $r['vehicle_type'], 'icon' => 'bi-car-front'];
                ?>
                    <tr>
                        <td>
                            <div class="d-flex align-items-center">
                                <span class="badge <?= $vMeta['badge'] ?> me-2 fs-6">
                                    <i class="bi <?= $vMeta['icon'] ?>"></i>
                                </span>
                                <div>
                                    <div class="fw-bold text-white"><?= e($vMeta['label']) ?></div>
                                    <small class="text-secondary font-monospace"><?= e($r['vehicle_type']) ?></small>
                                </div>
                            </div>
                        </td>
                        <td>
                            <span class="fw-bold text-success font-monospace fs-5"><?= format_currency($r['hourly_rate']) ?></span>
                            <small class="text-secondary d-block">per hour</small>
                        </td>
                        <td>
                            <span class="badge bg-dark border border-secondary text-light font-monospace">
                                <?= $r['minimum_charge_hours'] ?> hour(s)
                            </span>
                        </td>
                        <td>
                            <span class="badge bg-dark border border-secondary text-info font-monospace">
                                <?= $r['billing_increment_minutes'] ?> mins
                            </span>
                        </td>
                        <td>
                            <?php if ($r['is_active']): ?>
                                <span class="badge bg-success-subtle text-success"><i class="bi bi-check-circle me-1"></i>Active</span>
                            <?php else: ?>
                                <span class="badge bg-secondary-subtle text-secondary"><i class="bi bi-slash-circle me-1"></i>Disabled</span>
                            <?php endif; ?>
                        </td>
                        <td class="small text-secondary"><?= format_datetime($r['updated_at']) ?></td>
                        <td class="text-end">
                            <button type="button" class="btn btn-outline-primary btn-sm" 
                                    data-bs-toggle="modal" data-bs-target="#editRateModal<?= $r['id'] ?>">
                                <i class="bi bi-pencil me-1"></i>Edit Rate
                            </button>
                        </td>
                    </tr>

                    <!-- Edit Rate Modal for this row -->
                    <div class="modal fade" id="editRateModal<?= $r['id'] ?>" tabindex="-1" aria-hidden="true">
                        <div class="modal-dialog">
                            <div class="modal-content bg-dark text-white border-secondary">
                                <div class="modal-header border-secondary">
                                    <h5 class="modal-title fw-bold"><i class="bi bi-pencil-square me-2 text-primary"></i>Edit Rate: <?= e($vMeta['label']) ?></h5>
                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                </div>
                                <form method="POST">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="update_rate">
                                    <input type="hidden" name="rate_id" value="<?= $r['id'] ?>">

                                    <div class="modal-body">
                                        <div class="mb-3">
                                            <label class="form-label">Hourly Tariff Rate (<?= e(CURRENCY_SYMBOL) ?>)</label>
                                            <div class="input-group">
                                                <span class="input-group-text bg-secondary border-secondary text-white"><?= e(CURRENCY_SYMBOL) ?></span>
                                                <input type="number" step="0.50" min="1" class="form-control" name="hourly_rate" required value="<?= e((string)$r['hourly_rate']) ?>">
                                            </div>
                                            <small class="text-secondary">Amount billed per standard hour</small>
                                        </div>

                                        <div class="row g-3 mb-3">
                                            <div class="col-sm-6">
                                                <label class="form-label">Minimum Charge (Hours)</label>
                                                <input type="number" min="1" max="24" class="form-control" name="minimum_charge_hours" required value="<?= e((string)$r['minimum_charge_hours']) ?>">
                                                <small class="text-secondary">Floor charge minimum</small>
                                            </div>
                                            <div class="col-sm-6">
                                                <label class="form-label">Increment Step (Mins)</label>
                                                <input type="number" min="1" max="120" step="1" class="form-control" name="billing_increment_minutes" required value="<?= e((string)$r['billing_increment_minutes']) ?>">
                                                <small class="text-secondary">Billing quantum</small>
                                            </div>
                                        </div>

                                        <div class="form-check form-switch mt-3">
                                            <input class="form-check-input" type="checkbox" id="activeSwitch<?= $r['id'] ?>" name="is_active" <?= $r['is_active'] ? 'checked' : '' ?>>
                                            <label class="form-check-label" for="activeSwitch<?= $r['id'] ?>">Rate active and available for entry allocation</label>
                                        </div>
                                    </div>

                                    <div class="modal-footer border-secondary">
                                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                                        <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-save me-1"></i>Save Rate</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
