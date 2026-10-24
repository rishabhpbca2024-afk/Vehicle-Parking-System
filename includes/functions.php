<?php
/**
 * Global Utility Functions & Business Logic Helpers
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';

/**
 * XSS Escaping shortcut
 */
function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Sanitize generic text input
 */
function sanitize(?string $value): string
{
    return trim($value ?? '');
}

/**
 * HTTP Redirect helper
 */
function redirect(string $path): void
{
    $targetUrl = (str_starts_with($path, 'http://') || str_starts_with($path, 'https://'))
        ? $path
        : url($path);

    header("Location: {$targetUrl}");
    exit;
}

/**
 * Flash messaging system
 */
function set_flash(string $type, string $message): void
{
    $_SESSION['flash_messages'][] = [
        'type'    => $type, // success, danger, warning, info
        'message' => $message
    ];
}

function get_flash_messages(): array
{
    $messages = $_SESSION['flash_messages'] ?? [];
    unset($_SESSION['flash_messages']);
    return $messages;
}

/**
 * Normalize vehicle registration numbers
 * Trims whitespace, removes redundant internal spacing, and forces uppercase.
 */
function normalize_registration_number(string $regNumber): string
{
    $cleaned = trim($regNumber);
    $cleaned = preg_replace('/\s+/', ' ', $cleaned);
    return strtoupper($cleaned ?? '');
}

/**
 * Format currency with configured symbol
 */
function format_currency(float|int|string $amount): string
{
    return CURRENCY_SYMBOL . ' ' . number_format((float) $amount, 2);
}

/**
 * Format date & time
 */
function format_datetime(?string $dateTimeStr, string $format = 'd M Y, h:i A'): string
{
    if (empty($dateTimeStr)) {
        return '—';
    }
    try {
        $dt = new DateTime($dateTimeStr);
        return $dt->format($format);
    } catch (Exception) {
        return (string)$dateTimeStr;
    }
}

/**
 * Calculate human-readable duration between two timestamps
 */
function calculate_parking_duration(string $entryTime, ?string $exitTime = null): array
{
    try {
        $entry = new DateTime($entryTime);
        $exit = $exitTime ? new DateTime($exitTime) : new DateTime();
        
        $interval = $entry->diff($exit);
        $totalMinutes = ($interval->days * 24 * 60) + ($interval->h * 60) + $interval->i;
        
        // Human readable representation
        $parts = [];
        if ($interval->days > 0) {
            $parts[] = $interval->days . 'd';
        }
        if ($interval->h > 0 || $interval->days > 0) {
            $parts[] = $interval->h . 'h';
        }
        $parts[] = $interval->i . 'm';

        return [
            'total_minutes' => max(1, $totalMinutes),
            'days'          => $interval->days,
            'hours'         => $interval->h,
            'minutes'       => $interval->i,
            'formatted'     => implode(' ', $parts)
        ];
    } catch (Exception) {
        return [
            'total_minutes' => 1,
            'days'          => 0,
            'hours'         => 0,
            'minutes'       => 1,
            'formatted'     => '1m'
        ];
    }
}

/**
 * Authoritative Server-side Parking Charge Calculation
 * 
 * Billing Rule:
 * 1. Calculate elapsed duration in minutes (minimum 1 minute).
 * 2. Group into billing increments (default: 60 minutes = 1 hour). Any started increment counts as a full increment.
 * 3. Enforce minimum billable hours.
 * 4. Multiply billable hours by the snapshot hourly rate.
 */
function calculate_parking_charge(
    string $entryTime,
    ?string $exitTime,
    float $ratePerHour,
    int $minHours = 1,
    int $incrementMinutes = 60
): array {
    $duration = calculate_parking_duration($entryTime, $exitTime);
    $totalMinutes = $duration['total_minutes'];

    $incMin = ($incrementMinutes > 0) ? $incrementMinutes : 60;
    $minH = ($minHours > 0) ? $minHours : 1;

    // Calculate number of increments consumed
    $incrementsConsumed = (int) ceil($totalMinutes / $incMin);
    
    // Billable hours calculated from increments
    $calculatedHours = ($incrementsConsumed * $incMin) / 60.0;
    
    // Apply minimum hours floor
    $billableHours = max((float)$minH, $calculatedHours);

    // Final total charge rounded to 2 decimal places
    $totalAmount = round($billableHours * $ratePerHour, 2);

    return [
        'duration_minutes' => $totalMinutes,
        'duration_text'    => $duration['formatted'],
        'billable_hours'   => $billableHours,
        'rate_per_hour'    => $ratePerHour,
        'min_hours'        => $minH,
        'increment_mins'   => $incMin,
        'amount'           => $totalAmount
    ];
}

/**
 * Ticket Number Generator
 */
function generate_ticket_number(int $sessionId): string
{
    return 'TKT-' . str_pad((string)$sessionId, 6, '0', STR_PAD_LEFT);
}

/**
 * Vehicle Types Configuration Matrix
 */
function get_vehicle_types(): array
{
    return [
        'TWO_WHEELER' => [
            'label' => 'Two Wheeler (Bike/Scooter)',
            'short' => '2-Wheeler',
            'badge' => 'badge-two-wheeler',
            'icon'  => 'bi-bicycle'
        ],
        'CAR' => [
            'label' => 'Standard Car / Sedan',
            'short' => 'Car',
            'badge' => 'badge-car',
            'icon'  => 'bi-car-front'
        ],
        'SUV' => [
            'label' => 'SUV / MUV / Compact Truck',
            'short' => 'SUV',
            'badge' => 'badge-suv',
            'icon'  => 'bi-truck'
        ],
        'HEAVY' => [
            'label' => 'Heavy Commercial Vehicle',
            'short' => 'Heavy',
            'badge' => 'badge-heavy',
            'icon'  => 'bi-bus-front'
        ]
    ];
}

/**
 * Slot Status Matrix
 */
function get_slot_statuses(): array
{
    return [
        'AVAILABLE' => [
            'label' => 'Available',
            'badge' => 'bg-success',
            'icon'  => 'bi-check-circle'
        ],
        'OCCUPIED' => [
            'label' => 'Occupied',
            'badge' => 'bg-danger',
            'icon'  => 'bi-car-front-fill'
        ],
        'OUT_OF_SERVICE' => [
            'label' => 'Out of Service',
            'badge' => 'bg-secondary',
            'icon'  => 'bi-slash-circle'
        ]
    ];
}

/**
 * Payment Methods Matrix
 */
function get_payment_methods(): array
{
    return [
        'CASH' => [
            'label' => 'Cash Payment',
            'icon'  => 'bi-cash-stack',
            'badge' => 'bg-success'
        ],
        'UPI' => [
            'label' => 'UPI / QR Code',
            'icon'  => 'bi-qr-code-scan',
            'badge' => 'bg-info text-dark'
        ],
        'CARD' => [
            'label' => 'Credit / Debit Card',
            'icon'  => 'bi-credit-card-2-front',
            'badge' => 'bg-primary'
        ]
    ];
}
