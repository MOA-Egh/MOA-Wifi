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
    speed INTEGER DEFAULT 20,
    last_update TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_room_number (room_number),
    INDEX idx_device_mac (device_mac),
    INDEX idx_speed (speed)
);


CREATE TABLE IF NOT EXISTS rooms_to_skip (
    id INT AUTO_INCREMENT PRIMARY KEY,
    room_number VARCHAR(10) NOT NULL UNIQUE,
    guest_surname VARCHAR(100) NOT NULL,
    skip_clean BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_room_number (room_number)
);

-- View to get room statistics
CREATE OR REPLACE VIEW room_device_count AS
SELECT 
    room_number,
    speed,
    COUNT(*) as device_count,
    MAX(last_update) as last_activity
FROM authorized_devices 
GROUP BY room_number, speed;


COMMIT;