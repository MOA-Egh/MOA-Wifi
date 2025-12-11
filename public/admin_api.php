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
$db_config = require __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/MewsWifiAuth.php';

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
            mac_address as device_mac,
            room_number,
            last_name as surname,
            speed,
            updated_at as last_update,
            created_at
        FROM authorized_devices 
        ORDER BY updated_at DESC
    ");
    
    $devices = $stmt->fetchAll();
    
    // Convert speed to integer
    foreach ($devices as &$device) {
        $device['speed'] = (int)$device['speed'];
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
            COUNT(d.mac_address) as device_count
        FROM rooms_to_skip r
        LEFT JOIN authorized_devices d ON r.room_number = d.room_number
        GROUP BY r.room_number, r.guest_surname, r.skip_clean, r.updated_at
        ORDER BY r.room_number
    ");
    
    $rooms = $stmt->fetchAll();
    
    // Get current reservations from Mews
    try {
        $mews_config = require __DIR__ . '/../config/mews_config.php';
        $mews_auth = new MewsWifiAuth($mews_config['mews']['environment']);
        $mews_reservations = $mews_auth->getTodaysReservations();
        
        // Get room number lookup from database (resource_id -> room_number)
        $roomLookup = [];
        $roomStmt = $pdo->query("SELECT id, name FROM rooms");
        while ($row = $roomStmt->fetch()) {
            $roomLookup[$row['id']] = $row['name'];
        }
        
        // Create a map of Mews reservations by room number
        $mews_room_map = [];
        foreach ($mews_reservations as $reservation) {
            $resourceId = $reservation['resource_id'] ?? null;
            if ($resourceId && isset($roomLookup[$resourceId])) {
                $roomNumber = $roomLookup[$resourceId];
                $mews_room_map[$roomNumber] = $reservation;
                $mews_room_map[$roomNumber]['room_number'] = $roomNumber;
            }
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
    
    // Convert values for JSON
    foreach ($rooms as &$room) {
        $room['skip_clean'] = (bool)$room['skip_clean'];
        $room['device_count'] = (int)$room['device_count'];
    }
    
    return $rooms;
}

/**
 * Update device speed
 */
function updateDeviceSpeed($pdo, $mac, $speed) {
    // Validate speed value
    $speed = (int)$speed;
    if ($speed < 1 || $speed > 100) {
        throw new Exception("Speed must be between 1 and 100 Mbps");
    }
    
    // Get current device info
    $stmt = $pdo->prepare("SELECT * FROM authorized_devices WHERE mac_address = ?");
    $stmt->execute([$mac]);
    $device = $stmt->fetch();
    
    if (!$device) {
        throw new Exception("Device not found");
    }
    
    // Update device speed
    $stmt = $pdo->prepare("
        UPDATE authorized_devices 
        SET speed = ?, updated_at = CURRENT_TIMESTAMP 
        WHERE mac_address = ?
    ");
    $stmt->execute([$speed, $mac]);
    
    return true;
}

/**
 * Remove device from authorized list
 */
function removeDevice($pdo, $mac) {
    $stmt = $pdo->prepare("DELETE FROM authorized_devices WHERE mac_address = ?");
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
    
    // Devices by speed
    $stmt = $pdo->query("SELECT speed, COUNT(*) as count FROM authorized_devices GROUP BY speed ORDER BY speed");
    $devicesBySpeed = $stmt->fetchAll();
    
    // Active rooms
    $stmt = $pdo->query("SELECT COUNT(DISTINCT room_number) as count FROM authorized_devices");
    $activeRooms = $stmt->fetch()['count'];
    
    // Rooms skipping clean
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM rooms_to_skip WHERE skip_clean = TRUE");
    $skipCleanRooms = $stmt->fetch()['count'];
    
    return [
        'total_devices' => (int)$totalDevices,
        'devices_by_speed' => $devicesBySpeed,
        'active_rooms' => (int)$activeRooms,
        'skip_clean_rooms' => (int)$skipCleanRooms
    ];
}

/**
 * Get Mews PMS system status
 */
function getMewsStatus() {
    try {
        $mews_config = require __DIR__ . '/../config/mews_config.php';
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
            case 'update_device_speed':
                $mac = $input['mac'] ?? null;
                $speed = $input['speed'] ?? null;
                if (!$mac) {
                    sendResponse(false, null, 'MAC address required');
                }
                if ($speed === null) {
                    sendResponse(false, null, 'Speed value required');
                }
                
                updateDeviceSpeed($pdo, $mac, $speed);
                sendResponse(true, ['message' => 'Device speed updated successfully']);
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