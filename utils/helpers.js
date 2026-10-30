/**
 * Application Helpers & Utility Functions
 */

const CURRENCY_SYMBOL = process.env.APP_CURRENCY_SYMBOL || '₹';
const CURRENCY_CODE = process.env.APP_CURRENCY_CODE || 'INR';

function formatCurrency(amount) {
    const val = parseFloat(amount) || 0;
    return `${CURRENCY_SYMBOL} ${val.toFixed(2)}`;
}

function formatDateTime(dateInput, options = {}) {
    if (!dateInput) return '—';
    try {
        const d = new Date(dateInput);
        if (isNaN(d.getTime())) return String(dateInput);

        const defaultOpts = {
            day: '2-digit',
            month: 'short',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
            hour12: true,
            timeZone: process.env.APP_TIMEZONE || 'Asia/Kolkata',
            ...options
        };
        return new Intl.DateTimeFormat('en-IN', defaultOpts).format(d);
    } catch {
        return String(dateInput);
    }
}

function calculateParkingDuration(entryTime, exitTime = null) {
    try {
        const entry = new Date(entryTime);
        const exit = exitTime ? new Date(exitTime) : new Date();

        const diffMs = Math.max(0, exit - entry);
        const totalMinutes = Math.max(1, Math.floor(diffMs / 60000));

        const days = Math.floor(totalMinutes / (24 * 60));
        const remMinutes = totalMinutes % (24 * 60);
        const hours = Math.floor(remMinutes / 60);
        const minutes = remMinutes % 60;

        const parts = [];
        if (days > 0) parts.push(`${days}d`);
        if (hours > 0 || days > 0) parts.push(`${hours}h`);
        parts.push(`${minutes}m`);

        return {
            total_minutes: totalMinutes,
            days,
            hours,
            minutes,
            formatted: parts.join(' ')
        };
    } catch {
        return {
            total_minutes: 1,
            days: 0,
            hours: 0,
            minutes: 1,
            formatted: '1m'
        };
    }
}

function generateTicketNumber(sessionId) {
    return 'TKT-' + String(sessionId).padStart(6, '0');
}

function generateReceiptNumber(paymentId) {
    return 'RCP-' + String(paymentId).padStart(6, '0');
}

function getVehicleTypes() {
    return {
        TWO_WHEELER: {
            label: 'Two Wheeler (Bike/Scooter)',
            short: '2-Wheeler',
            badge: 'badge-two-wheeler',
            icon: 'bi-bicycle'
        },
        CAR: {
            label: 'Standard Car / Sedan',
            short: 'Car',
            badge: 'badge-car',
            icon: 'bi-car-front'
        },
        SUV: {
            label: 'SUV / MUV / Compact Truck',
            short: 'SUV',
            badge: 'badge-suv',
            icon: 'bi-truck'
        },
        HEAVY: {
            label: 'Heavy Commercial Vehicle',
            short: 'Heavy',
            badge: 'badge-heavy',
            icon: 'bi-bus-front'
        }
    };
}

function getSlotStatuses() {
    return {
        AVAILABLE: {
            label: 'Available',
            badge: 'bg-success',
            icon: 'bi-check-circle'
        },
        OCCUPIED: {
            label: 'Occupied',
            badge: 'bg-danger',
            icon: 'bi-car-front-fill'
        },
        OUT_OF_SERVICE: {
            label: 'Out of Service',
            badge: 'bg-secondary',
            icon: 'bi-slash-circle'
        }
    };
}

function getPaymentMethods() {
    return {
        CASH: {
            label: 'Cash Payment',
            icon: 'bi-cash-stack',
            badge: 'bg-success'
        },
        UPI: {
            label: 'UPI / QR Code',
            icon: 'bi-qr-code-scan',
            badge: 'bg-info text-dark'
        },
        CARD: {
            label: 'Credit / Debit Card',
            icon: 'bi-credit-card-2-front',
            badge: 'bg-primary'
        }
    };
}

module.exports = {
    CURRENCY_SYMBOL,
    CURRENCY_CODE,
    formatCurrency,
    formatDateTime,
    calculateParkingDuration,
    generateTicketNumber,
    generateReceiptNumber,
    getVehicleTypes,
    getSlotStatuses,
    getPaymentMethods
};
