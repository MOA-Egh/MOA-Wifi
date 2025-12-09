<?php
/**
 * MOA Hotel WiFi Authentication Script
 * Handles guest authentication and device management
 */

// ============================================================================
// DEBUG LOGGING
// ============================================================================
function debugLog($message, $data = null) {
    $logFile = __DIR__ . '/../logs/auth_debug.log';
    $logDir = dirname($logFile);
    
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[$timestamp] $message";
    
    if ($data !== null) {
        if (is_array($data) || is_object($data)) {
            $logEntry .= "\n" . print_r($data, true);
        } else {
            $logEntry .= " | $data";
        }
    }
    
    $logEntry .= "\n" . str_repeat('-', 80) . "\n";
    file_put_contents($logFile, $logEntry, FILE_APPEND);
}

// Log all incoming data at the start
debugLog("=== NEW REQUEST ===");
debugLog("POST data", $_POST);
debugLog("GET data", $_GET);
debugLog("SERVER variables (relevant)", [
    'REMOTE_ADDR' => $_SERVER['REMOTE_ADDR'] ?? 'not set',
    'HTTP_X_FORWARDED_FOR' => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? 'not set',
    'HTTP_X_MAC_ADDRESS' => $_SERVER['HTTP_X_MAC_ADDRESS'] ?? 'not set',
    'HTTP_X_REAL_IP' => $_SERVER['HTTP_X_REAL_IP'] ?? 'not set',
]);
// ============================================================================

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
$linkLogin = $_POST['link_login'] ?? $_POST['link-login'] ?? ''; // MikroTik login URL

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
        $dsn = "mysql:host={$config['host']};dbname={$config['database']};charset=utf8";
        $pdo = new PDO(
            $dsn,
            $config['username'],
            $config['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        return $pdo;
    } catch (PDOException $e) {
        error_log("Database connection failed: " . $e->getMessage());
        global $lang;
        redirectToError("Database connection failed", $lang ?? 'en');
    }
}

/**
 * Validate guest credentials against Mews PMS
 * Now requires room_id from database lookup
 */
function validateGuest($mews_auth, $room_id, $room_number, $surname) {
    return $mews_auth->validateGuest($room_id, $room_number, $surname);
}

/**
 * Get room id (Mews resource ID) from database by room name/number
 */
function getRoomId($pdo, $room_number) {
    $stmt = $pdo->prepare("
        SELECT id FROM rooms 
        WHERE name = ?
    ");
    $stmt->execute([$room_number]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result ? $result['id'] : null;
}

/**
 * Get devices count for a room and guest
 * Counts only devices registered to this specific guest (by surname)
 */
function getDeviceCountForGuest($pdo, $room_number, $surname) {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count 
        FROM authorized_devices 
        WHERE room_number = ? AND surname = ?
    ");
    $stmt->execute([$room_number, $surname]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result['count'];
}

/**
 * Check if device is already registered for this guest
 * Uses room_number + surname + device_mac as the unique identifier
 */
function isDeviceRegisteredForGuest($pdo, $mac_address, $room_number, $surname) {
    $stmt = $pdo->prepare("
        SELECT * FROM authorized_devices 
        WHERE device_mac = ? AND room_number = ? AND surname = ?
    ");
    $stmt->execute([$mac_address, $room_number, $surname]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Register or update device for a specific guest
 * Uses composite key (room_number, surname, device_mac) for guest isolation
 */
function registerDevice($pdo, $mac_address, $room_number, $surname, $speed) {
    // Check if this device is already registered for THIS guest
    $existing = isDeviceRegisteredForGuest($pdo, $mac_address, $room_number, $surname);
    
    if ($existing) {
        // Update existing device for this guest
        $stmt = $pdo->prepare("
            UPDATE authorized_devices 
            SET speed = ?, last_update = CURRENT_TIMESTAMP 
            WHERE device_mac = ? AND room_number = ? AND surname = ?
        ");
        $stmt->execute([$speed, $mac_address, $room_number, $surname]);
    } else {
        // Insert new device for this guest
        // Uses ON DUPLICATE KEY to handle the composite unique constraint
        $stmt = $pdo->prepare("
            INSERT INTO authorized_devices (device_mac, room_number, surname, speed) 
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
            speed = VALUES(speed), 
            last_update = CURRENT_TIMESTAMP
        ");
        $stmt->execute([$mac_address, $room_number, $surname, $speed]);
    }
}

/**
 * Update room cleaning skip preference
 * Only sets skip_clean to 1 when fast WiFi is selected - never resets to 0
 * This ensures cleaning is skipped even if only one device chose fast WiFi
 */
function updateRoomSkipPreference($pdo, $room_number, $surname, $skip_clean) {
    // Only update if guest selected fast WiFi (skip_clean = true)
    // Never reset to 0 - once a guest opts out of cleaning, it stays
    if (!$skip_clean) {
        return; // Do nothing if normal WiFi selected
    }
    
    $stmt = $pdo->prepare("
        INSERT INTO rooms_to_skip (room_number, guest_surname, skip_clean) 
        VALUES (?, ?, 1) 
        ON DUPLICATE KEY UPDATE 
        skip_clean = 1, 
        updated_at = CURRENT_TIMESTAMP
    ");
    $stmt->execute([$room_number, $surname]);
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
 * Redirect to success page with MikroTik auto-login support
 */
function redirectToSuccess($linkLogin, $username, $surname, $lang, $speed) {
    // Generate a simple token to validate access came from login
    $token = base64_encode(hash('sha256', session_id() . time(), true));
    $speedLabel = ($speed >= 20) ? 'fast' : 'standard';
    $mtUser = 'room' . $username;
    
    // Build query parameters for success page
    $params = http_build_query([
        'lang' => $lang,
        'speed' => $speedLabel,
        'token' => $token,
        'link_login' => $linkLogin,
        'mt_user' => $mtUser,
        'mt_pass' => $surname
    ]);
    
    header("Location: alogin.html?" . $params);
    exit();
}

/**
 * Check active session count for a MikroTik hotspot user
 * Returns the number of currently active sessions
 */
function checkMikroTikActiveSessionCount($config, $roomNumber) {
    if (!isset($config['mikrotik']['enabled']) || !$config['mikrotik']['enabled']) {
        return 0; // MikroTik disabled, no sessions to count
    }
    
    $host = $config['mikrotik']['host'];
    $port = $config['mikrotik']['port'] ?? 8728;
    $user = $config['mikrotik']['user'];
    $mtUsername = 'room' . $roomNumber;
    
    try {
        require_once __DIR__ . '/../vendor/autoload.php';
        
        $client = new \RouterOS\Client([
            'host' => $host,
            'user' => $user,
            'pass' => $config['mikrotik']['pass'],
            'port' => $port,
            'timeout' => 10
        ]);
        
        // Count active sessions for this user
        $query = new \RouterOS\Query('/ip/hotspot/active/print');
        $query->where('user', $mtUsername);
        $activeSessions = $client->query($query)->read();
        
        debugLog("Active sessions for $mtUsername", $activeSessions);
        return count($activeSessions);
        
    } catch (Exception $e) {
        debugLog("Error checking active sessions: " . $e->getMessage());
        return 0; // On error, allow login attempt
    }
}

/**
 * Configure MikroTik hotspot user via API and authorize the client
 * Creates user AND activates session directly via IP binding (no browser login needed)
 * 
 * @return array ['success' => bool, 'error' => string|null, 'error_type' => string|null]
 */
function configureMikroTikUser($config, $roomNumber, $surname, $profile, $macAddress = null, $clientIP = null) {
    debugLog("=== MIKROTIK CONFIGURATION ===");
    debugLog("Parameters", [
        'roomNumber' => $roomNumber,
        'surname' => $surname,
        'profile' => $profile,
        'macAddress' => $macAddress,
        'clientIP' => $clientIP
    ]);
    
    // Check if MikroTik integration is enabled
    if (!isset($config['mikrotik']['enabled']) || !$config['mikrotik']['enabled']) {
        debugLog("MikroTik integration DISABLED, skipping");
        return ['success' => true, 'error' => null, 'error_type' => null];
    }
    
    // Get max concurrent sessions from config (default 3)
    $maxSessions = $config['mikrotik']['max_sessions'] ?? 3;
    
    $host = $config['mikrotik']['host'];
    $port = $config['mikrotik']['port'] ?? 8728;
    $user = $config['mikrotik']['user'];
    
    debugLog("MikroTik connection", [
        'host' => $host,
        'port' => $port,
        'user' => $user
    ]);
    
    // Test socket connection before API
    debugLog("Testing TCP connection to $host:$port...");
    $socket = @fsockopen($host, $port, $errno, $errstr, 5);
    if ($socket) {
        debugLog("✓ TCP connection successful");
        fclose($socket);
    } else {
        debugLog("✗ TCP connection FAILED: [$errno] $errstr");
        error_log("MikroTik: Cannot connect to $host:$port - [$errno] $errstr");
        return ['success' => false, 'error' => 'Connection failed', 'error_type' => 'connection'];
    }
    
    // Check if RouterOS API library is available
    if (!file_exists(__DIR__ . '/../vendor/autoload.php')) {
        debugLog("ERROR: vendor/autoload.php not found");
        error_log("MikroTik API: vendor/autoload.php not found. Run 'composer require evilfreelancer/routeros-api-php'");
        return ['success' => false, 'error' => 'API library not found', 'error_type' => 'config'];
    }
    
    require_once __DIR__ . '/../vendor/autoload.php';
    
    if (!class_exists('RouterOS\Client')) {
        debugLog("ERROR: RouterOS\\Client class not found");
        error_log("MikroTik API: RouterOS\Client class not found. Run 'composer require evilfreelancer/routeros-api-php'");
        return ['success' => false, 'error' => 'API class not found', 'error_type' => 'config'];
    }
    
    try {
        debugLog("Creating RouterOS API client...");
        $client = new \RouterOS\Client([
            'host' => $host,
            'user' => $user,
            'pass' => $config['mikrotik']['pass'],
            'port' => $port,
            'timeout' => 10
        ]);
        debugLog("✓ RouterOS API connected successfully");
        
        // Use Room Number as MikroTik Username
        $mtUsername = 'room' . $roomNumber;
        debugLog("MikroTik username: $mtUsername");
        
        // =========================================================
        // CHECK CONCURRENT SESSION LIMIT BEFORE PROCEEDING
        // =========================================================
        // Check if this device (MAC) already has an active session
        $deviceAlreadyActive = false;
        if ($macAddress) {
            try {
                $macCheckQuery = new \RouterOS\Query('/ip/hotspot/active/print');
                $macCheckQuery->where('mac-address', $macAddress);
                $existingMacSession = $client->query($macCheckQuery)->read();
                if (!empty($existingMacSession)) {
                    debugLog("Device already has active session - allowing reconnect");
                    $deviceAlreadyActive = true;
                }
            } catch (Exception $e) {
                debugLog("Could not check device session: " . $e->getMessage());
            }
        }
        
        // Only check session limit if this is a NEW device connection
        if (!$deviceAlreadyActive) {
            try {
                $activeQuery = new \RouterOS\Query('/ip/hotspot/active/print');
                $activeQuery->where('user', $mtUsername);
                $activeSessions = $client->query($activeQuery)->read();
                $activeCount = count($activeSessions);
                
                debugLog("Active sessions for $mtUsername: $activeCount / $maxSessions max");
                
                if ($activeCount >= $maxSessions) {
                    debugLog("SESSION LIMIT REACHED - blocking new device");
                    error_log("MikroTik: Session limit reached for $mtUsername ($activeCount/$maxSessions)");
                    return [
                        'success' => false, 
                        'error' => "You have reached the maximum of $maxSessions devices connected at the same time. Please disconnect one of your devices and wait a few minutes before connecting this new device.", 
                        'error_type' => 'session_limit',
                        'active_count' => $activeCount,
                        'max_sessions' => $maxSessions
                    ];
                }
            } catch (Exception $e) {
                debugLog("Could not check session count: " . $e->getMessage());
                // On error, allow login attempt - MikroTik will block if needed
            }
        }
        
        // Check if user already exists
        debugLog("Checking if user exists...");
        $query = new \RouterOS\Query('/ip/hotspot/user/print');
        $query->where('name', $mtUsername);
        $existingUser = $client->query($query)->read();
        debugLog("User query result", $existingUser);
        
        if (empty($existingUser)) {
            // Create new user (no MAC binding - allows multiple devices)
            debugLog("Creating NEW hotspot user...");
            $addQuery = new \RouterOS\Query('/ip/hotspot/user/add');
            $addQuery->equal('name', $mtUsername);
            $addQuery->equal('password', $surname);
            $addQuery->equal('profile', $profile);
            $addQuery->equal('comment', 'Created via WiFi Portal - ' . date('Y-m-d H:i:s'));
            $result = $client->query($addQuery)->read();
            debugLog("User creation result", $result);
            error_log("MikroTik: Created user $mtUsername with profile $profile");
        } else {
            // Update existing user profile and password
            // Clear mac-address to allow multiple devices (MikroTik only allows 1 MAC per user)
            debugLog("Updating EXISTING hotspot user (ID: " . $existingUser[0]['.id'] . ")");
            $setQuery = new \RouterOS\Query('/ip/hotspot/user/set');
            $setQuery->equal('.id', $existingUser[0]['.id']);
            $setQuery->equal('profile', $profile);
            $setQuery->equal('password', $surname);
            $setQuery->equal('mac-address', ''); // Clear MAC binding to allow multiple devices
            $result = $client->query($setQuery)->read();
            debugLog("User update result", $result);
            error_log("MikroTik: Updated user $mtUsername to profile $profile (MAC cleared for multi-device)");
        }
        
        // =========================================================
        // AUTHORIZE DEVICE - Create active session WITH profile
        // =========================================================
        // We need to create an active hotspot session so the profile 
        // (rate limits) are applied. NO bypassed IP binding!
        debugLog("=== AUTHORIZE DEVICE WITH PROFILE ===");
        debugLog("ClientIP: $clientIP, MAC: $macAddress, Profile: $profile");
        
        if ($clientIP && $macAddress) {
            // Step 1: Remove any existing bypassed IP bindings for this MAC
            // (cleanup from previous code versions)
            try {
                $bindQuery = new \RouterOS\Query('/ip/hotspot/ip-binding/print');
                $bindQuery->where('mac-address', $macAddress);
                $existingBinding = $client->query($bindQuery)->read();
                
                if (!empty($existingBinding)) {
                    debugLog("Removing old IP binding for MAC: $macAddress");
                    $removeQuery = new \RouterOS\Query('/ip/hotspot/ip-binding/remove');
                    $removeQuery->equal('.id', $existingBinding[0]['.id']);
                    $client->query($removeQuery)->read();
                    debugLog("Old binding removed");
                }
            } catch (Exception $e) {
                debugLog("Note: Could not check/remove old bindings: " . $e->getMessage());
            }
            
            // Step 2: Remove any existing active session for this MAC
            // (to ensure fresh session with correct profile)
            try {
                $activeQuery = new \RouterOS\Query('/ip/hotspot/active/print');
                $activeQuery->where('mac-address', $macAddress);
                $existingActive = $client->query($activeQuery)->read();
                debugLog("Existing active sessions for MAC", $existingActive);
                
                if (!empty($existingActive)) {
                    debugLog("Removing existing active session to apply new profile...");
                    $removeActiveQuery = new \RouterOS\Query('/ip/hotspot/active/remove');
                    $removeActiveQuery->equal('.id', $existingActive[0]['.id']);
                    $client->query($removeActiveQuery)->read();
                    debugLog("Existing session removed");
                }
            } catch (Exception $e) {
                debugLog("Note: Could not check/remove active sessions: " . $e->getMessage());
            }
            
            // Step 3: Create active session using MikroTik's login mechanism
            // Method A: Try /ip/hotspot/active/login (RouterOS 6.45+)
            $sessionCreated = false;
            
            try {
                debugLog("Attempting /ip/hotspot/active/login...");
                $loginQuery = new \RouterOS\Query('/ip/hotspot/active/login');
                $loginQuery->equal('user', $mtUsername);
                $loginQuery->equal('password', $surname);
                $loginQuery->equal('ip', $clientIP);
                $loginQuery->equal('mac-address', $macAddress);
                $loginResult = $client->query($loginQuery)->read();
                debugLog("Login result", $loginResult);
                $sessionCreated = true;
                debugLog("✓ Active session created with profile: $profile");
                error_log("MikroTik: Session created for $mtUsername ($macAddress) with profile $profile");
                
            } catch (Exception $loginEx) {
                debugLog("Method A failed: " . $loginEx->getMessage());
                // API-based session creation not available on this RouterOS version
                // Session will be created via browser-based login in alogin.html
                // 
                // NOTE: We intentionally do NOT bind MAC to the user because:
                // 1. MikroTik only allows ONE mac-address per hotspot user
                // 2. Binding a new MAC would disconnect previous devices
                // 3. We need multiple devices per room to work independently
                debugLog("Browser-based login will be used to create session with profile");
            }
            
            if (!$sessionCreated) {
                debugLog("⚠ Could not create active session automatically");
                debugLog("Guest will see MikroTik login page - credentials are ready: $mtUsername / $surname");
            }
            
        } else {
            debugLog("⚠ Cannot authorize device - missing client IP or MAC");
            debugLog("Client IP: " . ($clientIP ?: "MISSING"));
            debugLog("MAC: " . ($macAddress ?: "MISSING"));
        }
        
        debugLog("=== MIKROTIK CONFIGURATION COMPLETE ===");
        return ['success' => true, 'error' => null, 'error_type' => null];
        
    } catch (Exception $e) {
        debugLog("EXCEPTION: " . $e->getMessage());
        debugLog("Stack trace", $e->getTraceAsString());
        error_log("MikroTik API Error: " . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage(), 'error_type' => 'exception'];
    }
}

/**
 * Get client MAC address from MikroTik
 * When using external login page, MikroTik passes MAC via URL params to login page,
 * which then forwards it via POST. Values like $(mac) mean it wasn't substituted.
 */
function getClientMAC() {
    debugLog("=== GET CLIENT MAC ===");
    
    // Method 1: From POST (login form with URL param values)
    if (isset($_POST['client_mac'])) {
        $mac = $_POST['client_mac'];
        debugLog("POST client_mac raw value: $mac");
        
        // Check if it's a real MAC (not a template variable like $(mac))
        if (preg_match('/^([0-9A-F]{2}[:-]){5}([0-9A-F]{2})$/i', $mac)) {
            debugLog("✓ Valid MAC from POST: $mac");
            return strtoupper(str_replace('-', ':', $mac));
        } else {
            debugLog("✗ POST client_mac is not a valid MAC (template not substituted): $mac");
        }
    }
    
    // Method 2: From GET params (direct MikroTik redirect)
    if (isset($_GET['mac'])) {
        $mac = $_GET['mac'];
        debugLog("GET mac raw value: $mac");
        
        if (preg_match('/^([0-9A-F]{2}[:-]){5}([0-9A-F]{2})$/i', $mac)) {
            debugLog("✓ Valid MAC from GET: $mac");
            return strtoupper(str_replace('-', ':', $mac));
        }
    }
    
    // Method 3: HTTP headers (if MikroTik configured to pass via proxy)
    if (isset($_SERVER['HTTP_X_MAC_ADDRESS'])) {
        $mac = $_SERVER['HTTP_X_MAC_ADDRESS'];
        if (preg_match('/^([0-9A-F]{2}[:-]){5}([0-9A-F]{2})$/i', $mac)) {
            debugLog("✓ Valid MAC from HTTP header: $mac");
            return strtoupper(str_replace('-', ':', $mac));
        }
    }
    
    // No valid MAC found - return null (will be handled by caller)
    debugLog("✗ No valid MAC address found from any source");
    return null;
}

/**
 * Get client IP address from MikroTik
 * When using external login page, MikroTik passes IP via URL params to login page.
 */
function getClientIP() {
    debugLog("=== GET CLIENT IP ===");
    
    // Method 1: From POST (login form with URL param values)
    if (isset($_POST['client_ip'])) {
        $ip = $_POST['client_ip'];
        debugLog("POST client_ip raw value: $ip");
        
        // Check if it's a real IP (not a template variable like $(ip))
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            debugLog("✓ Valid IP from POST: $ip");
            return $ip;
        } else {
            debugLog("✗ POST client_ip is not a valid IP (template not substituted): $ip");
        }
    }
    
    // Method 2: From GET params (direct MikroTik redirect)
    if (isset($_GET['ip'])) {
        $ip = $_GET['ip'];
        debugLog("GET ip raw value: $ip");
        
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            debugLog("✓ Valid IP from GET: $ip");
            return $ip;
        }
    }
    
    // Method 3: HTTP headers
    $headers = ['HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_CLIENT_IP'];
    foreach ($headers as $header) {
        if (isset($_SERVER[$header])) {
            $ip = $_SERVER[$header];
            // Handle comma-separated list (X-Forwarded-For can have multiple)
            if (strpos($ip, ',') !== false) {
                $ip = trim(explode(',', $ip)[0]);
            }
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                debugLog("✓ Valid IP from $header: $ip");
                return $ip;
            }
        }
    }
    
    // Fallback to REMOTE_ADDR (but this is likely the web server IP for external login)
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    debugLog("Fallback REMOTE_ADDR: $ip (likely web server IP, not client)");
    
    return null; // Return null to indicate we don't have the real client IP
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
    
    // Get actual MAC address (may be null if MikroTik didn't pass it)
    $mac_address = getClientMAC();
    $clientIP = getClientIP();
    
    debugLog("=== CLIENT INFO ===");
    debugLog("MAC from MikroTik: " . ($mac_address ?? "NOT AVAILABLE"));
    debugLog("IP from MikroTik: " . ($clientIP ?? "NOT AVAILABLE"));
    
    // If no valid MAC, we can still proceed but won't track device or create IP binding
    // The guest will need to login via MikroTik hotspot with the created credentials
    $hasValidMAC = false;
    if ($mac_address) {
        // Normalize MAC to uppercase
        $mac_address = strtoupper($mac_address);
        
        // Validate MAC address format
        if (preg_match('/^([0-9A-F]{2}:){5}[0-9A-F]{2}$/i', $mac_address)) {
            $hasValidMAC = true;
            debugLog("✓ Valid MAC address: $mac_address");
        } else {
            debugLog("✗ Invalid MAC format, ignoring: $mac_address");
            $mac_address = null;
        }
    }
    
    // Connect to database
    $pdo = connectDB($db_config);
    
    // Look up the Mews room_id from database using room number
    $room_id = getRoomId($pdo, $username);
    if (!$room_id) {
        error_log("Room not found in database: $username");
        redirectToError("Room not found. Please contact reception.", $lang);
    }
    
    // Initialize Mews authentication
    $mews_auth = new MewsWifiAuth($mews_environment);
    
    // Validate guest credentials against Mews PMS using room_id
    $guest = validateGuest($mews_auth, $room_id, $username, $surname);
    if (!$guest) {
        redirectToError("Invalid room number or surname. Please check your reservation details or contact reception.", $lang);
    }
    
    // Device limit is handled by MikroTik via shared-users setting on hotspot user profile
    // We just track devices for admin visibility, no hard limit enforced here
    debugLog("Device tracking: MAC=" . ($mac_address ?? "N/A") . " (limit enforced by MikroTik shared-users)");
    
    // Map speed selection to MikroTik profile and numeric speed for database
    $profileMap = $db_config['mikrotik']['profiles'] ?? [
        'normal' => 'hm_10M',
        'fast'   => 'hm_20M'
    ];
    $selectedProfile = $profileMap[$wifi_speed] ?? $profileMap['normal'];
    
    // Store numeric speed in database (10 Mbps for normal, 20 Mbps for fast)
    $numericSpeed = ($wifi_speed === 'fast') ? 20 : 10;
    
    debugLog("=== AUTHENTICATION SUMMARY ===");
    debugLog("Room: $username, Surname: $surname");
    debugLog("MAC Address: " . ($mac_address ?? "NOT AVAILABLE"));
    debugLog("Client IP: " . ($clientIP ?? "NOT AVAILABLE"));
    debugLog("Speed: $wifi_speed -> Profile: $selectedProfile ($numericSpeed Mbps)");
    
    // Configure MikroTik hotspot user (and session if MAC/IP available)
    $mikrotikResult = configureMikroTikUser($db_config, $username, $surname, $selectedProfile, $mac_address, $clientIP);
    debugLog("MikroTik configuration result", $mikrotikResult);
    
    // Handle MikroTik result
    if (!$mikrotikResult['success'] && ($db_config['mikrotik']['enabled'] ?? false)) {
        // Check for specific error types
        if ($mikrotikResult['error_type'] === 'session_limit') {
            // Session limit reached - show friendly error
            debugLog("ERROR: Session limit reached - showing user-friendly message");
            redirectToError($mikrotikResult['error'], $lang);
        } else {
            // Other MikroTik errors
            debugLog("ERROR: MikroTik failed and is enabled - aborting");
            redirectToError("WiFi system temporarily unavailable. Please try again or contact reception.", $lang);
        }
    }
    
    // Register the device in database for admin tracking (only if valid MAC)
    if ($hasValidMAC) {
        registerDevice($pdo, $mac_address, $username, $surname, $numericSpeed);
        debugLog("Device registered in database");
    } else {
        debugLog("Skipping device registration - no valid MAC available");
    }
    
    // Update room cleaning preference based on speed selection
    if ($wifi_speed === 'fast') {
        updateRoomSkipPreference($pdo, $username, $surname, true);
        debugLog("Room skip preference set to TRUE (fast WiFi selected)");
    } else {
        updateRoomSkipPreference($pdo, $username, $surname, false);
    }
    
    // Log the successful authentication
    debugLog("=== AUTHENTICATION COMPLETE - SUCCESS ===");
    if ($hasValidMAC) {
        debugLog("✓ MikroTik user created, session may have been activated via API");
    } else {
        debugLog("⚠ MikroTik user created but NO session activated (missing MAC/IP)");
        debugLog("Guest will need to login via MikroTik hotspot with credentials: room$username / $surname");
    }
    error_log("WiFi access granted - Room: $username, Device: " . ($mac_address ?? "unknown") . ", IP: " . ($clientIP ?? "unknown") . ", Speed: {$numericSpeed} Mbps, Profile: $selectedProfile");
    
    // Redirect to success page
    // If we couldn't create a session via API, the success page will try browser-based login
    redirectToSuccess($linkLogin, $username, $surname, $lang, $numericSpeed);
    
} catch (Exception $e) {
    debugLog("EXCEPTION: " . $e->getMessage());
    error_log("Authentication error: " . $e->getMessage());
    redirectToError("An error occurred during authentication. Please try again.", $lang);
}
?>