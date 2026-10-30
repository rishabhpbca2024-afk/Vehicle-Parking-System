const express = require('express');
const router = express.Router();
const pool = require('../config/db');
const { requireAuth } = require('../middleware/auth');
const { 
    formatCurrency, 
    formatDateTime, 
    calculateParkingDuration, 
    generateTicketNumber, 
    getVehicleTypes, 
    getPaymentMethods 
} = require('../utils/helpers');

// GET /reports/daily
router.get('/daily', requireAuth, async (req, res) => {
    try {
        const todayStr = new Date().toISOString().split('T')[0];
        let selectedDate = String(req.query.report_date || todayStr).trim();
        if (!/^\d{4}-\d{2}-\d{2}$/.test(selectedDate)) {
            selectedDate = todayStr;
        }

        // 1. Daily Core Metrics
        const [[{ dailyEntries }]] = await pool.query("SELECT COUNT(*) as dailyEntries FROM parking_sessions WHERE DATE(entry_time) = ?", [selectedDate]);
        const [[{ dailyExits }]] = await pool.query("SELECT COUNT(*) as dailyExits FROM parking_sessions WHERE status = 'COMPLETED' AND DATE(exit_time) = ?", [selectedDate]);
        const [[{ dailyRevenue }]] = await pool.query("SELECT COALESCE(SUM(amount), 0) as dailyRevenue FROM payments WHERE status = 'PAID' AND DATE(paid_at) = ?", [selectedDate]);

        // 2. Breakdown by Vehicle Type
        const [typeBreakdown] = await pool.query(`
            SELECT 
                v.vehicle_type,
                COUNT(ps.id) as sessions_count,
                COALESCE(SUM(p.amount), 0) as total_rev
            FROM parking_sessions ps
            JOIN vehicles v ON ps.vehicle_id = v.id
            LEFT JOIN payments p ON ps.id = p.session_id AND p.status = 'PAID'
            WHERE DATE(ps.entry_time) = ?
            GROUP BY v.vehicle_type
        `, [selectedDate]);

        // 3. Breakdown by Payment Method
        const [methodBreakdown] = await pool.query(`
            SELECT 
                method,
                COUNT(*) as tx_count,
                COALESCE(SUM(amount), 0) as total_amount
            FROM payments
            WHERE status = 'PAID' AND DATE(paid_at) = ?
            GROUP BY method
        `, [selectedDate]);

        // 4. Session Transactions for the day
        const [daySessions] = await pool.query(`
            SELECT 
                ps.id,
                ps.entry_time,
                ps.exit_time,
                ps.amount,
                ps.status,
                v.registration_number,
                v.vehicle_type,
                s.slot_number,
                p.method as payment_method,
                u.name as operator_name
            FROM parking_sessions ps
            JOIN vehicles v ON ps.vehicle_id = v.id
            JOIN parking_slots s ON ps.slot_id = s.id
            LEFT JOIN payments p ON ps.id = p.session_id
            LEFT JOIN users u ON ps.created_by = u.id
            WHERE DATE(ps.entry_time) = ? OR DATE(ps.exit_time) = ?
            ORDER BY ps.id DESC
        `, [selectedDate, selectedDate]);

        const vehicleTypesMap = getVehicleTypes();
        const paymentMethods = getPaymentMethods();

        res.render('reports/daily', {
            pageTitle: `Daily Report — ${selectedDate}`,
            activeNav: 'reports_daily',
            selectedDate,
            dailyEntries: parseInt(dailyEntries, 10),
            dailyExits: parseInt(dailyExits, 10),
            dailyRevenue: parseFloat(dailyRevenue || 0),
            typeBreakdown,
            methodBreakdown,
            daySessions,
            vehicleTypesMap,
            paymentMethods,
            formatCurrency,
            formatDateTime,
            calculateParkingDuration,
            generateTicketNumber
        });
    } catch (err) {
        console.error('Daily report error:', err);
        req.flash('danger', 'Unable to generate daily report.');
        res.redirect('/dashboard');
    }
});

// GET /reports/monthly
router.get('/monthly', requireAuth, async (req, res) => {
    try {
        const now = new Date();
        const selectedMonth = parseInt(req.query.month, 10) || (now.getMonth() + 1);
        const selectedYear = parseInt(req.query.year, 10) || now.getFullYear();

        const yearMonthStr = `${selectedYear}-${String(selectedMonth).padStart(2, '0')}`;
        const daysInMonth = new Date(selectedYear, selectedMonth, 0).getDate();

        // 1. Monthly Revenue
        const [[{ monthlyRevenue }]] = await pool.query(`
            SELECT COALESCE(SUM(amount), 0) as monthlyRevenue
            FROM payments
            WHERE status = 'PAID' AND DATE_FORMAT(paid_at, '%Y-%m') = ?
        `, [yearMonthStr]);

        // 2. Monthly Entries
        const [[{ monthlyEntries }]] = await pool.query(`
            SELECT COUNT(*) as monthlyEntries
            FROM parking_sessions
            WHERE DATE_FORMAT(entry_time, '%Y-%m') = ?
        `, [yearMonthStr]);

        // 3. Monthly Exits
        const [[{ monthlyExits }]] = await pool.query(`
            SELECT COUNT(*) as monthlyExits
            FROM parking_sessions
            WHERE status = 'COMPLETED' AND DATE_FORMAT(exit_time, '%Y-%m') = ?
        `, [yearMonthStr]);

        // 4. Day-by-Day Aggregates
        const [dailyEntriesRows] = await pool.query(`
            SELECT DATE_FORMAT(entry_time, '%Y-%m-%d') as dt, COUNT(*) as cnt 
            FROM parking_sessions 
            WHERE DATE_FORMAT(entry_time, '%Y-%m') = ? 
            GROUP BY DATE_FORMAT(entry_time, '%Y-%m-%d')
        `, [yearMonthStr]);
        const entriesByDay = {};
        dailyEntriesRows.forEach(r => { entriesByDay[r.dt] = parseInt(r.cnt, 10); });

        const [dailyRevRows] = await pool.query(`
            SELECT DATE_FORMAT(paid_at, '%Y-%m-%d') as dt, COALESCE(SUM(amount), 0) as rev 
            FROM payments 
            WHERE status = 'PAID' AND DATE_FORMAT(paid_at, '%Y-%m') = ? 
            GROUP BY DATE_FORMAT(paid_at, '%Y-%m-%d')
        `, [yearMonthStr]);
        const revByDay = {};
        dailyRevRows.forEach(r => { revByDay[r.dt] = parseFloat(r.rev); });

        const daysMatrix = [];
        const chartLabels = [];
        const chartRevData = [];
        for (let d = 1; d <= daysInMonth; d++) {
            const dtStr = `${yearMonthStr}-${String(d).padStart(2, '0')}`;
            const entriesCount = entriesByDay[dtStr] || 0;
            const dayRev = revByDay[dtStr] || 0.0;
            daysMatrix.push({ day: d, dateStr: dtStr, entries: entriesCount, revenue: dayRev });
            chartLabels.push(`Day ${d}`);
            chartRevData.push(dayRev);
        }

        res.render('reports/monthly', {
            pageTitle: `Monthly Financial Report — ${yearMonthStr}`,
            activeNav: 'reports_monthly',
            selectedMonth,
            selectedYear,
            yearMonthStr,
            monthlyRevenue: parseFloat(monthlyRevenue || 0),
            monthlyEntries: parseInt(monthlyEntries, 10),
            monthlyExits: parseInt(monthlyExits, 10),
            daysMatrix,
            chartLabels,
            chartRevData,
            formatCurrency
        });
    } catch (err) {
        console.error('Monthly report error:', err);
        req.flash('danger', 'Unable to generate monthly report.');
        res.redirect('/dashboard');
    }
});

module.exports = router;

