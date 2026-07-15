-- ============================================================
-- Haven Hotel — Full Database Schema
-- Reconstructed from every uploaded PHP file (CREATE/ALTER TABLE
-- statements + INSERT column lists where no CREATE TABLE existed).
-- Run this in phpMyAdmin's SQL tab against your `haven_hotel` database.
-- ============================================================

CREATE DATABASE IF NOT EXISTS haven_hotel;
USE haven_hotel;

-- ------------------------------------------------------------
-- users
-- Base columns inferred from User.php (register/login) +
-- suspension_reason added via auth_ajax.php's self-heal.
-- role/status are ENUM-like strings, not real ENUMs, since the
-- PHP only ever compares them as plain strings ('admin', 'user',
-- 'Active', 'Suspended').
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    user_email VARCHAR(190) NOT NULL UNIQUE,
    phone VARCHAR(30) NULL,
    password VARCHAR(255) NOT NULL,
    role VARCHAR(20) NOT NULL DEFAULT 'user',
    status VARCHAR(20) NOT NULL DEFAULT 'Active',
    suspension_reason VARCHAR(255) NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ------------------------------------------------------------
-- rooms
-- Fixed 5-row catalog (one per floor/type), per room_inventory_
-- engine.php's HOTEL_FLOOR_CATALOG. Columns assembled from its
-- INSERT statement + the floor/floor_number ALTERs from book.php,
-- admin_dashboard.php, and room_inventory_engine.php.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS rooms (
    room_id INT AUTO_INCREMENT PRIMARY KEY,
    room_number VARCHAR(10) NOT NULL,
    floor VARCHAR(20) NOT NULL DEFAULT '1st Floor',
    floor_number TINYINT NULL,
    room_type VARCHAR(100) NOT NULL,
    price_per_night DECIMAL(10,2) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'Available',
    description TEXT NULL,
    image_url VARCHAR(500) NULL,
    total_inventory INT NOT NULL DEFAULT 0
);

-- ------------------------------------------------------------
-- room_units
-- One row per PHYSICAL room number under a `rooms` floor/type row.
-- Defined explicitly in room_inventory_engine.php.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS room_units (
    unit_id INT AUTO_INCREMENT PRIMARY KEY,
    room_id INT NOT NULL,
    unit_number VARCHAR(10) NOT NULL,
    unit_status VARCHAR(20) NOT NULL DEFAULT 'Active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_room_unit (room_id, unit_number),
    CONSTRAINT fk_room_units_room FOREIGN KEY (room_id) REFERENCES rooms(room_id)
);

-- ------------------------------------------------------------
-- bookings
-- Core columns from book.php's INSERT (the fullest of the two
-- INSERT statements found). Payment/refund columns appended via
-- payment_wallet_engine.php's ensure_payment_wallet_schema();
-- room_unit_id appended via room_inventory_engine.php.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS bookings (
    booking_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    room_id INT NOT NULL,
    room_unit_id INT NULL DEFAULT NULL,
    room_type VARCHAR(100) NOT NULL,
    check_in_date DATE NOT NULL,
    check_out_date DATE NOT NULL,
    total_price DECIMAL(10,2) NOT NULL,
    downpayment_amount DECIMAL(10,2) NULL DEFAULT NULL,
    downpayment_paid_at DATETIME NULL DEFAULT NULL,
    full_payment_paid_at DATETIME NULL DEFAULT NULL,
    payment_status VARCHAR(50) NOT NULL DEFAULT 'Awaiting Downpayment',
    refund_amount DECIMAL(10,2) NULL DEFAULT NULL,
    refunded_at DATETIME NULL DEFAULT NULL,
    auto_cancelled TINYINT(1) NOT NULL DEFAULT 0,
    booking_status VARCHAR(20) NOT NULL DEFAULT 'Confirmed',
    booking_reference VARCHAR(64) NOT NULL,
    special_requests TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_bookings_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_bookings_room FOREIGN KEY (room_id) REFERENCES rooms(room_id),
    CONSTRAINT fk_bookings_room_unit FOREIGN KEY (room_unit_id) REFERENCES room_units(unit_id)
);

-- ------------------------------------------------------------
-- cancellation_requests
-- Admin-review cancellation queue. Identical CREATE TABLE found
-- in dashboard.php (x2, duplicate but consistent), admin_dashboard.php.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS cancellation_requests (
    request_id INT AUTO_INCREMENT PRIMARY KEY,
    booking_id INT NOT NULL,
    user_id INT NOT NULL,
    booking_reference VARCHAR(64) NOT NULL,
    reason TEXT NULL,
    request_status VARCHAR(20) NOT NULL DEFAULT 'Pending',
    admin_note TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    resolved_at TIMESTAMP NULL DEFAULT NULL,
    CONSTRAINT fk_cancelreq_booking FOREIGN KEY (booking_id) REFERENCES bookings(booking_id),
    CONSTRAINT fk_cancelreq_user FOREIGN KEY (user_id) REFERENCES users(id)
);

-- ------------------------------------------------------------
-- support_requests
-- "Review my suspended account" queue. From auth_ajax.php / login.php.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS support_requests (
    support_request_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    user_email VARCHAR(190) NOT NULL,
    suspension_reason_snapshot VARCHAR(255) NULL,
    guest_message TEXT NULL,
    request_status VARCHAR(20) NOT NULL DEFAULT 'Pending',
    admin_note TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    resolved_at TIMESTAMP NULL DEFAULT NULL,
    CONSTRAINT fk_supportreq_user FOREIGN KEY (user_id) REFERENCES users(id)
);

-- ------------------------------------------------------------
-- password_resets
-- Token table for reset-password.php. From auth_ajax.php / login.php.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS password_resets (
    reset_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    user_email VARCHAR(190) NOT NULL,
    token VARCHAR(64) NOT NULL UNIQUE,
    used TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_pwreset_user FOREIGN KEY (user_id) REFERENCES users(id)
);

-- ------------------------------------------------------------
-- notifications
-- user_id NULL = broadcast to every guest (see notifications_ajax.php
-- comments). Referenced via SELECT/INSERT in notifications_ajax.php,
-- payment_wallet_engine.php.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    message TEXT NOT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id)
);

-- ------------------------------------------------------------
-- testimonials
-- Guest feedback feed. Referenced in testimonials_ajax.php.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS testimonials (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    rating INT NOT NULL,
    review_text TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_testimonials_user FOREIGN KEY (user_id) REFERENCES users(id)
);

-- ------------------------------------------------------------
-- user_wallets
-- One running balance per user. From payment_wallet_engine.php.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS user_wallets (
    user_id INT PRIMARY KEY,
    balance DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_wallets_user FOREIGN KEY (user_id) REFERENCES users(id)
);

-- ------------------------------------------------------------
-- wallet_transactions
-- Append-only ledger backing user_wallets.balance. From
-- payment_wallet_engine.php.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS wallet_transactions (
    transaction_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    booking_id INT NULL,
    booking_reference VARCHAR(64) NULL,
    transaction_type VARCHAR(30) NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    balance_after DECIMAL(10,2) NOT NULL,
    note VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_wallettx_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_wallettx_booking FOREIGN KEY (booking_id) REFERENCES bookings(booking_id)
);
