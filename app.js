require('dotenv').config();
const express = require('express');
const path = require('path');
const session = require('express-session');
const flash = require('connect-flash');
const morgan = require('morgan');

const { attachUser } = require('./middleware/auth');

// Route Handlers
const authRoutes = require('./routes/auth');
const dashboardRoutes = require('./routes/dashboard');
const vehicleRoutes = require('./routes/vehicles');
const slotRoutes = require('./routes/slots');
const rateRoutes = require('./routes/rates');
const reportRoutes = require('./routes/reports');
const paymentRoutes = require('./routes/payments');
const profileRoutes = require('./routes/profile');

const app = express();
const PORT = process.env.PORT || 8000;

// Logging & Body Parsers
if (process.env.NODE_ENV !== 'production') {
    app.use(morgan('dev'));
}
app.use(express.json());
app.use(express.urlencoded({ extended: true }));

// Static Assets
app.use('/assets', express.static(path.join(__dirname, 'assets')));

// View Engine Setup (EJS)
app.set('views', path.join(__dirname, 'views'));
app.set('view engine', 'ejs');

// Session Configuration
app.use(session({
    secret: process.env.SESSION_SECRET || 'smartpark_super_secret_2026',
    resave: false,
    saveUninitialized: false,
    cookie: {
        maxAge: 1000 * 60 * 60 * 24, // 24 hours
        httpOnly: true,
        sameSite: 'lax'
    }
}));

// Flash Messages
app.use(flash());

// Context Injection Middleware
app.use(attachUser);

// Mount Application Routes
app.use('/', authRoutes);
app.use('/', dashboardRoutes);
app.use('/vehicles', vehicleRoutes);
app.use('/slots', slotRoutes);
app.use('/rates', rateRoutes);
app.use('/reports', reportRoutes);
app.use('/payments', paymentRoutes);
app.use('/profile', profileRoutes);

// 404 Handler
app.use((req, res) => {
    res.status(404).render('login', {
        pageTitle: 'Page Not Found',
        email: ''
    });
});

// Error Handler
app.use((err, req, res, next) => {
    console.error('Unhandled Application Error:', err);
    res.status(500).send(`
        <div style="font-family: sans-serif; padding: 2rem; background: #0f172a; color: #f8fafc; min-height: 100vh;">
            <h2>Application Error</h2>
            <p>${err.message}</p>
            <a href="/dashboard" style="color: #6366f1;">Back to Dashboard</a>
        </div>
    `);
});

// Start Server
app.listen(PORT, '0.0.0.0', () => {
    console.log('======================================================================');
    console.log(`  SmartPark (Node.js & MySQL) is running at: http://127.0.0.1:${PORT}`);
    console.log('======================================================================');
});
