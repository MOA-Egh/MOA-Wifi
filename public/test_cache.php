<?php
/**
 * Mews Reservation Cache Test Script
 * 
 * Tests the caching flow step-by-step with clear console output
 * Access via: http://localhost/MOA-Wifi/public/test_cache.php
 */

// Set content type for clean output
header('Content-Type: text/plain; charset=utf-8');

echo "╔══════════════════════════════════════════════════════════════════╗\n";
echo "║           MEWS RESERVATION CACHE TEST SCRIPT                    ║\n";
echo "╚══════════════════════════════════════════════════════════════════╝\n\n";

// ============================================================================
// STEP 1: Load dependencies
// ============================================================================
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "STEP 1: Loading dependencies...\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

require_once __DIR__ . '/../src/MewsWifiAuth.php';
$db_config = require __DIR__ . '/../config/config.php';
$mews_config = require __DIR__ . '/../config/mews_config.php';

echo "  ✓ MewsWifiAuth loaded\n";
echo "  ✓ Database config loaded\n";
echo "  ✓ Mews config loaded\n\n";

// ============================================================================
// STEP 2: Connect to database
// ============================================================================
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "STEP 2: Connecting to database...\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

try {
    $dsn = "mysql:host={$db_config['host']};dbname={$db_config['database']};charset=utf8";
    $pdo = new PDO(
        $dsn,
        $db_config['username'],
        $db_config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    echo "  ✓ Database connected: {$db_config['database']}\n\n";
} catch (PDOException $e) {
    echo "  ✗ Database connection failed: " . $e->getMessage() . "\n";
    exit(1);
}

// ============================================================================
// STEP 3: Check if cache tables exist
// ============================================================================
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "STEP 3: Checking cache tables...\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

$tables = ['cached_reservations', 'system_settings', 'rooms'];
foreach ($tables as $table) {
    try {
        $result = $pdo->query("SELECT COUNT(*) as cnt FROM $table");
        $count = $result->fetch(PDO::FETCH_ASSOC)['cnt'];
        echo "  ✓ Table '$table' exists ($count rows)\n";
    } catch (PDOException $e) {
        echo "  ✗ Table '$table' MISSING - Run database/add_reservation_cache.sql\n";
    }
}
echo "\n";

// ============================================================================
// STEP 4: Show current cache state
// ============================================================================
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "STEP 4: Current cache state...\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

try {
    // Get last bulk fetch time
    $stmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'last_bulk_fetch'");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $lastBulkFetch = $result ? $result['setting_value'] : 'Never';
    
    $timeSince = $result ? (time() - strtotime($result['setting_value'])) : 999999;
    $timeSinceStr = $timeSince < 3600 ? round($timeSince / 60) . " minutes ago" : round($timeSince / 3600, 1) . " hours ago";
    
    echo "  Last bulk fetch: $lastBulkFetch ($timeSinceStr)\n";
    echo "  Bulk fetch interval: 1 hour (3600 seconds)\n";
    echo "  Next bulk fetch: " . ($timeSince >= 3600 ? "NOW (on next request)" : "in " . round((3600 - $timeSince) / 60) . " minutes") . "\n\n";
    
    // Show cached reservations
    $stmt = $pdo->query("SELECT room_number, surname, check_in, check_out FROM cached_reservations ORDER BY room_number");
    $cached = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($cached) > 0) {
        echo "  Cached reservations:\n";
        echo "  ┌────────────┬────────────────┬────────────┬────────────┐\n";
        echo "  │ Room       │ Surname        │ Check-in   │ Check-out  │\n";
        echo "  ├────────────┼────────────────┼────────────┼────────────┤\n";
        foreach ($cached as $row) {
            printf("  │ %-10s │ %-14s │ %-10s │ %-10s │\n", 
                $row['room_number'], 
                substr($row['surname'], 0, 14),
                $row['check_in'],
                $row['check_out']
            );
        }
        echo "  └────────────┴────────────────┴────────────┴────────────┘\n";
    } else {
        echo "  Cached reservations: (none)\n";
    }
    echo "\n";
} catch (PDOException $e) {
    echo "  ✗ Error reading cache: " . $e->getMessage() . "\n\n";
}

// ============================================================================
// STEP 5: Initialize MewsWifiAuth with database
// ============================================================================
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "STEP 5: Initializing MewsWifiAuth...\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

$mews_environment = $mews_config['mews']['environment'] ?? 'demo';
echo "  Environment: $mews_environment\n";

$mews_auth = new MewsWifiAuth($mews_environment);
$mews_auth->setDatabase($pdo);
echo "  ✓ MewsWifiAuth initialized with database connection\n\n";

// ============================================================================
// STEP 6: Fetch all Mews resources (rooms)
// ============================================================================
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "STEP 6: Fetching all resources from Mews API...\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

try {
    // Create a direct MewsConnector to access getResources
    require_once __DIR__ . '/../src/MewsConnector.php';
    $mews = new MewsConnector($mews_environment);
    
    $startTime = microtime(true);
    $resourcesResponse = $mews->getResources();
    $duration = round((microtime(true) - $startTime) * 1000);
    
    echo "  API call completed in {$duration}ms\n\n";
    
    if (isset($resourcesResponse['Resources']) && count($resourcesResponse['Resources']) > 0) {
        $resources = $resourcesResponse['Resources'];
        echo "  Found " . count($resources) . " resources:\n\n";
        
        echo "  ┌──────────────────────────────────────┬────────────┬──────────┬─────────────┐\n";
        echo "  │ Resource ID                          │ Name       │ State    │ Type        │\n";
        echo "  ├──────────────────────────────────────┼────────────┼──────────┼─────────────┤\n";
        
        foreach ($resources as $resource) {
            $id = $resource['Id'] ?? 'N/A';
            $name = $resource['Name'] ?? 'N/A';
            $state = $resource['State'] ?? 'N/A';
            $type = $resource['Type'] ?? 'N/A';
            
            printf("  │ %-36s │ %-10s │ %-8s │ %-11s │\n",
                substr($id, 0, 36),
                substr($name, 0, 10),
                substr($state, 0, 8),
                substr($type, 0, 11)
            );
        }
        echo "  └──────────────────────────────────────┴────────────┴──────────┴─────────────┘\n";
    } else {
        echo "  No resources found in Mews response\n";
        if (isset($resourcesResponse)) {
            echo "  Response keys: " . implode(', ', array_keys($resourcesResponse)) . "\n";
        }
    }
    echo "\n";
    
} catch (Exception $e) {
    echo "  ✗ Error fetching resources: " . $e->getMessage() . "\n\n";
}

// ============================================================================
// STEP 7: Get a test room from database
// ============================================================================
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "STEP 7: Getting test room from database...\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

$stmt = $pdo->query("SELECT id, name FROM rooms LIMIT 5");
$rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (count($rooms) === 0) {
    echo "  ✗ No rooms in database. Run sync_rooms.php first.\n";
    exit(1);
}

echo "  Available rooms:\n";
foreach ($rooms as $room) {
    echo "    - Room {$room['name']} (ID: {$room['id']})\n";
}

// Use first room for testing
$testRoom = $rooms[0];
echo "\n  Using room {$testRoom['name']} for test\n\n";

// ============================================================================
// STEP 8: Test cache lookup (likely miss on first run)
// ============================================================================
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "STEP 8: Testing guest validation (with caching)...\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

// Test with a fake surname first to see cache behavior
$testSurname = "TestGuest" . rand(1000, 9999);
echo "  Testing with: Room={$testRoom['name']}, Surname=$testSurname\n";
echo "  (This should trigger cache lookup, then API call)\n\n";

echo "  ┌─ VALIDATION FLOW ─────────────────────────────────────────────┐\n";

// Check what will happen
$stmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'last_bulk_fetch'");
$result = $stmt->fetch(PDO::FETCH_ASSOC);
$lastFetch = $result ? $result['setting_value'] : '1970-01-01 00:00:00';
$timeSince = time() - strtotime($lastFetch);

echo "  │ 1. Check cache for room+surname combination...               │\n";
echo "  │    → Cache check will be performed                           │\n";
echo "  │                                                              │\n";

if ($timeSince >= 3600) {
    echo "  │ 2. Last bulk fetch: " . round($timeSince / 3600, 1) . " hours ago (> 1 hour)          │\n";
    echo "  │    → Will trigger BULK FETCH of all reservations           │\n";
    echo "  │    → Cache all results                                     │\n";
    echo "  │    → Then lookup in refreshed cache                        │\n";
} else {
    echo "  │ 2. Last bulk fetch: " . round($timeSince / 60) . " minutes ago (< 1 hour)           │\n";
    echo "  │    → Will do INDIVIDUAL API lookup (2 API calls)           │\n";
    echo "  │    → Cache result if valid                                 │\n";
}
echo "  └──────────────────────────────────────────────────────────────┘\n\n";

echo "  Calling validateGuest()...\n";
echo "  (Check logs/auth_debug.log for detailed API activity)\n\n";

$startTime = microtime(true);
$result = $mews_auth->validateGuest($testRoom['id'], $testRoom['name'], $testSurname);
$duration = round((microtime(true) - $startTime) * 1000);

if ($result) {
    echo "  ✓ Guest validated in {$duration}ms\n";
    echo "    Room: {$result['room_number']}\n";
    echo "    Surname: {$result['guest_surname']}\n";
    echo "    Check-in: {$result['check_in']}\n";
    echo "    Check-out: {$result['check_out']}\n";
    echo "    From cache: " . (isset($result['from_cache']) && $result['from_cache'] ? "YES" : "NO") . "\n";
} else {
    echo "  ✗ Guest not found (expected for random test surname)\n";
    echo "    Duration: {$duration}ms\n";
}
echo "\n";

// ============================================================================
// STEP 9: Show updated cache state
// ============================================================================
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "STEP 9: Updated cache state after test...\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

try {
    $stmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'last_bulk_fetch'");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $lastBulkFetch = $result ? $result['setting_value'] : 'Never';
    echo "  Last bulk fetch: $lastBulkFetch\n";
    
    $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM cached_reservations");
    $count = $stmt->fetch(PDO::FETCH_ASSOC)['cnt'];
    echo "  Cached reservations: $count\n\n";
    
    if ($count > 0) {
        $stmt = $pdo->query("SELECT room_number, surname, check_in, check_out FROM cached_reservations ORDER BY room_number LIMIT 10");
        $cached = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "  ┌────────────┬────────────────┬────────────┬────────────┐\n";
        echo "  │ Room       │ Surname        │ Check-in   │ Check-out  │\n";
        echo "  ├────────────┼────────────────┼────────────┼────────────┤\n";
        foreach ($cached as $row) {
            printf("  │ %-10s │ %-14s │ %-10s │ %-10s │\n", 
                $row['room_number'], 
                substr($row['surname'], 0, 14),
                $row['check_in'],
                $row['check_out']
            );
        }
        echo "  └────────────┴────────────────┴────────────┴────────────┘\n";
        
        if ($count > 10) {
            echo "  ... and " . ($count - 10) . " more\n";
        }
    }
} catch (PDOException $e) {
    echo "  ✗ Error: " . $e->getMessage() . "\n";
}

echo "\n";

// ============================================================================
// STEP 10: Test with a cached guest (if any exist)
// ============================================================================
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "STEP 10: Testing with cached guest (cache HIT test)...\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

$stmt = $pdo->query("SELECT room_id, room_number, surname FROM cached_reservations LIMIT 1");
$cachedGuest = $stmt->fetch(PDO::FETCH_ASSOC);

if ($cachedGuest) {
    echo "  Testing with cached guest: Room={$cachedGuest['room_number']}, Surname={$cachedGuest['surname']}\n";
    echo "  (This should be a cache HIT - no API calls)\n\n";
    
    $startTime = microtime(true);
    $result = $mews_auth->validateGuest($cachedGuest['room_id'], $cachedGuest['room_number'], $cachedGuest['surname']);
    $duration = round((microtime(true) - $startTime) * 1000);
    
    if ($result) {
        echo "  ✓ Guest validated in {$duration}ms\n";
        echo "    From cache: " . (isset($result['from_cache']) && $result['from_cache'] ? "YES ← Cache HIT!" : "NO") . "\n";
    } else {
        echo "  ✗ Unexpected: cached guest not found\n";
    }
} else {
    echo "  No cached guests available for cache HIT test\n";
}

echo "\n";
echo "╔══════════════════════════════════════════════════════════════════╗\n";
echo "║                        TEST COMPLETE                            ║\n";
echo "╚══════════════════════════════════════════════════════════════════╝\n";
echo "\n";
echo "TIPS:\n";
echo "  • Run this script again immediately to see cache HITs\n";
echo "  • Wait 1+ hour and run again to see bulk fetch trigger\n";
echo "  • Check logs/auth_debug.log for detailed API call logging\n";
echo "  • Clear cache: DELETE FROM cached_reservations;\n";
echo "  • Reset bulk fetch: UPDATE system_settings SET setting_value='1970-01-01' WHERE setting_key='last_bulk_fetch';\n";
