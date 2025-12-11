-- ============================================================================
-- MOA Hotel WiFi Management System - Database Setup
-- ============================================================================
-- Run this script in phpMyAdmin or MySQL client to set up the database
-- ============================================================================

-- Create database (uncomment if needed)
-- CREATE DATABASE IF NOT EXISTS moa_wifi_management;
-- USE moa_wifi_management;

-- ============================================================================
-- TABLE 1: rooms
-- ============================================================================
-- Maps room numbers (names) to Mews Resource IDs
-- This table is populated by sync_rooms.php from Mews API
-- 
-- Columns:
--   id   = Mews ResourceId (GUID) - used to filter reservations by room
--   name = Room number displayed to guests (e.g., '101', '202')
-- ============================================================================
DROP TABLE IF EXISTS rooms;
CREATE TABLE rooms (
    id VARCHAR(36) NOT NULL,              -- Mews ResourceId (GUID)
    name VARCHAR(10) NOT NULL,            -- Room number (e.g., '101', '202')
    PRIMARY KEY (id),
    UNIQUE KEY idx_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- TABLE 2: authorized_devices
-- ============================================================================
-- Tracks devices that have authenticated via the WiFi portal
-- Used for admin reporting and device management
--
-- Columns:
--   mac_address = MAC address of the device
--   room_number = Room number (matches rooms.name)
--   last_name   = Guest surname used for authentication
--   speed       = Speed in Mbps (10 = normal, 20 = fast/skip cleaning)
--
-- Note: Composite unique key on (room_number, last_name, mac_address) ensures
-- each guest's devices are tracked separately. When a new guest checks in
-- with a different surname, their devices are tracked independently.
-- ============================================================================
DROP TABLE IF EXISTS authorized_devices;
CREATE TABLE authorized_devices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    room_number VARCHAR(10) NOT NULL,     -- Room number
    last_name VARCHAR(100) NOT NULL,      -- Guest surname
    mac_address VARCHAR(17) NOT NULL,     -- MAC address (format: XX:XX:XX:XX:XX:XX)
    speed INT DEFAULT 20,                 -- Speed in Mbps (10 or 20)
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY idx_mac (mac_address),
    KEY idx_room_surname (room_number, last_name),
    KEY idx_speed (speed)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- TABLE 3: rooms_to_skip
-- ============================================================================
-- Tracks rooms where guests opted for "fast" speed (skip cleaning)
-- Used to generate cleaning skip reports for housekeeping
--
-- Columns:
--   room_number  = Room number
--   guest_surname = Guest surname
--   skip_clean   = TRUE if guest chose fast speed (skip cleaning)
--
-- Note: Composite unique key on (room_number, guest_surname) ensures each
-- guest's cleaning preference is tracked separately. A new guest with a 
-- different surname gets fresh preferences.
-- ============================================================================
DROP TABLE IF EXISTS rooms_to_skip;
CREATE TABLE rooms_to_skip (
    id INT AUTO_INCREMENT PRIMARY KEY,
    room_number VARCHAR(10) NOT NULL,     -- Room number
    guest_surname VARCHAR(100) NOT NULL,  -- Guest surname
    skip_clean BOOLEAN DEFAULT FALSE,     -- TRUE = skip cleaning
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY idx_room_guest (room_number, guest_surname)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- VIEW: room_device_count
-- ============================================================================
-- Provides room statistics for admin dashboard
-- Groups by room AND surname to show each guest's device count separately
-- ============================================================================
CREATE OR REPLACE VIEW room_device_count AS
SELECT 
    room_number,
    last_name as surname,
    speed,
    COUNT(*) as device_count,
    MAX(updated_at) as last_activity
FROM authorized_devices 
GROUP BY room_number, last_name, speed;

-- ============================================================================
-- VERIFICATION QUERIES (run these to check setup)
-- ============================================================================
-- SELECT 'rooms' as table_name, COUNT(*) as row_count FROM rooms
-- UNION ALL
-- SELECT 'authorized_devices', COUNT(*) FROM authorized_devices
-- UNION ALL
-- SELECT 'rooms_to_skip', COUNT(*) FROM rooms_to_skip;

-- ============================================================================
-- TABLE: cached_reservations
-- ============================================================================
-- Caches Mews reservation data to reduce API calls
-- Cache is valid until checkout date
-- Cleared daily at 3am
-- ============================================================================
CREATE TABLE IF NOT EXISTS cached_reservations (
    reservation_id VARCHAR(36) PRIMARY KEY, -- Mews Reservation GUID
    room_id VARCHAR(36) NOT NULL,           -- Mews Resource GUID
    room_number VARCHAR(10) NOT NULL,       -- Human-readable room number (from rooms table)
    surname VARCHAR(100) NOT NULL,          -- Guest surname (lowercase for matching)
    check_in DATE NOT NULL,
    check_out DATE NOT NULL,
    customer_id VARCHAR(36) NOT NULL,       -- Mews Customer GUID
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY idx_room_surname (room_id, surname),
    KEY idx_checkout (check_out),           -- For cleanup queries
    KEY idx_room_number (room_number)       -- For lookup by room number
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- TABLE: system_settings
-- ============================================================================
-- Stores system configuration values like last bulk fetch timestamp
-- ============================================================================
CREATE TABLE IF NOT EXISTS system_settings (
    setting_key VARCHAR(50) PRIMARY KEY,
    setting_value VARCHAR(255),
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Initialize last_bulk_fetch setting (set to epoch so first request triggers bulk fetch)
INSERT IGNORE INTO system_settings (setting_key, setting_value) 
VALUES ('last_bulk_fetch', '1970-01-01 00:00:00');

-- ============================================================================
-- EVENT: clear_reservation_cache
-- ============================================================================
-- Runs daily at 3am to clear expired reservations from cache
-- Requires EVENT scheduler to be enabled: SET GLOBAL event_scheduler = ON;
-- ============================================================================
DELIMITER //
CREATE EVENT IF NOT EXISTS clear_reservation_cache
ON SCHEDULE EVERY 1 DAY
STARTS (TIMESTAMP(CURRENT_DATE) + INTERVAL 3 HOUR)
DO
BEGIN
    -- Delete reservations where checkout has passed
    DELETE FROM cached_reservations WHERE check_out < CURRENT_DATE;
    
    -- Reset bulk fetch timestamp to force fresh data
    UPDATE system_settings 
    SET setting_value = '1970-01-01 00:00:00' 
    WHERE setting_key = 'last_bulk_fetch';
END//
DELIMITER ;

-- ============================================================================
-- VIEW: cache_statistics
-- ============================================================================
-- Provides cache statistics for admin dashboard
-- ============================================================================
CREATE OR REPLACE VIEW cache_statistics AS
SELECT 
    (SELECT COUNT(*) FROM cached_reservations) as total_cached,
    (SELECT COUNT(*) FROM cached_reservations WHERE check_out >= CURRENT_DATE) as active_cached,
    (SELECT setting_value FROM system_settings WHERE setting_key = 'last_bulk_fetch') as last_bulk_fetch;

-- ============================================================================
-- NEXT STEPS:
-- 1. Run this script in phpMyAdmin
-- 2. Enable MySQL event scheduler: SET GLOBAL event_scheduler = ON;
-- 3. Access http://localhost/MOA-Wifi/public/sync_rooms.php to populate rooms
-- 4. Test with http://localhost/MOA-Wifi/public/test_mews.php
-- ============================================================================