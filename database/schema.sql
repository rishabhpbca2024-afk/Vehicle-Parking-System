-- ==============================================================================
-- Vehicle Parking Management System - Database Schema
-- Compatible with MySQL 8.0+ and MariaDB 10.4+
-- Character Set: utf8mb4, Collation: utf8mb4_unicode_ci, Engine: InnoDB
-- ==============================================================================

SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

-- ------------------------------------------------------------------------------
-- Table: users
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `email` VARCHAR(150) NOT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `role` ENUM('ADMIN', 'OPERATOR') NOT NULL DEFAULT 'OPERATOR',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_user_email` (`email`),
    INDEX `idx_user_role_active` (`role`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- Table: parking_slots
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `parking_slots` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `slot_number` VARCHAR(20) NOT NULL,
    `floor_number` VARCHAR(20) NOT NULL,
    `vehicle_type` ENUM('TWO_WHEELER', 'CAR', 'SUV', 'HEAVY') NOT NULL,
    `status` ENUM('AVAILABLE', 'OCCUPIED', 'OUT_OF_SERVICE') NOT NULL DEFAULT 'AVAILABLE',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_floor_slot` (`floor_number`, `slot_number`),
    INDEX `idx_slot_status_type` (`status`, `vehicle_type`),
    INDEX `idx_slot_floor` (`floor_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- Table: vehicles
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `vehicles` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `registration_number` VARCHAR(30) NOT NULL,
    `vehicle_type` ENUM('TWO_WHEELER', 'CAR', 'SUV', 'HEAVY') NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_vehicle_reg` (`registration_number`),
    INDEX `idx_vehicle_type` (`vehicle_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- Table: parking_rates
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `parking_rates` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `vehicle_type` ENUM('TWO_WHEELER', 'CAR', 'SUV', 'HEAVY') NOT NULL,
    `hourly_rate` DECIMAL(10,2) NOT NULL,
    `minimum_charge_hours` INT NOT NULL DEFAULT 1,
    `billing_increment_minutes` INT NOT NULL DEFAULT 60,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_rate_vehicle_type` (`vehicle_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- Table: parking_sessions
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `parking_sessions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `vehicle_id` INT NOT NULL,
    `slot_id` INT NOT NULL,
    `created_by` INT NULL,
    `entry_time` DATETIME NOT NULL,
    `exit_time` DATETIME NULL,
    `rate_per_hour` DECIMAL(10,2) NOT NULL,
    `amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `status` ENUM('ACTIVE', 'COMPLETED') NOT NULL DEFAULT 'ACTIVE',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_session_vehicle` FOREIGN KEY (`vehicle_id`) 
        REFERENCES `vehicles` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_session_slot` FOREIGN KEY (`slot_id`) 
        REFERENCES `parking_slots` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_session_user` FOREIGN KEY (`created_by`) 
        REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX `idx_session_status_entry` (`status`, `entry_time`),
    INDEX `idx_session_exit` (`exit_time`),
    INDEX `idx_session_vehicle` (`vehicle_id`),
    INDEX `idx_session_slot` (`slot_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- Table: payments
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `payments` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `session_id` INT NOT NULL,
    `amount` DECIMAL(10,2) NOT NULL,
    `method` ENUM('CASH', 'UPI', 'CARD') NOT NULL DEFAULT 'CASH',
    `status` ENUM('PENDING', 'PAID', 'FAILED') NOT NULL DEFAULT 'PAID',
    `paid_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_payment_session` (`session_id`),
    CONSTRAINT `fk_payment_session` FOREIGN KEY (`session_id`) 
        REFERENCES `parking_sessions` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    INDEX `idx_payment_status_paid` (`status`, `paid_at`),
    INDEX `idx_payment_method` (`method`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- Initial Seed: Default Parking Rates (Idempotent INSERT IGNORE)
-- ------------------------------------------------------------------------------
INSERT IGNORE INTO `parking_rates` (`vehicle_type`, `hourly_rate`, `minimum_charge_hours`, `billing_increment_minutes`, `is_active`)
VALUES
    ('TWO_WHEELER', 10.00, 1, 60, 1),
    ('CAR', 30.00, 1, 60, 1),
    ('SUV', 40.00, 1, 60, 1),
    ('HEAVY', 60.00, 1, 60, 1);

-- ------------------------------------------------------------------------------
-- Sample Initial Parking Slots (Idempotent INSERT IGNORE)
-- ------------------------------------------------------------------------------
INSERT IGNORE INTO `parking_slots` (`floor_number`, `slot_number`, `vehicle_type`, `status`)
VALUES
    -- Ground Floor (Two Wheelers & Cars)
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
    -- Floor 1 (Cars & SUVs)
    ('1st Floor', 'F1-01', 'CAR', 'AVAILABLE'),
    ('1st Floor', 'F1-02', 'CAR', 'AVAILABLE'),
    ('1st Floor', 'F1-03', 'CAR', 'AVAILABLE'),
    ('1st Floor', 'SUV-01', 'SUV', 'AVAILABLE'),
    ('1st Floor', 'SUV-02', 'SUV', 'AVAILABLE'),
    -- Basement (Heavy & SUVs)
    ('Basement', 'HV-01', 'HEAVY', 'AVAILABLE'),
    ('Basement', 'HV-02', 'HEAVY', 'AVAILABLE'),
    ('Basement', 'B1-01', 'SUV', 'AVAILABLE');

SET FOREIGN_KEY_CHECKS = 1;
