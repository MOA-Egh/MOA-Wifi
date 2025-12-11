<?php
/**
 * Mews WiFi Authentication Adapter
 * Adapts the Mews connector for WiFi authentication system
 * Includes reservation caching to reduce API calls
 */

require_once __DIR__ . '/MewsConnector.php';

class MewsWifiAuth {
    private $mews;
    private $environment;
    private $config;
    private $pdo;
    
    // Cache settings
    private const BULK_FETCH_INTERVAL = 3600; // 1 hour in seconds
    
    public function __construct($environment = 'demo', $configFile = null, $pdo = null) {
        $this->environment = $environment;
        $this->pdo = $pdo;
        
        // Load config
        $configPath = $configFile ?: __DIR__ . '/../config/mews_config.php';
        if (file_exists($configPath)) {
            $this->config = require $configPath;
            
            // Use environment from config if not specified
            if (!$environment && isset($this->config['mews']['environment'])) {
                $this->environment = $this->config['mews']['environment'];
            }
        }
        
        $this->mews = new MewsConnector($this->environment, $configPath);
    }
    
    /**
     * Set the PDO connection for caching
     * 
     * @param PDO $pdo Database connection
     */
    public function setDatabase($pdo) {
        $this->pdo = $pdo;
    }
    
    /**
     * Validate guest credentials against Mews PMS with caching
     * 
     * Flow:
     * 1. Check cache first
     * 2. If not in cache and bulk fetch < 1 hour ago, do individual API lookup
     * 3. If bulk fetch >= 1 hour ago, do bulk fetch, cache, then lookup
     * 
     * @param string $room_id Mews resource ID from database
     * @param string $room_number Room number for display
     * @param string $guest_surname Guest's last name to validate
     * @return array|false Returns reservation data or false if not found
     */
    public function validateGuest($room_id, $room_number, $guest_surname) {
        try {
            $surnameNormalized = strtolower(trim($guest_surname));
            
            // Step 1: Check cache if database is available
            if ($this->pdo) {
                $cached = $this->checkCache($room_id, $surnameNormalized);
                if ($cached) {
                    error_log("Cache HIT for room $room_number, surname $surnameNormalized");
                    return $cached;
                }
                error_log("Cache MISS for room $room_number, surname $surnameNormalized");
                
                // Step 2: Check if we need bulk fetch or individual lookup
                $lastBulkFetch = $this->getLastBulkFetch();
                $timeSinceLastFetch = time() - strtotime($lastBulkFetch);
                
                if ($timeSinceLastFetch < self::BULK_FETCH_INTERVAL) {
                    // Bulk fetch was recent, do individual API lookup
                    error_log("Individual API lookup (bulk fetch was " . round($timeSinceLastFetch / 60) . " min ago)");
                    $result = $this->mews->validateGuestForWifi($room_id, $room_number, $guest_surname);
                    
                    if ($result && isset($result['valid']) && $result['valid']) {
                        // Cache this result
                        $this->addToCache($result);
                    }
                    
                    return $result;
                } else {
                    // Bulk fetch needed
                    error_log("Bulk fetch required (last fetch: $lastBulkFetch)");
                    $this->bulkFetchAndCache();
                    
                    // Check cache again after bulk fetch
                    $cached = $this->checkCache($room_id, $surnameNormalized);
                    if ($cached) {
                        return $cached;
                    }
                    
                    // Still not found after bulk fetch - guest not in system
                    error_log("Guest not found after bulk fetch: room $room_number, surname $surnameNormalized");
                    return false;
                }
            }
            
            // No database connection, fall back to direct API call
            return $this->mews->validateGuestForWifi($room_id, $room_number, $guest_surname);
            
        } catch (Exception $e) {
            error_log("Mews WiFi Auth Error: " . $e->getMessage());
            
            // Fallback for development/testing
            if ($this->environment === 'demo' || $this->environment === 'cert') {
                return $this->getFallbackValidation($room_number, $guest_surname);
            }
            
            return false;
        }
    }
    
    /**
     * Check cache for a matching reservation
     * 
     * @param string $room_id Mews resource ID
     * @param string $surname Normalized surname (lowercase)
     * @return array|false Cached reservation data or false
     */
    private function checkCache($room_id, $surname) {
        if (!$this->pdo) return false;
        
        try {
            $stmt = $this->pdo->prepare("
                SELECT room_id, room_number, surname, check_in, check_out, reservation_id, customer_id
                FROM cached_reservations
                WHERE room_id = :room_id 
                  AND surname = :surname 
                  AND check_out >= CURDATE()
            ");
            $stmt->execute([
                ':room_id' => $room_id,
                ':surname' => $surname
            ]);
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result) {
                return [
                    'valid' => true,
                    'room_number' => $result['room_number'],
                    'room_id' => $result['room_id'],
                    'guest_surname' => $result['surname'],
                    'check_in' => $result['check_in'],
                    'check_out' => $result['check_out'],
                    'reservation_id' => $result['reservation_id'],
                    'customer_id' => $result['customer_id'],
                    'from_cache' => true
                ];
            }
            
            return false;
            
        } catch (PDOException $e) {
            error_log("Cache check error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Add a reservation to the cache
     * 
     * @param array $reservation Reservation data from Mews API
     */
    private function addToCache($reservation) {
        if (!$this->pdo || !$reservation) return;
        
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO cached_reservations 
                    (reservation_id, room_id, room_number, surname, check_in, check_out, customer_id)
                VALUES 
                    (:reservation_id, :room_id, :room_number, :surname, :check_in, :check_out, :customer_id)
                ON DUPLICATE KEY UPDATE
                    room_id = VALUES(room_id),
                    room_number = VALUES(room_number),
                    surname = VALUES(surname),
                    check_in = VALUES(check_in),
                    check_out = VALUES(check_out),
                    customer_id = VALUES(customer_id),
                    updated_at = CURRENT_TIMESTAMP
            ");
            
            $stmt->execute([
                ':reservation_id' => $reservation['reservation_id'],
                ':room_id' => $reservation['room_id'],
                ':room_number' => $reservation['room_number'],
                ':surname' => strtolower(trim($reservation['guest_surname'])),
                ':check_in' => $reservation['check_in'],
                ':check_out' => $reservation['check_out'],
                ':customer_id' => $reservation['customer_id']
            ]);
            
            error_log("Cached reservation: room {$reservation['room_number']}, surname {$reservation['guest_surname']}");
            
        } catch (PDOException $e) {
            error_log("Cache add error: " . $e->getMessage());
        }
    }
    
    /**
     * Get the timestamp of the last bulk fetch
     * 
     * @return string Timestamp of last bulk fetch
     */
    private function getLastBulkFetch() {
        if (!$this->pdo) return '1970-01-01 00:00:00';
        
        try {
            $stmt = $this->pdo->prepare("
                SELECT setting_value 
                FROM system_settings 
                WHERE setting_key = 'last_bulk_fetch'
            ");
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return $result ? $result['setting_value'] : '1970-01-01 00:00:00';
            
        } catch (PDOException $e) {
            error_log("Get last bulk fetch error: " . $e->getMessage());
            return '1970-01-01 00:00:00';
        }
    }
    
    /**
     * Update the timestamp of the last bulk fetch
     */
    private function updateLastBulkFetch() {
        if (!$this->pdo) return;
        
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO system_settings (setting_key, setting_value)
                VALUES ('last_bulk_fetch', NOW())
                ON DUPLICATE KEY UPDATE
                    setting_value = NOW()
            ");
            $stmt->execute();
            
        } catch (PDOException $e) {
            error_log("Update last bulk fetch error: " . $e->getMessage());
        }
    }
    
    /**
     * Bulk fetch all today's reservations from Mews and cache them
     */
    private function bulkFetchAndCache() {
        if (!$this->pdo) return;
        
        try {
            error_log("Starting bulk fetch of all reservations...");
            
            // Get all current reservations from Mews
            $reservations = $this->mews->getCurrentReservations();
            
            if (empty($reservations)) {
                error_log("No reservations returned from bulk fetch");
                $this->updateLastBulkFetch();
                return;
            }
            
            error_log("Bulk fetch returned " . count($reservations) . " reservations");
            
            // Cache each reservation
            foreach ($reservations as $reservation) {
                // Format for addToCache
                $cacheData = [
                    'room_id' => $reservation['resource_id'],
                    'room_number' => $this->getRoomNumberFromId($reservation['resource_id']),
                    'guest_surname' => $reservation['guest_surname'],
                    'check_in' => $reservation['check_in'],
                    'check_out' => $reservation['check_out'],
                    'reservation_id' => $reservation['reservation_id'],
                    'customer_id' => $reservation['customer_id'] ?? 'unknown'
                ];
                
                $this->addToCache($cacheData);
            }
            
            // Update the last bulk fetch timestamp
            $this->updateLastBulkFetch();
            
            error_log("Bulk fetch complete - cached " . count($reservations) . " reservations");
            
        } catch (Exception $e) {
            error_log("Bulk fetch error: " . $e->getMessage());
        }
    }
    
    /**
     * Get room number from room ID (Mews resource ID)
     * 
     * @param string $roomId Mews resource GUID
     * @return string Room number or 'Unknown'
     */
    private function getRoomNumberFromId($roomId) {
        if (!$this->pdo) return 'Unknown';
        
        try {
            $stmt = $this->pdo->prepare("
                SELECT name FROM rooms WHERE id = :id
            ");
            $stmt->execute([':id' => $roomId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return $result ? $result['name'] : 'Unknown';
            
        } catch (PDOException $e) {
            error_log("Get room number error: " . $e->getMessage());
            return 'Unknown';
        }
    }
    
    /**
     * Clear expired reservations from cache (called by cron or admin)
     */
    public function clearExpiredCache() {
        if (!$this->pdo) return 0;
        
        try {
            $stmt = $this->pdo->prepare("
                DELETE FROM cached_reservations 
                WHERE check_out < CURDATE()
            ");
            $stmt->execute();
            
            $count = $stmt->rowCount();
            error_log("Cleared $count expired reservations from cache");
            
            return $count;
            
        } catch (PDOException $e) {
            error_log("Clear cache error: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Get cache statistics
     * 
     * @return array Cache stats
     */
    public function getCacheStats() {
        if (!$this->pdo) return [];
        
        try {
            $stmt = $this->pdo->query("SELECT * FROM cache_statistics");
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            error_log("Get cache stats error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get all current reservations for today
     * 
     * @return array
     */
    public function getTodaysReservations() {
        try {
            return $this->mews->getCurrentReservations();
        } catch (Exception $e) {
            error_log("Mews WiFi Auth Error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Fallback validation for development/testing
     * 
     * @param string $room_number
     * @param string $guest_surname
     * @return array|false
     */
    private function getFallbackValidation($room_number, $guest_surname) {
        // Test data for development when Mews API is unavailable
        $test_reservations = [
            '101' => ['Schmidt', 'Mueller', 'Johnson'],
            '102' => ['Weber', 'Fischer', 'Brown'], 
            '103' => ['Becker', 'Wagner', 'Davis'],
            '201' => ['Schulz', 'Hoffmann', 'Wilson'],
            '202' => ['Koch', 'Richter', 'Miller'],
            '203' => ['Neumann', 'Klein', 'Taylor'],
            '301' => ['Wolf', 'Schroeder', 'Anderson'],
            '302' => ['Zimmermann', 'Braun', 'Thomas'],
            '303' => ['Krueger', 'Hofmann', 'Jackson'],
            '401' => ['Hartmann', 'Lange', 'White']
        ];
        
        if (isset($test_reservations[$room_number]) && 
            in_array($guest_surname, $test_reservations[$room_number])) {
            
            return [
                'valid' => true,
                'room_number' => $room_number,
                'room_id' => 'FALLBACK_ROOM_ID_' . $room_number,
                'guest_surname' => $guest_surname,
                'check_in' => date('Y-m-d'),
                'check_out' => date('Y-m-d', strtotime('+3 days')),
                'reservation_id' => 'FALLBACK_' . $room_number . '_' . time(),
                'customer_id' => 'TEST_CUSTOMER_' . $room_number
            ];
        }
        
        return false;
    }
    
    /**
     * Get Mews environment info
     * 
     * @return array
     */
    public function getEnvironmentInfo() {
        return [
            'environment' => $this->environment,
            'api_url' => $this->mews->getApiUrl(),
            'status' => 'Connected'
        ];
    }
}
?>