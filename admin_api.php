<?php
/**
 * MOA Hotel WiFi Management API
 * Provides API endpoints for the admin interface
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type');

// Include configuration and Mews integration
$db_config = require 'config.php';
require_once 'mews_wifi_auth.php';

/**
 * Connect to database
 */
function connectDB($config) {
    try {
        $pdo = new PDO(
            "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
            $config['username'],
            $config['password'],
            $config['options']
        );
        return $pdo;
    } catch (PDOException $e) {
        throw new Exception("Database connection failed: " . $e->getMessage());
    }
}

/**
 * Send JSON response
 */
function sendResponse($success, $data = null, $error = null) {
    $response = ['success' => $success];
    
    if ($data !== null) {
        $response = array_merge($response, $data);
    }
    
    if ($error !== null) {
        $response['error'] = $error;
    }
    
    echo json_encode($response);
    exit;
}

/**
 * Get all authorized devices
 */
function getDevices($pdo) {
    $stmt = $pdo->query("
        SELECT 
            device_mac,
            room_number,
            surname,
            fast_mode,
            last_update,
            created_at
        FROM authorized_devices 
        ORDER BY last_update DESC
    ");
    
    $devices = $stmt->fetchAll();
    
    // Convert boolean values for JSON
    foreach ($devices as &$device) {
        $device['fast_mode'] = (bool)$device['fast_mode'];
    }
    
    return $devices;
}

/**
 * Get room information with device counts (enhanced with Mews data)
 */
function getRooms($pdo) {
    // Get rooms with cleaning skip preferences and device counts
    $stmt = $pdo->query("
        SELECT DISTINCT
            r.room_number,
            r.guest_surname,
            r.skip_clean,
            r.updated_at,
            COUNT(d.device_mac) as device_count,
            SUM(CASE WHEN d.fast_mode = 1 THEN 1 ELSE 0 END) as fast_device_count
        FROM rooms_to_skip r
        LEFT JOIN authorized_devices d ON r.room_number = d.room_number
        GROUP BY r.room_number, r.guest_surname, r.skip_clean, r.updated_at
        ORDER BY r.room_number
    ");
    
    $rooms = $stmt->fetchAll();
    
    // Get current reservations from Mews
    try {
        $mews_config = require 'mews_config.php';
        $mews_auth = new MewsWifiAuth($mews_config['mews']['environment']);
        $mews_reservations = $mews_auth->getTodaysReservations();
        
        // Create a map of Mews reservations by room number
        $mews_room_map = [];
        foreach ($mews_reservations as $reservation) {
            $mews_room_map[$reservation['room_number']] = $reservation;
        }
        
        // Enhance rooms data with Mews information
        foreach ($rooms as &$room) {
            if (isset($mews_room_map[$room['room_number']])) {
                $room['mews_data'] = $mews_room_map[$room['room_number']];
                $room['has_active_reservation'] = true;
            } else {
                $room['has_active_reservation'] = false;
            }
        }
        
    } catch (Exception $e) {
        error_log("Error getting Mews reservations: " . $e->getMessage());
        // Continue without Mews data
    }
    
    // Convert boolean values for JSON
    foreach ($rooms as &$room) {
        $room['skip_clean'] = (bool)$room['skip_clean'];
        $room['device_count'] = (int)$room['device_count'];
        $room['fast_device_count'] = (int)$room['fast_device_count'];
    }
    
    return $rooms;
}

/**
 * Toggle device speed mode
 */
function toggleDeviceSpeed($pdo, $mac) {
    // Get current device info
    $stmt = $pdo->prepare("SELECT * FROM authorized_devices WHERE device_mac = ?");
    $stmt->execute([$mac]);
    $device = $stmt->fetch();
    
    if (!$device) {
        throw new Exception("Device not found");
    }
    
    $newFastMode = !$device['fast_mode'];
    
    // If switching to fast mode, check device limit for the room
    if ($newFastMode) {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count 
            FROM authorized_devices 
            WHERE room_number = ? AND fast_mode = TRUE AND device_mac != ?
        ");
        $stmt->execute([$device['room_number'], $mac]);
        $currentFastDevices = $stmt->fetch()['count'];
        
        if ($currentFastDevices >= 3) {
            throw new Exception("Room already has maximum of 3 fast devices");
        }
    }
    
    // Update device
    $stmt = $pdo->prepare("
        UPDATE authorized_devices 
        SET fast_mode = ?, last_update = CURRENT_TIMESTAMP 
        WHERE device_mac = ?
    ");
    $stmt->execute([$newFastMode, $mac]);
    
    return true;
}

/**
 * Remove device from authorized list
 */
function removeDevice($pdo, $mac) {
    $stmt = $pdo->prepare("DELETE FROM authorized_devices WHERE device_mac = ?");
    $result = $stmt->execute([$mac]);
    
    if ($stmt->rowCount() === 0) {
        throw new Exception("Device not found or already removed");
    }
    
    return true;
}

/**
 * Get statistics
 */
function getStatistics($pdo) {
    // Total devices
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM authorized_devices");
    $totalDevices = $stmt->fetch()['count'];
    
    // Fast devices
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM authorized_devices WHERE fast_mode = TRUE");
    $fastDevices = $stmt->fetch()['count'];
    
    // Active rooms
    $stmt = $pdo->query("SELECT COUNT(DISTINCT room_number) as count FROM authorized_devices");
    $activeRooms = $stmt->fetch()['count'];
    
    // Rooms skipping clean
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM rooms_to_skip WHERE skip_clean = TRUE");
    $skipCleanRooms = $stmt->fetch()['count'];
    
    return [
        'total_devices' => (int)$totalDevices,
        'fast_devices' => (int)$fastDevices,
        'active_rooms' => (int)$activeRooms,
        'skip_clean_rooms' => (int)$skipCleanRooms
    ];
}

/**
 * Get Mews PMS system status
 */
function getMewsStatus() {
    try {
        $mews_config = require 'mews_config.php';
        $mews_auth = new MewsWifiAuth($mews_config['mews']['environment']);
        
        // Try to get environment info
        $env_info = $mews_auth->getEnvironmentInfo();
        
        // Try to get today's reservations to test connectivity
        $reservations = $mews_auth->getTodaysReservations();
        
        return [
            'status' => 'connected',
            'environment' => $env_info['environment'],
            'reservations_count' => count($reservations),
            'last_check' => date('Y-m-d H:i:s'),
            'api_responsive' => true
        ];
        
    } catch (Exception $e) {
        return [
            'status' => 'error',
            'environment' => 'unknown',
            'error_message' => $e->getMessage(),
            'last_check' => date('Y-m-d H:i:s'),
            'api_responsive' => false
        ];
    }
}

// Main request handling
try {
    $pdo = connectDB($db_config);
    
    $method = $_SERVER['REQUEST_METHOD'];
    $action = $_GET['action'] ?? null;
    
    if ($method === 'GET') {
        switch ($action) {
            case 'get_devices':
                $devices = getDevices($pdo);
                sendResponse(true, ['devices' => $devices]);
                break;
                
            case 'get_rooms':
                $rooms = getRooms($pdo);
                sendResponse(true, ['rooms' => $rooms]);
                break;
                
            case 'get_statistics':
                $stats = getStatistics($pdo);
                sendResponse(true, ['statistics' => $stats]);
                break;
                
            case 'get_mews_status':
                $mews_status = getMewsStatus();
                sendResponse(true, ['mews_status' => $mews_status]);
                break;
                
            default:
                sendResponse(false, null, 'Invalid action');
        }
        
    } elseif ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!$input) {
            sendResponse(false, null, 'Invalid JSON input');
        }
        
        $action = $input['action'] ?? null;
        
        switch ($action) {
            case 'toggle_device_speed':
                $mac = $input['mac'] ?? null;
                if (!$mac) {
                    sendResponse(false, null, 'MAC address required');
                }
                
                toggleDeviceSpeed($pdo, $mac);
                sendResponse(true, ['message' => 'Device speed toggled successfully']);
                break;
                
            case 'remove_device':
                $mac = $input['mac'] ?? null;
                if (!$mac) {
                    sendResponse(false, null, 'MAC address required');
                }
                
                removeDevice($pdo, $mac);
                sendResponse(true, ['message' => 'Device removed successfully']);
                break;
                
            default:
                sendResponse(false, null, 'Invalid action');
        }
        
    } else {
        sendResponse(false, null, 'Method not allowed');
    }
    
} catch (Exception $e) {
    error_log("Admin API Error: " . $e->getMessage());
    sendResponse(false, null, $e->getMessage());
}
?>