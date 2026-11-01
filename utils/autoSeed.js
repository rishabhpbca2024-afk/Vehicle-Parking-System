const pool = require('../config/db');

async function autoSeedDatabase() {
    try {
        // 1. Ensure users table has default Admin and Operator
        const [users] = await pool.query("SELECT COUNT(*) as count FROM users WHERE role = 'ADMIN'");
        if (users[0].count === 0) {
            console.log('🌱 Seeding default Admin and Operator accounts into database...');
            await pool.query(`
                INSERT IGNORE INTO users (id, name, email, password_hash, role, is_active, created_at, updated_at)
                VALUES 
                (1, 'Rishabh Pal', 'rishabh@gmail.com', '$2b$10$K2lpQPuT98ke6VujZJaABOomKdRLFs98QKCK1wd.xdiw8UwTj7UaC', 'ADMIN', 1, NOW(), NOW()),
                (2, 'Muskan Sharma', 'muskan@gmail.com', '$2b$10$d.IK6KZsn2iA9NyZHTqaUuspNzBYFWicVXiBTXCuHgvQZyT6uE0i.', 'OPERATOR', 1, NOW(), NOW())
            `);
            console.log('✅ Default users seeded successfully!');
        }

        // 2. Ensure parking_rates has initial rates
        const [rates] = await pool.query("SELECT COUNT(*) as count FROM parking_rates");
        if (rates[0].count === 0) {
            console.log('🌱 Seeding initial parking rates...');
            await pool.query(`
                INSERT IGNORE INTO parking_rates (vehicle_type, hourly_rate, minimum_charge_hours, billing_increment_minutes, is_active)
                VALUES
                ('TWO_WHEELER', 10.00, 1, 60, 1),
                ('CAR', 30.00, 1, 60, 1),
                ('SUV', 40.00, 1, 60, 1),
                ('HEAVY', 60.00, 1, 60, 1)
            `);
        }

        // 3. Ensure parking_slots has initial bays
        const [slots] = await pool.query("SELECT COUNT(*) as count FROM parking_slots");
        if (slots[0].count === 0) {
            console.log('🌱 Seeding initial parking slots...');
            await pool.query(`
                INSERT IGNORE INTO parking_slots (floor_number, slot_number, vehicle_type, status)
                VALUES
                ('Ground', 'G-01', 'CAR', 'AVAILABLE'),
                ('Ground', 'G-02', 'CAR', 'AVAILABLE'),
                ('Ground', 'G-03', 'CAR', 'AVAILABLE'),
                ('Ground', 'G-04', 'CAR', 'AVAILABLE'),
                ('Ground', 'G-05', 'CAR', 'AVAILABLE'),
                ('Ground', 'TW-01', 'TWO_WHEELER', 'AVAILABLE'),
                ('Ground', 'TW-02', 'TWO_WHEELER', 'AVAILABLE'),
                ('Ground', 'TW-03', 'TWO_WHEELER', 'AVAILABLE'),
                ('Ground', 'TW-04', 'TWO_WHEELER', 'AVAILABLE'),
                ('Ground', 'TW-05', 'TWO_WHEELER', 'AVAILABLE'),
                ('1st Floor', 'F1-01', 'CAR', 'AVAILABLE'),
                ('1st Floor', 'F1-02', 'CAR', 'AVAILABLE'),
                ('1st Floor', 'F1-03', 'CAR', 'AVAILABLE'),
                ('1st Floor', 'SUV-01', 'SUV', 'AVAILABLE'),
                ('1st Floor', 'SUV-02', 'SUV', 'AVAILABLE'),
                ('Basement', 'HV-01', 'HEAVY', 'AVAILABLE'),
                ('Basement', 'HV-02', 'HEAVY', 'AVAILABLE'),
                ('Basement', 'B1-01', 'SUV', 'AVAILABLE')
            `);
        }
    } catch (err) {
        console.warn('⚠️ Auto-seed notice:', err.message);
    }
}

module.exports = { autoSeedDatabase };
