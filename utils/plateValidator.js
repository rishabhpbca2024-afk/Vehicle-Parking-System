/**
 * Indian Vehicle Plate Validation & Normalization
 */

const VALID_INDIAN_STATES = new Set([
    'AN', 'AP', 'AR', 'AS', 'BR', 'CH', 'CG', 'DD', 'DN', 'DL', 'GA', 'GJ',
    'HR', 'HP', 'JH', 'JK', 'KA', 'KL', 'LA', 'LD', 'MP', 'MH', 'MN', 'ML',
    'MZ', 'NL', 'OD', 'OR', 'PB', 'PY', 'RJ', 'SK', 'TN', 'TS', 'TR', 'UP',
    'UK', 'UA', 'WB'
]);

function normalizeRegistrationNumber(regNumber) {
    if (!regNumber) return '';
    return String(regNumber).trim().replace(/\s+/g, ' ').toUpperCase();
}

function validateVehicleRegistrationNumber(regNumber) {
    const raw = String(regNumber || '').trim();
    if (!raw) {
        return {
            is_valid: false,
            formatted: '',
            error: 'Vehicle registration number is required.'
        };
    }

    // Strip internal spaces and hyphens
    const clean = raw.toUpperCase().replace(/[\s\-]/g, '');

    // Reject special characters
    if (/[^A-Z0-9]/.test(clean)) {
        return {
            is_valid: false,
            formatted: raw,
            error: 'Registration number cannot contain special characters.'
        };
    }

    if (clean.length < 5 || clean.length > 14) {
        return {
            is_valid: false,
            formatted: raw,
            error: 'Registration number length is invalid (must be 5 to 14 characters).'
        };
    }

    // 1. Check Bharat Series (BH) - e.g. 22BH1234AA
    const bhMatch = clean.match(/^([0-9]{2})BH([0-9]{4})([A-Z]{1,2})$/);
    if (bhMatch) {
        return {
            is_valid: true,
            formatted: `${bhMatch[1]} BH ${bhMatch[2]} ${bhMatch[3]}`,
            type: 'BH Series',
            error: ''
        };
    }

    // 2. Standard State Code check
    const stateCode = clean.substring(0, 2);
    if (!VALID_INDIAN_STATES.has(stateCode)) {
        return {
            is_valid: false,
            formatted: raw,
            error: `Invalid state code '${stateCode}'. Must start with a valid Indian State/UT code (e.g. MH, DL, UP, KA, HR, RJ, GJ, MP).`
        };
    }

    // 3. Match State(2) + RTO(1-2 digits) + Series(0-4 letters) + Number(1-4 digits)
    const stdMatch = clean.match(/^([A-Z]{2})([0-9]{1,2})([A-Z]{0,4})([0-9]{1,4})$/);
    if (stdMatch) {
        const rto = stdMatch[2];
        const series = stdMatch[3];
        const digits = stdMatch[4];

        if (rto === '00') {
            return {
                is_valid: false,
                formatted: raw,
                error: 'RTO district code cannot be 00.'
            };
        }

        if (parseInt(digits, 10) === 0) {
            return {
                is_valid: false,
                formatted: raw,
                error: 'Vehicle number cannot be 0000.'
            };
        }

        const formatted = `${stdMatch[1]} ${rto}${series ? ' ' + series : ''} ${digits}`;
        return {
            is_valid: true,
            formatted: formatted,
            type: 'Standard RTO',
            error: ''
        };
    }

    return {
        is_valid: false,
        formatted: raw,
        error: 'Invalid vehicle registration format. Valid formats: MH 12 AB 1234, DL 01 C 4567, or 22 BH 1234 AA.'
    };
}

module.exports = {
    normalizeRegistrationNumber,
    validateVehicleRegistrationNumber,
    VALID_INDIAN_STATES
};

