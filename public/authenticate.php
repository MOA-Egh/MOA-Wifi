<?php
/**
 * MOA Hotel WiFi Authentication Script
 * Handles guest authentication and device management
 */

// Include required files
require_once __DIR__ . '/../src/MewsWifiAuth.php';

// Database configuration - Update with your actual database credentials
$db_config = require __DIR__ . '/../config/config.php';

// Mews PMS Configuration
$mews_config = require __DIR__ . '/../config/mews_config.php';
$mews_environment = $mews_config['mews']['environment'];

// RouterOS HotSpot variables
$mac_address = $_SERVER['REMOTE_ADDR']; // This should be the MAC address in production
$ip_address = $_SERVER['REMOTE_ADDR'];
$username = $_POST['username'] ?? ''; // Room number
$surname = $_POST['radius1'] ?? '';   // Guest surname
$wifi_speed = $_POST['radius2'] ?? 'normal'; // WiFi speed preference
$dst = $_POST['dst'] ?? 'http://www.google.com';
$lang = $_POST['lang'] ?? 'en'; // Language preference from login page

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
 * Get devices count by speed for a room
 */
function getDeviceCountBySpeed($pdo, $room_number, $speed) {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count 
        FROM authorized_devices 
        WHERE room_number = ? AND speed = ?
    ");
    $stmt->execute([$room_number, $speed]);
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
function registerDevice($pdo, $mac_address, $room_number, $surname, $speed) {
    // Check if device already exists
    $existing = isDeviceRegistered($pdo, $mac_address);
    
    if ($existing) {
        // Update existing device
        $stmt = $pdo->prepare("
            UPDATE authorized_devices 
            SET room_number = ?, surname = ?, speed = ?, last_update = CURRENT_TIMESTAMP 
            WHERE device_mac = ?
        ");
        $stmt->execute([$room_number, $surname, $speed, $mac_address]);
    } else {
        // Insert new device
        $stmt = $pdo->prepare("
            INSERT INTO authorized_devices (device_mac, room_number, surname, speed) 
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$mac_address, $room_number, $surname, $speed]);
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
function redirectToSuccess($dst, $speed, $lang) {
    // Generate a simple token to validate access came from login
    $token = base64_encode(hash('sha256', session_id() . time() . $dst, true));
    $speedLabel = ($speed >= 50) ? 'fast' : 'standard';
    header("Location: alogin.html?dst=" . urlencode($dst) . "&speed=" . $speedLabel . "&lang=" . $lang . "&token=" . urlencode($token));
    exit();
}

/**
 * Get client MAC address (RouterOS specific)
 * RouterOS provides the MAC address through server variables when hotspot is configured properly
 */
function getClientMAC() {
    // Method 0: RouterOS template variables (most reliable)
    // RouterOS passes MAC via $(mac) template variable in form
    if (isset($_POST['client_mac']) && preg_match('/^([0-9A-F]{2}[:-]){5}([0-9A-F]{2})$/i', $_POST['client_mac'])) {
        return strtoupper(str_replace('-', ':', $_POST['client_mac']));
    }
    
    // RouterOS hotspot provides MAC address in different ways depending on configuration
    
    // Method 1: Direct server variable (most common in RouterOS hotspot)
    if (isset($_SERVER['HTTP_X_FORWARDED_FOR']) && preg_match('/^([0-9A-F]{2}[:-]){5}([0-9A-F]{2})$/i', $_SERVER['HTTP_X_FORWARDED_FOR'])) {
        return strtoupper(str_replace('-', ':', $_SERVER['HTTP_X_FORWARDED_FOR']));
    }
    
    // Method 2: RouterOS may pass MAC in custom header
    if (isset($_SERVER['HTTP_X_MAC_ADDRESS'])) {
        return strtoupper(str_replace('-', ':', $_SERVER['HTTP_X_MAC_ADDRESS']));
    }
    
    // Method 3: Check if RouterOS passes MAC in REMOTE_USER (some configurations)
    if (isset($_SERVER['REMOTE_USER']) && preg_match('/^([0-9A-F]{2}[:-]){5}([0-9A-F]{2})$/i', $_SERVER['REMOTE_USER'])) {
        return strtoupper(str_replace('-', ':', $_SERVER['REMOTE_USER']));
    }
    
    // Method 4: RouterOS may pass MAC in query parameters or form data
    if (isset($_GET['mac']) && preg_match('/^([0-9A-F]{2}[:-]){5}([0-9A-F]{2})$/i', $_GET['mac'])) {
        return strtoupper(str_replace('-', ':', $_GET['mac']));
    }
    
    // Method 5: Check if RouterOS hotspot passes client IP and we can resolve MAC via ARP
    // Note: This requires RouterOS to be configured to pass client info
    $client_ip = getClientIP();
    if ($client_ip) {
        $mac = getMACFromIP($client_ip);
        if ($mac) {
            return $mac;
        }
    }
    
    // Development/Testing fallback: Generate consistent MAC based on IP and User Agent
    // This ensures same device gets same MAC during testing
    if (isDevelopmentMode()) {
        return generateTestMAC();
    }
    
    // If we can't get MAC address, this is a configuration issue
    error_log("WARNING: Cannot determine client MAC address. Check RouterOS hotspot configuration.");
    throw new Exception("Unable to determine device MAC address. Please contact technical support.");
}

/**
 * Get client IP address
 */
function getClientIP() {
    // RouterOS typically provides the real client IP
    $ip_sources = [
        $_SERVER['HTTP_X_REAL_IP'] ?? null,
        $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null,
        $_SERVER['REMOTE_ADDR'] ?? null
    ];
    
    foreach ($ip_sources as $ip) {
        if ($ip && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return $ip;
        }
        if ($ip && filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip; // Accept private IPs for hotel network
        }
    }
    
    return $_SERVER['REMOTE_ADDR'] ?? null;
}

/**
 * Attempt to get MAC address from IP using ARP (RouterOS specific)
 * This only works if RouterOS is configured to expose this information
 */
function getMACFromIP($ip) {
    // This would require RouterOS API integration or special configuration
    // For now, return null - implement based on your RouterOS setup
    return null;
}

/**
 * Check if we're in development mode
 */
function isDevelopmentMode() {
    $mews_config = require __DIR__ . '/../config/mews_config.php';
    return isset($mews_config['development']['use_fallback_when_api_fails']) && 
           $mews_config['development']['use_fallback_when_api_fails'] === true;
}

/**
 * Generate a consistent test MAC for development
 */
function generateTestMAC() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    
    // Create a consistent hash based on IP and User Agent
    $hash = md5($ip . $userAgent);
    
    // Format as MAC address (using locally administered MAC prefix 02:xx:xx:xx:xx:xx)
    return sprintf(
        "02:%s:%s:%s:%s:%s",
        substr($hash, 0, 2),
        substr($hash, 2, 2), 
        substr($hash, 4, 2),
        substr($hash, 6, 2),
        substr($hash, 8, 2)
    );
}

// Main authentication logic
try {
    // Start session for token generation
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Validate required fields first
    if (empty($username) || empty($surname)) {
        redirectToError("Room number and surname are required", $lang);
    }
    
    // Get actual MAC address
    try {
        $mac_address = getClientMAC();
        
        // Validate MAC address format
        if (!preg_match('/^([0-9A-F]{2}:){5}[0-9A-F]{2}$/', $mac_address)) {
            throw new Exception("Invalid MAC address format: $mac_address");
        }
        
    } catch (Exception $e) {
        error_log("MAC address error: " . $e->getMessage());
        redirectToError("Unable to register device. .", $lang);
    }
    
    // Connect to database
    $pdo = connectDB($db_config);
    
    // Initialize Mews authentication
    $mews_auth = new MewsWifiAuth($mews_environment);
    
    // Validate guest credentials against Mews PMS
    $guest = validateGuest($mews_auth, $username, $surname);
    if (!$guest) {
        redirectToError("Invalid room number or surname. Please check your reservation details or contact reception.", $lang);
    }
    
    // Determine speed based on selection (fast = 50 Mbps, normal = 20 Mbps)
    $speed = ($wifi_speed === 'fast') ? 50 : 20;
    
    // Register the device with the selected speed
    registerDevice($pdo, $mac_address, $username, $surname, $speed);
    
    // Update room cleaning preference if fast speed is selected
    if ($speed >= 50) {
        updateRoomSkipPreference($pdo, $username, $surname, true);
    }
    
    // Log the successful authentication
    error_log("WiFi access granted - Room: $username, Device: $mac_address, Speed: {$speed} Mbps");
    
    // For RouterOS integration, you might need to set additional variables or call RouterOS API
    // This depends on your specific RouterOS configuration
    
    // Redirect to success page
    redirectToSuccess($dst, $speed, $lang);
    
} catch (Exception $e) {
    error_log("Authentication error: " . $e->getMessage());
    redirectToError("An error occurred during authentication. Please try again.", $lang);
}
?>