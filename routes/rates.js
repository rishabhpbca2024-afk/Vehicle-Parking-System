const express = require('express');
const router = express.Router();
const pool = require('../config/db');
const { requireAdmin } = require('../middleware/auth');
const { formatCurrency, getVehicleTypes } = require('../utils/helpers');

// GET /rates
router.get('/', requireAdmin, async (req, res) => {
    try {
        const [rates] = await pool.query("SELECT * FROM parking_rates ORDER BY id ASC");
        const vehicleTypesMap = getVehicleTypes();

        res.render('rates/index', {
            pageTitle: 'Parking Rates & Tariffs Configuration',
            activeNav: 'rates',
            rates,
            vehicleTypesMap,
            formatCurrency
        });
    } catch (err) {
        console.error('Rates error:', err);
        req.flash('danger', 'Unable to retrieve parking rates.');
        res.redirect('/dashboard');
    }
});

// POST /rates/update
router.post('/update', requireAdmin, async (req, res) => {
    const rateId = parseInt(req.body.rate_id, 10);
    const hourlyRate = parseFloat(req.body.hourly_rate) || 0;
    const minHours = Math.max(1, parseInt(req.body.minimum_charge_hours, 10) || 1);
    const incrementMins = Math.max(15, parseInt(req.body.billing_increment_minutes, 10) || 60);
    const isActive = req.body.is_active ? 1 : 0;

    if (!rateId || hourlyRate <= 0) {
        req.flash('danger', 'Hourly rate must be greater than zero.');
        return res.redirect('/rates');
    }

    try {
        await pool.query(`
            UPDATE parking_rates 
            SET hourly_rate = ?, minimum_charge_hours = ?, billing_increment_minutes = ?, is_active = ?, updated_at = NOW()
            WHERE id = ?
        `, [hourlyRate, minHours, incrementMins, isActive, rateId]);

        req.flash('success', 'Parking rate and billing policy updated successfully.');
        res.redirect('/rates');
    } catch (err) {
        console.error('Update rate error:', err);
        req.flash('danger', 'Database error while updating parking rate.');
        res.redirect('/rates');
    }
});

module.exports = router;

