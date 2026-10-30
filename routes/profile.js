const express = require('express');
const router = express.Router();
const bcrypt = require('bcryptjs');
const pool = require('../config/db');
const { requireAuth } = require('../middleware/auth');

// GET /profile
router.get('/', requireAuth, async (req, res) => {
    try {
        const [rows] = await pool.query("SELECT id, name, email, role, created_at FROM users WHERE id = ? LIMIT 1", [req.session.user.id]);
        if (rows.length === 0) {
            req.flash('danger', 'User profile not found.');
            return res.redirect('/dashboard');
        }

        res.render('profile', {
            pageTitle: 'My Profile & Account Security',
            activeNav: 'profile',
            user: rows[0]
        });
    } catch (err) {
        console.error('Profile error:', err);
        req.flash('danger', 'Unable to retrieve profile.');
        res.redirect('/dashboard');
    }
});

// POST /profile
router.post('/', requireAuth, async (req, res) => {
    // Cyber Security Defense: Prevent demo users from altering admin credentials or locking the account
    if (req.session.user && req.session.user.isDemo) {
        req.flash('danger', '🔒 Security Protection: Modifying profile credentials or changing system passwords is disabled in Recruiter Demo Mode.');
        return res.redirect('/profile');
    }

    const userId = req.session.user.id;
    const name = String(req.body.name || '').trim();
    const currentPassword = req.body.current_password || '';
    const newPassword = req.body.new_password || '';
    const confirmPassword = req.body.confirm_password || '';

    if (!name) {
        req.flash('danger', 'Display name cannot be empty.');
        return res.redirect('/profile');
    }

    try {
        const [rows] = await pool.query("SELECT * FROM users WHERE id = ? LIMIT 1", [userId]);
        const user = rows[0];

        // Update name
        await pool.query("UPDATE users SET name = ?, updated_at = NOW() WHERE id = ?", [name, userId]);
        req.session.user.name = name;

        // If password change is requested
        if (newPassword) {
            if (!currentPassword) {
                req.flash('danger', 'Please enter your current password to verify identity.');
                return res.redirect('/profile');
            }
            if (newPassword.length < 6) {
                req.flash('danger', 'New password must be at least 6 characters.');
                return res.redirect('/profile');
            }
            if (newPassword !== confirmPassword) {
                req.flash('danger', 'New passwords do not match.');
                return res.redirect('/profile');
            }

            let hash = user.password_hash;
            if (hash.startsWith('$2y$')) {
                hash = hash.replace(/^\$2y\$/, '$2a$');
            }
            if (!bcrypt.compareSync(currentPassword, hash)) {
                req.flash('danger', 'Current password is incorrect.');
                return res.redirect('/profile');
            }

            const newHash = bcrypt.hashSync(newPassword, 10);
            await pool.query("UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?", [newHash, userId]);
            req.flash('success', 'Profile and password updated successfully.');
            return res.redirect('/profile');
        }

        req.flash('success', 'Profile updated successfully.');
        res.redirect('/profile');
    } catch (err) {
        console.error('Profile update error:', err);
        req.flash('danger', 'Failed to update profile.');
        res.redirect('/profile');
    }
});

module.exports = router;

