-- ============================================================================
-- MOA Hotel WiFi Management System - Migration Script
-- ============================================================================
-- Run this script to update existing tables to use composite keys
-- This ensures guest isolation (new guest = fresh device count & preferences)
-- ============================================================================

-- ============================================================================
-- STEP 1: Update authorized_devices table
-- ============================================================================
-- Remove old unique index on device_mac only
-- Add new composite unique index on (room_number, surname, device_mac)
-- ============================================================================

-- Check if old index exists and drop it
SET @exist := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
               WHERE table_schema = DATABASE() 
               AND table_name = 'authorized_devices' 
               AND index_name = 'idx_device_mac');

SET @sqlstmt := IF(@exist > 0, 
    'ALTER TABLE authorized_devices DROP INDEX idx_device_mac', 
    'SELECT "Index idx_device_mac does not exist"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Also check for 'device_mac' as the index name (MySQL auto-naming)
SET @exist := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
               WHERE table_schema = DATABASE() 
               AND table_name = 'authorized_devices' 
               AND index_name = 'device_mac');

SET @sqlstmt := IF(@exist > 0, 
    'ALTER TABLE authorized_devices DROP INDEX device_mac', 
    'SELECT "Index device_mac does not exist"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add new composite unique index (if it doesn't exist)
SET @exist := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
               WHERE table_schema = DATABASE() 
               AND table_name = 'authorized_devices' 
               AND index_name = 'idx_room_guest_device');

SET @sqlstmt := IF(@exist = 0, 
    'ALTER TABLE authorized_devices ADD UNIQUE KEY idx_room_guest_device (room_number, surname, device_mac)', 
    'SELECT "Index idx_room_guest_device already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add index on room_number + surname for faster lookups
SET @exist := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
               WHERE table_schema = DATABASE() 
               AND table_name = 'authorized_devices' 
               AND index_name = 'idx_room_surname');

SET @sqlstmt := IF(@exist = 0, 
    'ALTER TABLE authorized_devices ADD KEY idx_room_surname (room_number, surname)', 
    'SELECT "Index idx_room_surname already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================================
-- STEP 2: Update rooms_to_skip table
-- ============================================================================
-- Change unique key from room_number only to (room_number, guest_surname)
-- This allows different guests to have different preferences
-- ============================================================================

-- Drop old unique index on room_number only
SET @exist := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
               WHERE table_schema = DATABASE() 
               AND table_name = 'rooms_to_skip' 
               AND index_name = 'idx_room_number');

SET @sqlstmt := IF(@exist > 0, 
    'ALTER TABLE rooms_to_skip DROP INDEX idx_room_number', 
    'SELECT "Index idx_room_number does not exist"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add new composite unique index
SET @exist := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
               WHERE table_schema = DATABASE() 
               AND table_name = 'rooms_to_skip' 
               AND index_name = 'idx_room_guest');

SET @sqlstmt := IF(@exist = 0, 
    'ALTER TABLE rooms_to_skip ADD UNIQUE KEY idx_room_guest (room_number, guest_surname)', 
    'SELECT "Index idx_room_guest already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================================
-- STEP 3: Update the view
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
-- VERIFICATION
-- ============================================================================
SELECT 'Migration completed successfully!' as status;

-- Show current indexes on authorized_devices
SELECT 'authorized_devices indexes:' as info;
SHOW INDEX FROM authorized_devices;

-- Show current indexes on rooms_to_skip  
SELECT 'rooms_to_skip indexes:' as info;
SHOW INDEX FROM rooms_to_skip;
