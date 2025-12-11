-- ============================================================================
-- MIGRATION: Add Reservation Caching Tables
-- ============================================================================
-- Run this script to add caching tables to an existing MOA-WiFi installation
-- This reduces Mews API calls by caching reservation data
-- ============================================================================

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
-- VERIFICATION
-- ============================================================================
SELECT 'Migration complete!' as status;
SELECT * FROM cache_statistics;

-- ============================================================================
-- NEXT STEPS:
-- 1. Run this script in phpMyAdmin
-- 2. Enable MySQL event scheduler if not already enabled:
--    SET GLOBAL event_scheduler = ON;
--    
--    To make it permanent, add to my.cnf/my.ini:
--    [mysqld]
--    event_scheduler = ON
-- ============================================================================
