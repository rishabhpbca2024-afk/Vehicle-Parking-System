const express = require('express');
const router = express.Router();
const pool = require('../config/db');
const { requireAuth, requireAdmin } = require('../middleware/auth');
const { getVehicleTypes, getSlotStatuses } = require('../utils/helpers');

// GET /slots
router.get('/', requireAuth, async (req, res) => {
    try {
        const filterFloor = String(req.query.floor || '').trim();
        const filterType = String(req.query.vehicle_type || '').trim();
        const filterStatus = String(req.query.status || '').trim();
        const searchSlot = String(req.query.search || '').trim();
        const viewMode = req.query.view === 'grid' ? 'grid' : 'table';

        const vehicleTypesMap = getVehicleTypes();
        const slotStatusesMap = getSlotStatuses();

        let query = `
            SELECT 
                ps.id,
                ps.slot_number,
                ps.floor_number,
                ps.vehicle_type,
                ps.status,
                ps.created_at,
                active_sess.id as active_session_id,
                active_veh.registration_number as active_reg_number
            FROM parking_slots ps
            LEFT JOIN parking_sessions active_sess ON ps.id = active_sess.slot_id AND active_sess.status = 'ACTIVE'
            LEFT JOIN vehicles active_veh ON active_sess.vehicle_id = active_veh.id
            WHERE 1=1
        `;
        const params = [];

        if (filterFloor) {
            query += ` AND ps.floor_number = ?`;
            params.push(filterFloor);
        }
        if (filterType && vehicleTypesMap[filterType]) {
            query += ` AND ps.vehicle_type = ?`;
            params.push(filterType);
        }
        if (filterStatus && slotStatusesMap[filterStatus]) {
            query += ` AND ps.status = ?`;
            params.push(filterStatus);
        }
        if (searchSlot) {
            query += ` AND ps.slot_number LIKE ?`;
            params.push(`%${searchSlot}%`);
        }

        query += ` ORDER BY ps.floor_number ASC, ps.slot_number ASC`;

        const [slots] = await pool.query(query, params);

        // Fetch distinct floors
        const [floorRows] = await pool.query("SELECT DISTINCT floor_number FROM parking_slots ORDER BY floor_number ASC");
        const availableFloors = floorRows.map(r => r.floor_number);

        res.render('slots/index', {
            pageTitle: 'Parking Slot & Capacity Management',
            activeNav: 'slots',
            slots,
            availableFloors,
            vehicleTypesMap,
            slotStatusesMap,
            filterFloor,
            filterType,
            filterStatus,
            searchSlot,
            viewMode
        });
    } catch (err) {
        console.error('Slots error:', err);
        req.flash('danger', 'Unable to retrieve parking slots.');
        res.redirect('/dashboard');
    }
});

// POST /slots/add (Admin only)
router.post('/add', requireAdmin, async (req, res) => {
    const slotNumber = String(req.body.slot_number || '').trim().toUpperCase();
    const floorNumber = String(req.body.floor_number || '').trim();
    const vehicleType = String(req.body.vehicle_type || '').trim();
    const vehicleTypesMap = getVehicleTypes();

    if (!slotNumber || !floorNumber || !vehicleTypesMap[vehicleType]) {
        req.flash('danger', 'Please provide valid slot number, floor, and vehicle category.');
        return res.redirect('/slots');
    }

    try {
        const [existing] = await pool.query("SELECT id FROM parking_slots WHERE slot_number = ? LIMIT 1", [slotNumber]);
        if (existing.length > 0) {
            req.flash('danger', `Slot ${slotNumber} already exists.`);
            return res.redirect('/slots');
        }

        await pool.query(`
            INSERT INTO parking_slots (slot_number, floor_number, vehicle_type, status, created_at, updated_at)
            VALUES (?, ?, ?, 'AVAILABLE', NOW(), NOW())
        `, [slotNumber, floorNumber, vehicleType]);

        req.flash('success', `Parking slot ${slotNumber} created successfully!`);
        res.redirect('/slots');
    } catch (err) {
        console.error('Add slot error:', err);
        req.flash('danger', 'Failed to add parking slot.');
        res.redirect('/slots');
    }
});

// POST /slots/toggle-status (Admin only)
router.post('/toggle-status', requireAdmin, async (req, res) => {
    const slotId = parseInt(req.body.slot_id, 10);
    const targetStatus = String(req.body.status || '').toUpperCase();
    const slotStatusesMap = getSlotStatuses();

    if (!slotId || !slotStatusesMap[targetStatus]) {
        req.flash('danger', 'Invalid slot request.');
        return res.redirect('/slots');
    }

    try {
        const [slots] = await pool.query("SELECT * FROM parking_slots WHERE id = ? LIMIT 1", [slotId]);
        if (slots.length === 0) {
            req.flash('danger', 'Slot not found.');
            return res.redirect('/slots');
        }

        const slot = slots[0];
        if (slot.status === 'OCCUPIED') {
            req.flash('danger', `Slot ${slot.slot_number} is currently occupied by an active parked vehicle and cannot be modified.`);
            return res.redirect('/slots');
        }

        await pool.query("UPDATE parking_slots SET status = ?, updated_at = NOW() WHERE id = ?", [targetStatus, slotId]);
        req.flash('success', `Slot ${slot.slot_number} status updated to ${targetStatus}.`);
        res.redirect('/slots');
    } catch (err) {
        console.error('Toggle slot error:', err);
        req.flash('danger', 'Failed to update slot status.');
        res.redirect('/slots');
    }
});

module.exports = router;

