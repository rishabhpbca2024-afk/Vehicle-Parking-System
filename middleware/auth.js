/**
 * Authentication & Authorization Middlewares
 */

function attachUser(req, res, next) {
    res.locals.currentUser = req.session.user || null;
    res.locals.appName = process.env.APP_NAME || 'SmartPark - Parking Management System';
    res.locals.currentPath = req.path;
    res.locals.flash = {
        success: req.flash('success'),
        danger: req.flash('danger'),
        warning: req.flash('warning'),
        info: req.flash('info')
    };
    next();
}

function requireAuth(req, res, next) {
    if (!req.session.user) {
        req.flash('warning', 'Please sign in to access the system.');
        return res.redirect('/login');
    }
    next();
}

function requireAdmin(req, res, next) {
    if (!req.session.user) {
        req.flash('warning', 'Please sign in.');
        return res.redirect('/login');
    }
    if (req.session.user.role !== 'ADMIN') {
        req.flash('danger', 'Access denied. Administrator privileges are required.');
        return res.redirect('/dashboard');
    }
    next();
}

module.exports = {
    attachUser,
    requireAuth,
    requireAdmin
};

