const express = require('express');
const router = express.Router();
const pool = require('../config/db');
const { requireAuth } = require('../middleware/auth');
const { formatCurrency, formatDateTime, getVehicleTypes } = require('../utils/helpers');

router.get(['/', '/dashboard'], requireAuth, async (req, res) => {
    try {
        // 1. Parking Slots Metrics
        const [slotRows] = await pool.query(`
            SELECT 
                COUNT(*) as total_slots,
                COALESCE(SUM(CASE WHEN status = 'AVAILABLE' THEN 1 ELSE 0 END), 0) as available_slots,
                COALESCE(SUM(CASE WHEN status = 'OCCUPIED' THEN 1 ELSE 0 END), 0) as occupied_slots,
                COALESCE(SUM(CASE WHEN status = 'OUT_OF_SERVICE' THEN 1 ELSE 0 END), 0) as out_of_service_slots
            FROM parking_slots
        `);
        const slotStats = slotRows[0] || {};
        const totalSlots = parseInt(slotStats.total_slots || 0, 10);
        const availableSlots = parseInt(slotStats.available_slots || 0, 10);
        const occupiedSlots = parseInt(slotStats.occupied_slots || 0, 10);
        const outOfServiceSlots = parseInt(slotStats.out_of_service_slots || 0, 10);
        const occupancyRate = totalSlots > 0 ? Math.round((occupiedSlots / totalSlots) * 1000) / 10 : 0;

        // 2. Active Parked Vehicles
        const [[{ currentlyParked }]] = await pool.query("SELECT COUNT(*) as currentlyParked FROM parking_sessions WHERE status = 'ACTIVE'");

        // 3. Today's Entries
        const [[{ todayEntries }]] = await pool.query("SELECT COUNT(*) as todayEntries FROM parking_sessions WHERE DATE(entry_time) = CURDATE()");

        // 4. Today's Exits
        const [[{ todayExits }]] = await pool.query("SELECT COUNT(*) as todayExits FROM parking_sessions WHERE status = 'COMPLETED' AND DATE(exit_time) = CURDATE()");

        // 5. Today's Collected Revenue
        const [[{ todayRevenue }]] = await pool.query(`
            SELECT COALESCE(SUM(amount), 0) as todayRevenue 
            FROM payments 
            WHERE status = 'PAID' AND DATE(paid_at) = CURDATE()
        `);

        // 6. Occupancy by Vehicle Type
        const [typeOccupancyRows] = await pool.query(`
            SELECT 
                vehicle_type,
                COUNT(*) as total,
                COALESCE(SUM(CASE WHEN status = 'OCCUPIED' THEN 1 ELSE 0 END), 0) as occupied,
                COALESCE(SUM(CASE WHEN status = 'AVAILABLE' THEN 1 ELSE 0 END), 0) as available
            FROM parking_slots
            GROUP BY vehicle_type
        `);
        const vehicleTypesMap = getVehicleTypes();

        // 7. Last 7 Days Revenue Trend
        const [rev7Rows] = await pool.query(`
            SELECT 
                DATE_FORMAT(paid_at, '%Y-%m-%d') as pay_date,
                COALESCE(SUM(amount), 0) as daily_total
            FROM payments
            WHERE status = 'PAID' AND paid_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
            GROUP BY DATE_FORMAT(paid_at, '%Y-%m-%d')
            ORDER BY pay_date ASC
        `);
        const revMap = {};
        rev7Rows.forEach(r => { revMap[r.pay_date] = parseFloat(r.daily_total) || 0; });

        const chartLabels = [];
        const chartRevenueData = [];
        for (let i = 6; i >= 0; i--) {
            const d = new Date();
            d.setDate(d.getDate() - i);
            const dateKey = d.toISOString().split('T')[0];
            chartLabels.push(d.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' }));
            chartRevenueData.push(revMap[dateKey] || 0);
        }

        // 8. Vehicle Type Distribution
        const typeChartLabels = [];
        const typeChartData = [];
        for (const [vKey, vMeta] of Object.entries(vehicleTypesMap)) {
            const found = typeOccupancyRows.find(t => t.vehicle_type === vKey);
            typeChartLabels.push(vMeta.short);
            typeChartData.push(found ? parseInt(found.occupied, 10) : 0);
        }

        // 9. Recent Parking Activity
        const [recentActivities] = await pool.query(`
            SELECT 
                ps.id,
                ps.entry_time,
                ps.exit_time,
                ps.rate_per_hour,
                ps.amount,
                ps.status,
                v.registration_number,
                v.vehicle_type,
                s.slot_number,
                s.floor_number
            FROM parking_sessions ps
            JOIN vehicles v ON ps.vehicle_id = v.id
            JOIN parking_slots s ON ps.slot_id = s.id
            ORDER BY ps.id DESC
            LIMIT 8
        `);

        res.render('dashboard', {
            pageTitle: 'Dashboard Overview',
            activeNav: 'dashboard',
            totalSlots,
            availableSlots,
            occupiedSlots,
            outOfServiceSlots,
            occupancyRate,
            currentlyParked,
            todayEntries,
            todayExits,
            todayRevenue: parseFloat(todayRevenue || 0),
            typeOccupancyRows,
            vehicleTypesMap,
            chartLabels,
            chartRevenueData,
            typeChartLabels,
            typeChartData,
            recentActivities,
            formatCurrency,
            formatDateTime
        });
    } catch (err) {
        console.error('Dashboard error:', err);
        req.flash('danger', 'Failed to load dashboard metrics.');
        res.render('dashboard', {
            pageTitle: 'Dashboard Overview',
            activeNav: 'dashboard',
            totalSlots: 0,
            availableSlots: 0,
            occupiedSlots: 0,
            outOfServiceSlots: 0,
            occupancyRate: 0,
            currentlyParked: 0,
            todayEntries: 0,
            todayExits: 0,
            todayRevenue: 0,
            typeOccupancyRows: [],
            vehicleTypesMap: getVehicleTypes(),
            chartLabels: [],
            chartRevenueData: [],
            typeChartLabels: [],
            typeChartData: [],
            recentActivities: [],
            formatCurrency,
            formatDateTime
        });
    }
});

module.exports = router;
