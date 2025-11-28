<?php
/**
 * Mews WiFi Authentication Adapter
 * Adapts the Mews connector for WiFi authentication system
 */

require_once 'mews_connector.php';

class MewsWifiAuth {
    private $mews;
    private $environment;
    private $config;
    
    public function __construct($environment = 'demo', $configFile = null) {
        $this->environment = $environment;
        
        // Load config
        $configPath = $configFile ?: __DIR__ . '/mews_config.php';
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
     * Validate guest credentials against Mews PMS
     * 
     * @param string $room_number
     * @param string $guest_surname
     * @return array|false Returns reservation data or false if not found
     */
    public function validateGuest($room_number, $guest_surname) {
        try {
            return $this->mews->validateGuestForWifi($room_number, $guest_surname);
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