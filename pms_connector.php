<?php
/**
 * PMS API Connector for MOA Hotel WiFi Management System
 * This class handles integration with your Property Management System
 */

class PMSConnector {
    private $api_base_url;
    private $api_key;
    private $timeout;
    
    public function __construct($api_base_url, $api_key, $timeout = 10) {
        $this->api_base_url = rtrim($api_base_url, '/');
        $this->api_key = $api_key;
        $this->timeout = $timeout;
    }
    
    /**
     * Validate guest credentials against PMS reservations
     * 
     * @param string $room_number
     * @param string $guest_surname
     * @return array|false Returns reservation data or false if not found
     */
    public function validateGuest($room_number, $guest_surname) {
        try {
            // Get today's date
            $today = date('Y-m-d');
            
            // Prepare API request
            $url = $this->api_base_url . '/reservations/validate';
            $data = [
                'room_number' => $room_number,
                'guest_surname' => $guest_surname,
                'date' => $today
            ];
            
            $response = $this->makeApiRequest($url, 'POST', $data);
            
            if ($response && isset($response['valid']) && $response['valid'] === true) {
                return [
                    'room_number' => $response['room_number'],
                    'guest_surname' => $response['guest_surname'], 
                    'check_in' => $response['check_in'],
                    'check_out' => $response['check_out'],
                    'reservation_id' => $response['reservation_id'] ?? null
                ];
            }
            
            return false;
            
        } catch (Exception $e) {
            error_log("PMS API Error: " . $e->getMessage());
            
            // Fallback validation for development/testing
            return $this->fallbackValidation($room_number, $guest_surname);
        }
    }
    
    /**
     * Get all current reservations for today
     * 
     * @return array
     */
    public function getTodaysReservations() {
        try {
            $today = date('Y-m-d');
            $url = $this->api_base_url . '/reservations/current';
            $data = ['date' => $today];
            
            $response = $this->makeApiRequest($url, 'GET', $data);
            
            return $response['reservations'] ?? [];
            
        } catch (Exception $e) {
            error_log("PMS API Error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Make HTTP request to PMS API
     * 
     * @param string $url
     * @param string $method
     * @param array $data
     * @return array|false
     */
    private function makeApiRequest($url, $method = 'GET', $data = []) {
        $ch = curl_init();
        
        // Basic cURL options
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->api_key,
                'Accept: application/json'
            ]
        ]);
        
        // Set method and data
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        } elseif ($method === 'GET' && !empty($data)) {
            $url .= '?' . http_build_query($data);
            curl_setopt($ch, CURLOPT_URL, $url);
        }
        
        // Execute request
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        curl_close($ch);
        
        // Handle cURL errors
        if ($error) {
            throw new Exception("cURL Error: " . $error);
        }
        
        // Handle HTTP errors
        if ($http_code >= 400) {
            throw new Exception("HTTP Error: " . $http_code);
        }
        
        // Decode JSON response
        $decoded = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Invalid JSON response");
        }
        
        return $decoded;
    }
    
    /**
     * Fallback validation for development/testing when PMS API is unavailable
     * 
     * @param string $room_number
     * @param string $guest_surname
     * @return array|false
     */
    private function fallbackValidation($room_number, $guest_surname) {
        // This is just for testing - remove in production
        $test_reservations = [
            '101' => ['Schmidt', 'Mueller'],
            '102' => ['Weber', 'Fischer'], 
            '103' => ['Becker', 'Wagner'],
            '201' => ['Schulz', 'Hoffmann'],
            '202' => ['Koch', 'Richter']
        ];
        
        if (isset($test_reservations[$room_number]) && 
            in_array($guest_surname, $test_reservations[$room_number])) {
            
            return [
                'room_number' => $room_number,
                'guest_surname' => $guest_surname,
                'check_in' => date('Y-m-d'),
                'check_out' => date('Y-m-d', strtotime('+3 days')),
                'reservation_id' => 'TEST_' . $room_number . '_' . time()
            ];
        }
        
        return false;
    }
}
?>