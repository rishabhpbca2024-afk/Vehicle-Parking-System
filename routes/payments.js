const express = require('express');
const router = express.Router();
const fs = require('fs');
const path = require('path');
const pool = require('../config/db');
const { requireAuth } = require('../middleware/auth');
const { 
    formatCurrency, 
    formatDateTime, 
    calculateParkingDuration, 
    generateTicketNumber, 
    generateReceiptNumber, 
    getVehicleTypes, 
    getPaymentMethods 
} = require('../utils/helpers');

// GET /payments
router.get('/', requireAuth, async (req, res) => {
    try {
        const search = String(req.query.search || '').trim();
        const methodFilter = String(req.query.method || '').trim();
        const paymentMethods = getPaymentMethods();
        const vehicleTypesMap = getVehicleTypes();

        let query = `
            SELECT 
                p.id as payment_id,
                p.session_id,
                p.amount,
                p.method,
                p.status,
                p.paid_at,
                v.registration_number,
                v.vehicle_type,
                s.slot_number,
                s.floor_number,
                u.name as operator_name
            FROM payments p
            JOIN parking_sessions ps ON p.session_id = ps.id
            JOIN vehicles v ON ps.vehicle_id = v.id
            JOIN parking_slots s ON ps.slot_id = s.id
            LEFT JOIN users u ON ps.created_by = u.id
            WHERE 1=1
        `;
        const params = [];

        if (search) {
            query += ` AND (v.registration_number LIKE ? OR p.id = ?)`;
            params.push(`%${search}%`, search);
        }

        if (methodFilter && paymentMethods[methodFilter]) {
            query += ` AND p.method = ?`;
            params.push(methodFilter);
        }

        query += ` ORDER BY p.paid_at DESC LIMIT 100`;

        const [payments] = await pool.query(query, params);

        res.render('payments/index', {
            pageTitle: 'Payments & Transactions Log',
            activeNav: 'payments',
            payments,
            paymentMethods,
            vehicleTypesMap,
            search,
            methodFilter,
            formatCurrency,
            formatDateTime,
            generateTicketNumber,
            generateReceiptNumber
        });
    } catch (err) {
        console.error('Payments list error:', err);
        req.flash('danger', 'Unable to retrieve payments log.');
        res.redirect('/dashboard');
    }
});

// GET /payments/receipt/:id (Receipt for session_id)
router.get('/receipt/:id', requireAuth, async (req, res) => {
    const sessionId = parseInt(req.params.id, 10);
    if (!sessionId) return res.redirect('/vehicles/history');

    try {
        const [rows] = await pool.query(`
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
                p.status as payment_status,
                p.paid_at,
                u.name as operator_name,
                u.role as operator_role
            FROM parking_sessions ps
            JOIN vehicles v ON ps.vehicle_id = v.id
            JOIN parking_slots s ON ps.slot_id = s.id
            LEFT JOIN payments p ON ps.id = p.session_id
            LEFT JOIN users u ON ps.created_by = u.id
            WHERE ps.id = ?
            LIMIT 1
        `, [sessionId]);

        if (rows.length === 0) {
            req.flash('danger', 'Payment receipt record not found.');
            return res.redirect('/vehicles/history');
        }

        const receipt = rows[0];
        const duration = calculateParkingDuration(receipt.entry_time, receipt.exit_time);
        const vehicleTypesMap = getVehicleTypes();
        const paymentMethods = getPaymentMethods();

        const vMeta = vehicleTypesMap[receipt.vehicle_type] || { short: receipt.vehicle_type, label: receipt.vehicle_type };
        const mMeta = paymentMethods[receipt.payment_method] || { label: receipt.payment_method || 'Cash' };

        const roleRaw = String(receipt.operator_role || 'OPERATOR').toUpperCase();
        const roleLabel = (roleRaw === 'ADMIN') ? 'Admin' : ((roleRaw === 'OPERATOR') ? 'Operator' : roleRaw);
        const issuerName = receipt.operator_name || 'Counter';

        // Check if payment QR exists in public/assets/images
        let qrSrc = null;
        const qrExts = ['png', 'jpg', 'jpeg', 'webp'];
        for (const ext of qrExts) {
            const checkFile = path.join(__dirname, '..', 'assets', 'images', `payment_qr.${ext}`);
            if (fs.existsSync(checkFile)) {
                qrSrc = `/assets/images/payment_qr.${ext}`;
                break;
            }
        }

        res.render('payments/receipt', {
            pageTitle: `Payment Receipt — ${generateTicketNumber(receipt.session_id)}`,
            activeNav: 'payments',
            receipt,
            duration,
            vMeta,
            mMeta,
            roleLabel,
            issuerName,
            qrSrc,
            formatCurrency,
            formatDateTime,
            generateTicketNumber,
            generateReceiptNumber
        });
    } catch (err) {
        console.error('Receipt error:', err);
        req.flash('danger', 'Unable to generate receipt.');
        res.redirect('/vehicles/history');
    }
});

module.exports = router;
