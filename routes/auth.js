const express = require('express');
const router = express.Router();
const bcrypt = require('bcryptjs');
const pool = require('../config/db');

// GET /login
router.get('/login', (req, res) => {
    if (req.session.user) {
        return res.redirect('/dashboard');
    }
    res.render('login', {
        pageTitle: 'Sign In — SmartPark',
        email: ''
    });
});

// POST /login
router.post('/login', async (req, res) => {
    const { email, password } = req.body;
    const cleanEmail = String(email || '').trim().toLowerCase();

    if (!cleanEmail || !password) {
        req.flash('danger', 'Please provide both email address and password.');
        return res.render('login', {
            pageTitle: 'Sign In — SmartPark',
            email: cleanEmail
        });
    }

    try {
        const [rows] = await pool.query('SELECT * FROM users WHERE email = ? LIMIT 1', [cleanEmail]);
        const user = rows[0];

        if (!user) {
            req.flash('danger', 'Invalid email address or password.');
            return res.render('login', {
                pageTitle: 'Sign In — SmartPark',
                email: cleanEmail
            });
        }

        if (!user.is_active) {
            req.flash('danger', 'Your account has been deactivated. Please contact an administrator.');
            return res.render('login', {
                pageTitle: 'Sign In — SmartPark',
                email: cleanEmail
            });
        }

        // Compare password (support both $2y$ and $2a$/$2b$ bcrypt formats)
        let hash = user.password_hash;
        if (hash.startsWith('$2y$')) {
            hash = hash.replace(/^\$2y\$/, '$2a$');
        }

        const isMatch = bcrypt.compareSync(password, hash);
        if (!isMatch) {
            req.flash('danger', 'Invalid email address or password.');
            return res.render('login', {
                pageTitle: 'Sign In — SmartPark',
                email: cleanEmail
            });
        }

        // Set session
        req.session.user = {
            id: user.id,
            name: user.name,
            email: user.email,
            role: user.role
        };

        req.flash('success', `Welcome back, ${user.name}!`);
        res.redirect('/dashboard');
    } catch (err) {
        console.error('Login error:', err);
        req.flash('danger', 'An unexpected error occurred during sign-in.');
        res.render('login', {
            pageTitle: 'Sign In — SmartPark',
            email: cleanEmail
        });
    }
});

// GET /demo-login/:role (Recruiter 1-Click Showcase Login)
router.get('/demo-login/:role', async (req, res) => {
    const role = String(req.params.role || '').toUpperCase();
    try {
        let targetRole = (role === 'OPERATOR') ? 'OPERATOR' : 'ADMIN';
        const [rows] = await pool.query('SELECT * FROM users WHERE role = ? AND is_active = 1 LIMIT 1', [targetRole]);
        const user = rows[0];

        if (user) {
            req.session.user = {
                id: user.id,
                name: user.name,
                email: user.email,
                role: user.role,
                isDemo: true
            };
            req.flash('success', `🚀 Demo Mode Active: Logged in as ${user.name} (${user.role}). You have full access to test all features!`);
            return res.redirect('/dashboard');
        } else {
            req.flash('danger', `No active demo user found for role ${role}.`);
            return res.redirect('/login');
        }
    } catch (err) {
        console.error('Demo login error:', err);
        req.flash('danger', 'Failed to initialize demo session.');
        res.redirect('/login');
    }
});

// GET /logout
router.get('/logout', (req, res) => {
    req.session.destroy(() => {
        res.redirect('/login');
    });
});

module.exports = router;
