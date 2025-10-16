-- MOA Hotel WiFi Management System Database Setup
-- This script creates the necessary tables for managing device authentication and room cleaning preferences

-- Create database (uncomment if needed)
-- CREATE DATABASE moa_wifi_management;
-- USE moa_wifi_management;

-- Table to store authorized devices
CREATE TABLE IF NOT EXISTS authorized_devices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    device_mac VARCHAR(17) NOT NULL UNIQUE,
    room_number VARCHAR(10) NOT NULL,
    surname VARCHAR(100) NOT NULL,
    fast_mode BOOLEAN DEFAULT FALSE,
    last_update TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_room_number (room_number),
    INDEX idx_device_mac (device_mac),
    INDEX idx_fast_mode (fast_mode)
);

-- Table to store room cleaning skip preferences
CREATE TABLE IF NOT EXISTS rooms_to_skip (
    id INT AUTO_INCREMENT PRIMARY KEY,
    room_number VARCHAR(10) NOT NULL UNIQUE,
    skip_clean BOOLEAN DEFAULT FALSE,
    guest_surname VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_room_number (room_number)
);

-- View to get room statistics
CREATE OR REPLACE VIEW room_device_count AS
SELECT 
    room_number,
    COUNT(*) as total_devices,
    SUM(CASE WHEN fast_mode = TRUE THEN 1 ELSE 0 END) as fast_devices,
    MAX(last_update) as last_activity
FROM authorized_devices 
GROUP BY room_number;

-- Note: Reservations are now handled via PMS API integration
-- No local reservations table needed

COMMIT;