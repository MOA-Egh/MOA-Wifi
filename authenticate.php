<?php
/**
 * MOA Hotel WiFi Authentication Script
 * Handles guest authentication and device management
 */

// Include required files
require_once 'mews_wifi_auth.php';

// Database configuration - Update with your actual database credentials
$db_config = require 'config.php';

// Mews PMS Configuration
$mews_config = require 'mews_config.php';
$mews_environment = $mews_config['mews']['environment'];

// RouterOS HotSpot variables
$mac_address = $_SERVER['REMOTE_ADDR']; // This should be the MAC address in production
$ip_address = $_SERVER['REMOTE_ADDR'];
$username = $_POST['username'] ?? ''; // Room number
$surname = $_POST['radius1'] ?? '';   // Guest surname
$wifi_speed = $_POST['radius2'] ?? 'normal'; // WiFi speed preference
$dst = $_POST['dst'] ?? 'http://www.google.com';

// For RouterOS, you might need to get the MAC address differently
// In a real RouterOS environment, this would be available as a server variable
if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
    $mac_address = $_SERVER['HTTP_X_FORWARDED_FOR'];
}

/**
 * Connect to database
 */
function connectDB($config) {
    try {
        $pdo = new PDO(
            "mysql:host={$config['host']};dbname={$config['database']};charset=utf8",
            $config['username'],
            $config['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        return $pdo;
    } catch (PDOException $e) {
        error_log("Database connection failed: " . $e->getMessage());
        redirectToError("Database connection failed");
    }
}

/**
 * Validate guest credentials against Mews PMS
 */
function validateGuest($mews_auth, $room_number, $surname) {
    return $mews_auth->validateGuest($room_number, $surname);
}

/**
 * Check how many devices are already registered for fast WiFi in this room
 */
function getFastDeviceCount($pdo, $room_number) {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count 
        FROM authorized_devices 
        WHERE room_number = ? AND fast_mode = TRUE
    ");
    $stmt->execute([$room_number]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result['count'];
}

/**
 * Check if device is already registered
 */
function isDeviceRegistered($pdo, $mac_address) {
    $stmt = $pdo->prepare("
        SELECT * FROM authorized_devices 
        WHERE device_mac = ?
    ");
    $stmt->execute([$mac_address]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Register or update device
 */
function registerDevice($pdo, $mac_address, $room_number, $surname, $fast_mode) {
    // Check if device already exists
    $existing = isDeviceRegistered($pdo, $mac_address);
    
    if ($existing) {
        // Update existing device
        $stmt = $pdo->prepare("
            UPDATE authorized_devices 
            SET room_number = ?, surname = ?, fast_mode = ?, last_update = CURRENT_TIMESTAMP 
            WHERE device_mac = ?
        ");
        $stmt->execute([$room_number, $surname, $fast_mode, $mac_address]);
    } else {
        // Insert new device
        $stmt = $pdo->prepare("
            INSERT INTO authorized_devices (device_mac, room_number, surname, fast_mode) 
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$mac_address, $room_number, $surname, $fast_mode]);
    }
}

/**
 * Update room cleaning skip preference
 */
function updateRoomSkipPreference($pdo, $room_number, $surname, $skip_clean) {
    $stmt = $pdo->prepare("
        INSERT INTO rooms_to_skip (room_number, guest_surname, skip_clean) 
        VALUES (?, ?, ?) 
        ON DUPLICATE KEY UPDATE 
        skip_clean = VALUES(skip_clean), 
        updated_at = CURRENT_TIMESTAMP
    ");
    $stmt->execute([$room_number, $surname, $skip_clean]);
}

/**
 * Redirect to error page
 */
function redirectToError($message, $lang = 'en') {
    $encoded_message = urlencode($message);
    header("Location: flogin.html?error=" . $encoded_message . "&lang=" . $lang);
    exit();
}

/**
 * Redirect to success page
 */
function redirectToSuccess($dst) {
    header("Location: alogin.html?dst=" . urlencode($dst));
    exit();
}

/**
 * Get client MAC address (RouterOS specific)
 * In a real RouterOS environment, this would be different
 */
function getClientMAC() {
    // In RouterOS hotspot, the MAC address is typically available as a server variable
    // This is a simplified version - you'll need to adapt this for your RouterOS setup
    
    if (isset($_SERVER['HTTP_X_MAC_ADDRESS'])) {
        return $_SERVER['HTTP_X_MAC_ADDRESS'];
    }
    
    // Fallback: generate a mock MAC based on IP for testing
    $ip = $_SERVER['REMOTE_ADDR'];
    return sprintf("02:00:%02x:%02x:%02x:%02x", 
        ...array_map('intval', explode('.', $ip))
    );
}

// Main authentication logic
try {
    // Get actual MAC address
    $mac_address = getClientMAC();
    
    // Validate required fields
    if (empty($username) || empty($surname)) {
        redirectToError("Room number and surname are required");
    }
    
    // Connect to database
    $pdo = connectDB($db_config);
    
    // Initialize Mews authentication
    $mews_auth = new MewsWifiAuth($mews_environment);
    
    // Validate guest credentials against Mews PMS
    $guest = validateGuest($mews_auth, $username, $surname);
    if (!$guest) {
        redirectToError("Invalid room number or surname. Please check your reservation details or contact reception.");
    }
    
    // Determine if fast mode is requested
    $fast_mode = ($wifi_speed === 'fast');
    
    // If fast mode requested, check device limit
    if ($fast_mode) {
        $current_fast_devices = getFastDeviceCount($pdo, $username);
        $existing_device = isDeviceRegistered($pdo, $mac_address);
        
        // If this device isn't already registered and we're at the limit, deny fast access
        if (!$existing_device && $current_fast_devices >= 3) {
            redirectToError("Maximum 3 devices per room can use fast WiFi. This device will be connected with standard speed.");
        }
        
        // If we already have 3 devices and this device is registered but not in fast mode
        if ($existing_device && !$existing_device['fast_mode'] && $current_fast_devices >= 3) {
            redirectToError("Maximum 3 devices per room can use fast WiFi. This device will be connected with standard speed.");
        }
    }
    
    // Register the device
    registerDevice($pdo, $mac_address, $username, $surname, $fast_mode);
    
    // Update room cleaning preference if fast mode is selected
    if ($fast_mode) {
        updateRoomSkipPreference($pdo, $username, $surname, true);
    }
    
    // Log the successful authentication
    error_log("WiFi access granted - Room: $username, Device: $mac_address, Fast: " . ($fast_mode ? 'Yes' : 'No'));
    
    // For RouterOS integration, you might need to set additional variables or call RouterOS API
    // This depends on your specific RouterOS configuration
    
    // Redirect to success page
    redirectToSuccess($dst);
    
} catch (Exception $e) {
    error_log("Authentication error: " . $e->getMessage());
    redirectToError("An error occurred during authentication. Please try again.");
}
?>