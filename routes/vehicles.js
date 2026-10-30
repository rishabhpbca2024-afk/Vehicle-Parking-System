const express = require('express');
const router = express.Router();
const pool = require('../config/db');
const { requireAuth } = require('../middleware/auth');
const { validateVehicleRegistrationNumber, normalizeRegistrationNumber } = require('../utils/plateValidator');
const { calculateParkingCharge } = require('../utils/billingEngine');
const { 
    formatCurrency, 
    formatDateTime, 
    calculateParkingDuration, 
    generateTicketNumber, 
    getVehicleTypes, 
    getPaymentMethods 
} = require('../utils/helpers');

// =========================================================================
// 1. VEHICLE ENTRY / CHECK-IN
// =========================================================================

// GET /vehicles/entry
router.get('/entry', requireAuth, async (req, res) => {
    try {
        const vehicleTypesMap = getVehicleTypes();

        // Fetch active parking rates
        const [rates] = await pool.query("SELECT vehicle_type, hourly_rate, minimum_charge_hours, billing_increment_minutes FROM parking_rates WHERE is_active = 1");
        const activeRates = {};
        rates.forEach(r => { activeRates[r.vehicle_type] = parseFloat(r.hourly_rate); });

        // Fetch available slots
        const [availableSlots] = await pool.query(`
            SELECT id, slot_number, floor_number, vehicle_type 
            FROM parking_slots 
            WHERE status = 'AVAILABLE' 
            ORDER BY floor_number ASC, slot_number ASC
        `);

        res.render('vehicles/entry', {
            pageTitle: 'Vehicle Entry / Check-In',
            activeNav: 'entry',
            vehicleTypesMap,
            activeRates,
            availableSlots,
            registrationNumber: '',
            selectedVehicleType: 'CAR',
            selectedSlotId: '',
            errors: []
        });
    } catch (err) {
        console.error('Entry GET error:', err);
        req.flash('danger', 'Unable to load vehicle entry form.');
        res.redirect('/dashboard');
    }
});

// POST /vehicles/entry
router.post('/entry', requireAuth, async (req, res) => {
    const rawReg = req.body.registration_number || '';
    const selectedVehicleType = req.body.vehicle_type || 'CAR';
    const selectedSlotId = req.body.slot_id ? parseInt(req.body.slot_id, 10) : 0;
    const vehicleTypesMap = getVehicleTypes();
    const user = req.session.user;

    // Fetch active rates & available slots for re-rendering on error
    const [rates] = await pool.query("SELECT vehicle_type, hourly_rate, minimum_charge_hours, billing_increment_minutes FROM parking_rates WHERE is_active = 1");
    const activeRates = {};
    rates.forEach(r => { activeRates[r.vehicle_type] = parseFloat(r.hourly_rate); });

    const [availableSlots] = await pool.query(`
        SELECT id, slot_number, floor_number, vehicle_type 
        FROM parking_slots 
        WHERE status = 'AVAILABLE' 
        ORDER BY floor_number ASC, slot_number ASC
    `);

    const errors = [];

    // Indian Plate Validation
    const plateCheck = validateVehicleRegistrationNumber(rawReg);
    let registrationNumber = '';
    if (!plateCheck.is_valid) {
        errors.push(plateCheck.error);
        registrationNumber = normalizeRegistrationNumber(rawReg);
    } else {
        registrationNumber = plateCheck.formatted;
    }

    if (!vehicleTypesMap[selectedVehicleType]) {
        errors.push('Please select a valid vehicle type.');
    }

    if (!activeRates[selectedVehicleType]) {
        errors.push(`No active parking rate has been configured for vehicle type '${selectedVehicleType}'.`);
    }

    if (errors.length > 0) {
        return res.render('vehicles/entry', {
            pageTitle: 'Vehicle Entry / Check-In',
            activeNav: 'entry',
            vehicleTypesMap,
            activeRates,
            availableSlots,
            registrationNumber,
            selectedVehicleType,
            selectedSlotId,
            errors
        });
    }

    // Begin Transaction
    const conn = await pool.getConnection();
    try {
        await conn.beginTransaction();

        // 1. Find or create vehicle
        const [existingVehicles] = await conn.query("SELECT id, vehicle_type FROM vehicles WHERE registration_number = ? LIMIT 1", [registrationNumber]);
        let vehicleId;

        if (existingVehicles.length > 0) {
            vehicleId = existingVehicles[0].id;

            // Check if vehicle already has an active session
            const [activeSessions] = await conn.query("SELECT id FROM parking_sessions WHERE vehicle_id = ? AND status = 'ACTIVE' LIMIT 1", [vehicleId]);
            if (activeSessions.length > 0) {
                await conn.rollback();
                conn.release();
                const ticketNum = generateTicketNumber(activeSessions[0].id);
                errors.push(`Vehicle ${registrationNumber} is already parked inside with active ticket ${ticketNum}. Please checkout first.`);
                return res.render('vehicles/entry', {
                    pageTitle: 'Vehicle Entry / Check-In',
                    activeNav: 'entry',
                    vehicleTypesMap,
                    activeRates,
                    availableSlots,
                    registrationNumber,
                    selectedVehicleType,
                    selectedSlotId,
                    errors
                });
            }

            // Update vehicle type if operator changed it
            await conn.query("UPDATE vehicles SET vehicle_type = ?, updated_at = NOW() WHERE id = ?", [selectedVehicleType, vehicleId]);
        } else {
            const [newVehResult] = await conn.query("INSERT INTO vehicles (registration_number, vehicle_type, created_at, updated_at) VALUES (?, ?, NOW(), NOW())", [registrationNumber, selectedVehicleType]);
            vehicleId = newVehResult.insertId;
        }

        // 2. Allocate Slot with FOR UPDATE row locking
        let slotIdToUse = 0;
        if (selectedSlotId > 0) {
            const [manualSlots] = await conn.query("SELECT id, status, vehicle_type FROM parking_slots WHERE id = ? FOR UPDATE", [selectedSlotId]);
            if (manualSlots.length === 0 || manualSlots[0].status !== 'AVAILABLE') {
                await conn.rollback();
                conn.release();
                errors.push('The manually selected slot is no longer available. Please select another slot or choose Auto-Allocate.');
                return res.render('vehicles/entry', {
                    pageTitle: 'Vehicle Entry / Check-In',
                    activeNav: 'entry',
                    vehicleTypesMap,
                    activeRates,
                    availableSlots,
                    registrationNumber,
                    selectedVehicleType,
                    selectedSlotId,
                    errors
                });
            }
            slotIdToUse = manualSlots[0].id;
        } else {
            // Auto-allocate nearest available slot matching vehicle type
            const [autoSlots] = await conn.query(`
                SELECT id 
                FROM parking_slots 
                WHERE status = 'AVAILABLE' AND vehicle_type = ? 
                ORDER BY floor_number ASC, slot_number ASC 
                LIMIT 1 
                FOR UPDATE
            `, [selectedVehicleType]);

            if (autoSlots.length === 0) {
                await conn.rollback();
                conn.release();
                errors.push(`No available parking space is currently available for category '${vehicleTypesMap[selectedVehicleType].label}'.`);
                return res.render('vehicles/entry', {
                    pageTitle: 'Vehicle Entry / Check-In',
                    activeNav: 'entry',
                    vehicleTypesMap,
                    activeRates,
                    availableSlots,
                    registrationNumber,
                    selectedVehicleType,
                    selectedSlotId,
                    errors
                });
            }
            slotIdToUse = autoSlots[0].id;
        }

        // 3. Mark Slot as OCCUPIED
        await conn.query("UPDATE parking_slots SET status = 'OCCUPIED', updated_at = NOW() WHERE id = ?", [slotIdToUse]);

        // 4. Create Parking Session with Snapshot Rate
        const snapshotRate = activeRates[selectedVehicleType];
        const [sessionResult] = await conn.query(`
            INSERT INTO parking_sessions (vehicle_id, slot_id, created_by, entry_time, rate_per_hour, amount, status, created_at, updated_at)
            VALUES (?, ?, ?, NOW(), ?, 0.00, 'ACTIVE', NOW(), NOW())
        `, [vehicleId, slotIdToUse, user.id, snapshotRate]);

        await conn.commit();
        conn.release();

        const newSessionId = sessionResult.insertId;
        req.flash('success', `Vehicle ${registrationNumber} checked in successfully! Ticket generated.`);
        res.redirect(`/vehicles/ticket/${newSessionId}`);
    } catch (err) {
        await conn.rollback();
        conn.release();
        console.error('Vehicle Check-in Transaction Error:', err);
        errors.push('An unexpected error occurred during vehicle check-in: ' + err.message);
        res.render('vehicles/entry', {
            pageTitle: 'Vehicle Entry / Check-In',
            activeNav: 'entry',
            vehicleTypesMap,
            activeRates,
            availableSlots,
            registrationNumber,
            selectedVehicleType,
            selectedSlotId,
            errors
        });
    }
});

// =========================================================================
// 2. PRINTABLE PARKING TICKET
// =========================================================================

// GET /vehicles/ticket/:id
router.get('/ticket/:id', requireAuth, async (req, res) => {
    const sessionId = parseInt(req.params.id, 10);
    if (!sessionId) return res.redirect('/vehicles/entry');

    try {
        const [rows] = await pool.query(`
            SELECT 
                ps.id,
                ps.entry_time,
                ps.rate_per_hour,
                ps.status,
                v.registration_number,
                v.vehicle_type,
                s.slot_number,
                s.floor_number,
                u.name as operator_name,
                u.role as operator_role
            FROM parking_sessions ps
            JOIN vehicles v ON ps.vehicle_id = v.id
            JOIN parking_slots s ON ps.slot_id = s.id
            LEFT JOIN users u ON ps.created_by = u.id
            WHERE ps.id = ?
            LIMIT 1
        `, [sessionId]);

        if (rows.length === 0) {
            req.flash('danger', 'Parking session ticket not found.');
            return res.redirect('/vehicles/active');
        }

        const ticket = rows[0];
        const roleRaw = String(ticket.operator_role || 'OPERATOR').toUpperCase();
        const roleLabel = (roleRaw === 'ADMIN') ? 'Admin' : ((roleRaw === 'OPERATOR') ? 'Operator' : roleRaw);
        const issuerName = ticket.operator_name || 'System';
        const vehicleTypesMap = getVehicleTypes();
        const vMeta = vehicleTypesMap[ticket.vehicle_type] || { short: ticket.vehicle_type, label: ticket.vehicle_type };

        res.render('vehicles/ticket', {
            pageTitle: `Parking Ticket #${generateTicketNumber(ticket.id)}`,
            activeNav: 'entry',
            ticket,
            roleLabel,
            issuerName,
            vMeta,
            formatCurrency,
            formatDateTime,
            generateTicketNumber
        });
    } catch (err) {
        console.error('Ticket error:', err);
        req.flash('danger', 'Unable to retrieve parking ticket.');
        res.redirect('/vehicles/active');
    }
});

// =========================================================================
// 3. ACTIVE PARKED VEHICLES
// =========================================================================

// GET /vehicles/active
router.get('/active', requireAuth, async (req, res) => {
    try {
        const search = String(req.query.search || '').trim();
        const filterType = String(req.query.type || '').trim();
        const vehicleTypesMap = getVehicleTypes();

        let query = `
            SELECT 
                ps.id,
                ps.entry_time,
                ps.rate_per_hour,
                v.registration_number,
                v.vehicle_type,
                s.slot_number,
                s.floor_number,
                u.name as operator_name
            FROM parking_sessions ps
            JOIN vehicles v ON ps.vehicle_id = v.id
            JOIN parking_slots s ON ps.slot_id = s.id
            LEFT JOIN users u ON ps.created_by = u.id
            WHERE ps.status = 'ACTIVE'
        `;
        const params = [];

        if (search) {
            query += ` AND (v.registration_number LIKE ? OR s.slot_number LIKE ?)`;
            params.push(`%${search}%`, `%${search}%`);
        }

        if (filterType && vehicleTypesMap[filterType]) {
            query += ` AND v.vehicle_type = ?`;
            params.push(filterType);
        }

        query += ` ORDER BY ps.entry_time DESC`;

        const [sessions] = await pool.query(query, params);

        // Fetch rate configurations for live billing calculation
        const [rateRows] = await pool.query("SELECT vehicle_type, minimum_charge_hours, billing_increment_minutes FROM parking_rates");
        const rateMeta = {};
        rateRows.forEach(r => { rateMeta[r.vehicle_type] = r; });

        // Augment each session with live duration & live charge
        const augmentedSessions = sessions.map(sess => {
            const meta = rateMeta[sess.vehicle_type] || {};
            const minH = parseInt(meta.minimum_charge_hours || 1, 10);
            const incM = parseInt(meta.billing_increment_minutes || 60, 10);
            const billing = calculateParkingCharge(sess.entry_time, null, sess.rate_per_hour, minH, incM);
            return {
                ...sess,
                duration: billing.duration_text,
                estimatedAmount: billing.amount
            };
        });

        res.render('vehicles/active', {
            pageTitle: 'Active Parked Vehicles',
            activeNav: 'active',
            sessions: augmentedSessions,
            vehicleTypesMap,
            search,
            filterType,
            formatCurrency,
            formatDateTime,
            generateTicketNumber
        });
    } catch (err) {
        console.error('Active vehicles error:', err);
        req.flash('danger', 'Unable to retrieve active parked vehicles list.');
        res.redirect('/dashboard');
    }
});

// =========================================================================
// 4. CHECKOUT & EXIT BILLING CONSOLE
// =========================================================================

// GET /vehicles/checkout/:id
router.get('/checkout/:id', requireAuth, async (req, res) => {
    const sessionId = parseInt(req.params.id, 10);
    if (!sessionId) return res.redirect('/vehicles/active');

    try {
        const [rows] = await pool.query(`
            SELECT 
                ps.id,
                ps.vehicle_id,
                ps.slot_id,
                ps.entry_time,
                ps.rate_per_hour,
                ps.status,
                v.registration_number,
                v.vehicle_type,
                s.slot_number,
                s.floor_number
            FROM parking_sessions ps
            JOIN vehicles v ON ps.vehicle_id = v.id
            JOIN parking_slots s ON ps.slot_id = s.id
            WHERE ps.id = ?
            LIMIT 1
        `, [sessionId]);

        if (rows.length === 0) {
            req.flash('danger', 'Parking session not found.');
            return res.redirect('/vehicles/active');
        }

        const session = rows[0];
        if (session.status !== 'ACTIVE') {
            req.flash('warning', 'This parking session has already been completed and checked out.');
            return res.redirect(`/payments/receipt/${session.id}`);
        }

        // Fetch rate rule configuration
        const [rateRows] = await pool.query("SELECT minimum_charge_hours, billing_increment_minutes FROM parking_rates WHERE vehicle_type = ? LIMIT 1", [session.vehicle_type]);
        const minHours = rateRows[0] ? parseInt(rateRows[0].minimum_charge_hours, 10) : 1;
        const incMins = rateRows[0] ? parseInt(rateRows[0].billing_increment_minutes, 10) : 60;

        const previewBilling = calculateParkingCharge(session.entry_time, null, session.rate_per_hour, minHours, incMins);
        const vehicleTypesMap = getVehicleTypes();
        const paymentMethods = getPaymentMethods();
        const vMeta = vehicleTypesMap[session.vehicle_type] || { short: session.vehicle_type, label: session.vehicle_type };

        res.render('vehicles/checkout', {
            pageTitle: 'Vehicle Checkout & Billing',
            activeNav: 'active',
            session,
            previewBilling,
            vMeta,
            vehicleTypesMap,
            paymentMethods,
            formatCurrency,
            formatDateTime,
            generateTicketNumber,
            errors: []
        });
    } catch (err) {
        console.error('Checkout GET error:', err);
        req.flash('danger', 'Unable to load checkout console.');
        res.redirect('/vehicles/active');
    }
});

// POST /vehicles/checkout/:id
router.post('/checkout/:id', requireAuth, async (req, res) => {
    const sessionId = parseInt(req.params.id, 10);
    const paymentMethod = String(req.body.payment_method || 'CASH').toUpperCase();
    const paymentMethods = getPaymentMethods();

    if (!paymentMethods[paymentMethod]) {
        req.flash('danger', 'Please select a valid payment method.');
        return res.redirect(`/vehicles/checkout/${sessionId}`);
    }

    const conn = await pool.getConnection();
    try {
        await conn.beginTransaction();

        // 1. Lock and retrieve session
        const [sessions] = await conn.query(`
            SELECT 
                ps.id,
                ps.slot_id,
                ps.entry_time,
                ps.rate_per_hour,
                ps.status,
                v.vehicle_type,
                v.registration_number
            FROM parking_sessions ps
            JOIN vehicles v ON ps.vehicle_id = v.id
            WHERE ps.id = ? 
            FOR UPDATE
        `, [sessionId]);

        if (sessions.length === 0) {
            await conn.rollback();
            conn.release();
            req.flash('danger', 'Parking session not found.');
            return res.redirect('/vehicles/active');
        }

        const session = sessions[0];
        if (session.status !== 'ACTIVE') {
            await conn.rollback();
            conn.release();
            req.flash('warning', 'This session has already been checked out.');
            return res.redirect(`/payments/receipt/${sessionId}`);
        }

        // 2. Calculate Final Authoritative Charge
        const [rateRows] = await conn.query("SELECT minimum_charge_hours, billing_increment_minutes FROM parking_rates WHERE vehicle_type = ? LIMIT 1", [session.vehicle_type]);
        const minHours = rateRows[0] ? parseInt(rateRows[0].minimum_charge_hours, 10) : 1;
        const incMins = rateRows[0] ? parseInt(rateRows[0].billing_increment_minutes, 10) : 60;

        const finalBilling = calculateParkingCharge(session.entry_time, null, session.rate_per_hour, minHours, incMins);
        const finalAmount = finalBilling.amount;

        // 3. Complete Session
        await conn.query(`
            UPDATE parking_sessions 
            SET status = 'COMPLETED', exit_time = NOW(), amount = ?, updated_at = NOW() 
            WHERE id = ?
        `, [finalAmount, sessionId]);

        // 4. Record Payment
        await conn.query(`
            INSERT INTO payments (session_id, amount, method, status, paid_at, created_at)
            VALUES (?, ?, ?, 'PAID', NOW(), NOW())
        `, [sessionId, finalAmount, paymentMethod]);

        // 5. Release Parking Slot back to AVAILABLE
        await conn.query(`
            UPDATE parking_slots 
            SET status = 'AVAILABLE', updated_at = NOW() 
            WHERE id = ?
        `, [session.slot_id]);

        await conn.commit();
        conn.release();

        req.flash('success', `Checkout successful! Payment of ${formatCurrency(finalAmount)} recorded and slot released.`);
        res.redirect(`/payments/receipt/${sessionId}`);
    } catch (err) {
        await conn.rollback();
        conn.release();
        console.error('Checkout POST Transaction error:', err);
        req.flash('danger', 'An error occurred during checkout: ' + err.message);
        res.redirect(`/vehicles/checkout/${sessionId}`);
    }
});

// =========================================================================
// 5. PARKING SESSIONS HISTORY
// =========================================================================

// GET /vehicles/history
router.get('/history', requireAuth, async (req, res) => {
    try {
        const search = String(req.query.search || '').trim();
        const dateFrom = String(req.query.from || '').trim();
        const dateTo = String(req.query.to || '').trim();
        const filterType = String(req.query.type || '').trim();
        const vehicleTypesMap = getVehicleTypes();

        let query = `
            SELECT 
                ps.id as session_id,
                ps.entry_time,
                ps.exit_time,
                ps.rate_per_hour,
                ps.amount as session_amount,
                ps.status as session_status,
                v.registration_number,
                v.vehicle_type,
                s.slot_number,
                s.floor_number,
                p.id as payment_id,
                p.amount as payment_amount,
                p.method as payment_method,
                p.paid_at,
                u.name as operator_name
            FROM parking_sessions ps
            JOIN vehicles v ON ps.vehicle_id = v.id
            JOIN parking_slots s ON ps.slot_id = s.id
            LEFT JOIN payments p ON ps.id = p.session_id
            LEFT JOIN users u ON ps.created_by = u.id
            WHERE ps.status = 'COMPLETED'
        `;
        const params = [];

        if (search) {
            query += ` AND (v.registration_number LIKE ? OR s.slot_number LIKE ?)`;
            params.push(`%${search}%`, `%${search}%`);
        }

        if (filterType && vehicleTypesMap[filterType]) {
            query += ` AND v.vehicle_type = ?`;
            params.push(filterType);
        }

        if (dateFrom) {
            query += ` AND DATE(ps.entry_time) >= ?`;
            params.push(dateFrom);
        }

        if (dateTo) {
            query += ` AND DATE(ps.entry_time) <= ?`;
            params.push(dateTo);
        }

        query += ` ORDER BY ps.exit_time DESC LIMIT 100`;

        const [history] = await pool.query(query, params);

        res.render('vehicles/history', {
            pageTitle: 'Parking Sessions History',
            activeNav: 'history',
            history,
            vehicleTypesMap,
            search,
            dateFrom,
            dateTo,
            filterType,
            formatCurrency,
            formatDateTime,
            calculateParkingDuration,
            generateTicketNumber
        });
    } catch (err) {
        console.error('History error:', err);
        req.flash('danger', 'Unable to retrieve parking history.');
        res.redirect('/dashboard');
    }
});

module.exports = router;

