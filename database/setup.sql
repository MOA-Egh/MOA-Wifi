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
--   device_mac  = MAC address of the device
--   room_number = Room number (matches rooms.name)
--   surname     = Guest surname used for authentication
--   speed       = Speed in Mbps (10 = normal, 20 = fast/skip cleaning)
--
-- Note: Composite unique key on (room_number, surname, device_mac) ensures
-- each guest's devices are tracked separately. When a new guest checks in
-- with a different surname, their devices are tracked independently.
-- ============================================================================
DROP TABLE IF EXISTS authorized_devices;
CREATE TABLE authorized_devices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    device_mac VARCHAR(17) NOT NULL,      -- MAC address (format: XX:XX:XX:XX:XX:XX)
    room_number VARCHAR(10) NOT NULL,     -- Room number
    surname VARCHAR(100) NOT NULL,        -- Guest surname
    speed INT DEFAULT 10,                 -- Speed in Mbps (10 or 20)
    last_update TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY idx_room_guest_device (room_number, surname, device_mac),
    KEY idx_device_mac (device_mac),
    KEY idx_room_surname (room_number, surname),
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
    surname,
    speed,
    COUNT(*) as device_count,
    MAX(last_update) as last_activity
FROM authorized_devices 
GROUP BY room_number, surname, speed;

-- ============================================================================
-- VERIFICATION QUERIES (run these to check setup)
-- ============================================================================
-- SELECT 'rooms' as table_name, COUNT(*) as row_count FROM rooms
-- UNION ALL
-- SELECT 'authorized_devices', COUNT(*) FROM authorized_devices
-- UNION ALL
-- SELECT 'rooms_to_skip', COUNT(*) FROM rooms_to_skip;

-- ============================================================================
-- NEXT STEPS:
-- 1. Run this script in phpMyAdmin
-- 2. Access http://localhost/MOA-Wifi/public/sync_rooms.php to populate rooms
-- 3. Test with http://localhost/MOA-Wifi/public/test_mews.php
-- ============================================================================