/**
 * Authoritative Server-side Parking Charge Calculation Engine
 */

const { calculateParkingDuration } = require('./helpers');

function calculateParkingCharge(entryTime, exitTime, ratePerHour, minHours = 1, incrementMinutes = 60) {
    const duration = calculateParkingDuration(entryTime, exitTime);
    const totalMinutes = duration.total_minutes;

    const incMin = incrementMinutes > 0 ? incrementMinutes : 60;
    const minH = minHours > 0 ? minHours : 1;

    // Calculate increments consumed
    const incrementsConsumed = Math.ceil(totalMinutes / incMin);

    // Billable hours calculated from increments
    const calculatedHours = (incrementsConsumed * incMin) / 60.0;

    // Minimum billable hours floor
    const billableHours = Math.max(minH, calculatedHours);

    // Rate calculation
    const rate = parseFloat(ratePerHour) || 0;
    const totalAmount = Math.round(billableHours * rate * 100) / 100;

    return {
        duration_minutes: totalMinutes,
        duration_text: duration.formatted,
        billable_hours: billableHours,
        rate_per_hour: rate,
        min_hours: minH,
        increment_mins: incMin,
        amount: totalAmount
    };
}

module.exports = {
    calculateParkingCharge
};

